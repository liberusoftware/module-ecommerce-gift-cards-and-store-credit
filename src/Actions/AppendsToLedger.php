<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountState;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerEntryData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerConflict;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerInFlight;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\UnknownAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;

/**
 * What redeem, credit, adjust and disable all do, with the guard left to each.
 *
 * One shape rather than four copies. The copies would drift, and the place they
 * would drift is the idempotency handling — which is exactly how a fleet ends up
 * with one module answering `423` where its neighbour answers `409`.
 *
 * ## The lock, the fold, the guard, the append — in that order
 *
 * ```
 * DB::transaction(function () {
 *     $account = …->lockForUpdate()->findOrFail($id);   // 1. lock the row
 *     $state   = $account->state();                     // 2. fold under the lock
 *     $row     = $build($account, $state);              // 3. guard, or throw
 *     return $account->entries()->create($row);         // 4. append
 * });
 * ```
 *
 * **That ordering is the whole double-redemption argument.** Two tills racing for
 * the last £20 on one card both pass a check made against what the caller loaded
 * a moment ago; exactly one passes a check made against the database, under a
 * row lock, on a state folded from the ledger inside the same transaction that
 * writes the debit. A test drives it with a deliberately stale in-memory account
 * and proves the stale copy cannot get round it.
 *
 * The lock is held for the length of a fold and an insert — microseconds, and no
 * network call inside it. Payment Operations names holding a lock across a
 * provider's network call as its ceiling; this module has no provider to call, so
 * that ceiling does not exist here. Concurrent movements against **one** card
 * serialise; movements against different cards do not touch each other.
 *
 * ## Two exception classes, from day one
 *
 * `LedgerConflict` is permanent (`409`, never retry); `LedgerInFlight` is
 * transient (`423`, retry shortly). They are opposite instructions and a caller
 * must not have to read a message to tell them apart.
 *
 * ## What honestly is not proved here
 *
 * The unique index on `entry_key` is the guarantee that two workers processing
 * one retry write one row. That is a property of the index, and this package's
 * suite runs on SQLite `:memory:` inside a `RefreshDatabase` transaction on **one
 * connection** — so a genuine concurrent race is never exercised. `LedgerTest`
 * says so in its own header and proves the three things it actually can: that the
 * index is declared, that the loser's recovery path executes, and that the guard
 * reads from the database rather than from memory.
 */
trait AppendsToLedger
{
    /**
     * Append one entry to an account's ledger, idempotently.
     *
     * `$build` receives the account and its state **as folded under the lock**,
     * and returns the entry's attributes. Each action's invariant lives inside
     * that closure and throws from there, which is what makes the guard mean
     * something: it is evaluated against the database, not against the caller.
     *
     * @param  Closure(GiftCardAccount, AccountState): array<string, mixed>  $build
     */
    protected function appendEntry(GiftCardAccount $account, string $entryKey, string $hash, Closure $build): LedgerResult
    {
        $existing = GiftCardEntry::query()->where('entry_key', $entryKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $hash, $entryKey);
        }

        $accountId = $account->id;

        try {
            $entry = DB::transaction(function () use ($accountId, $entryKey, $hash, $build): GiftCardEntry {
                $locked = GiftCardAccount::query()->lockForUpdate()->findOrFail($accountId);

                // Folded here, under the lock, from the database. Never from
                // whatever the caller was holding.
                $attributes = $build($locked, $locked->state());

                return $locked->entries()->create([
                    ...$attributes,
                    'entry_key' => $entryKey,
                    'entry_hash' => $hash,
                    'team_id' => $attributes['team_id'] ?? $locked->team_id,
                    'occurred_at' => $attributes['occurred_at'] ?? now(),
                ]);
            });
        } catch (QueryException $exception) {
            $winner = GiftCardEntry::query()->where('entry_key', $entryKey)->first();

            if ($winner === null) {
                // The index refused us and there is still no row, which means the
                // winner's transaction has not committed. Transient: retry.
                if ($this->isUniqueViolation($exception)) {
                    throw LedgerInFlight::entry($entryKey);
                }

                throw $exception;
            }

            return $this->replay($winner, $hash, $entryKey);
        }

        return $this->result($entry, recorded: true);
    }

    /** Resolve an account by its public reference, or refuse by name. */
    protected function accountByReference(string $reference): GiftCardAccount
    {
        $account = GiftCardAccount::query()->where('reference', $reference)->first();

        if ($account === null) {
            throw UnknownAccount::reference($reference);
        }

        return $account;
    }

    /**
     * The stored payload hash for an idempotency key.
     *
     * **No code material goes in here, ever.** `entry_hash` is a column, and a
     * hash of a code plus known facts is code material in a column. What is
     * hashed is the account, the amount and the references — which is what "the
     * same movement" means. How the caller found the card is not part of what
     * happened.
     *
     * @param  array<string, mixed>  $facts
     */
    protected function hashOf(array $facts): string
    {
        ksort($facts);

        return hash('sha256', (string) json_encode($facts, JSON_THROW_ON_ERROR));
    }

    /**
     * Whether this driver is telling us a unique index refused the row.
     *
     * SQLSTATE `23000` and `23505` are the integrity-violation classes across
     * MySQL, Postgres and SQLite. Matched on the code rather than the message,
     * because messages are localised and vendor-specific and the code is not.
     */
    protected function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }

    /**
     * Whether a replay is the same facts or different ones.
     *
     * `hash_equals` rather than `===` out of habit rather than necessity — both
     * are our own hashes — but the habit is the point: a timing-safe comparison
     * where a payload hash is checked is one fewer thing to argue about in review.
     */
    protected function sameFacts(string $stored, string $given): bool
    {
        return hash_equals($stored, $given);
    }

    private function replay(GiftCardEntry $entry, string $hash, string $entryKey): LedgerResult
    {
        if (! $this->sameFacts($entry->entry_hash, $hash)) {
            throw LedgerConflict::entry($entryKey);
        }

        return $this->result($entry, recorded: false);
    }

    /**
     * One place that turns a written entry into a result, so a replay and a fresh
     * write cannot answer differently about the same row.
     *
     * The account is **re-read with its ledger** because the state travels on the
     * read model, and a model held from before the append would report the
     * balance as it was a moment ago — which for a redemption is the number the
     * caller is about to show somebody.
     */
    private function result(GiftCardEntry $entry, bool $recorded): LedgerResult
    {
        $account = GiftCardAccount::query()->with('entries')->findOrFail($entry->account_id);

        return new LedgerResult(AccountData::from($account), LedgerEntryData::from($entry), $recorded);
    }
}
