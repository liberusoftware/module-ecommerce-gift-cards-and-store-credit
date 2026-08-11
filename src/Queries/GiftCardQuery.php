<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Queries;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerEntryData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;

/**
 * The reads a surface performs, returning read models rather than Eloquent.
 *
 * Every method eager-loads `entries`, because every one of them folds — an
 * account that does not know its own ledger costs a query per row, and the fold
 * is the only way this module answers what anything is worth.
 *
 * **There is no `byCode()` here, and there never will be.** A query object is
 * where a search box, a filter and an export end up pointing, and every one of
 * those persists its argument into a query string, a log line and a browser
 * history. The only path that accepts a code is `RedeemByCode`, which is rate
 * limited, uniform in cost and identical in every refusal. Lookup by code exists
 * to spend a card, not to find one.
 */
final class GiftCardQuery
{
    /** One balance by its public reference. Never by id, and never by code. */
    public function byReference(string $reference): ?AccountData
    {
        $account = GiftCardAccount::query()->with('entries')->where('reference', $reference)->first();

        return $account === null ? null : AccountData::from($account);
    }

    /**
     * The ledger behind one balance, oldest first.
     *
     * Separate from `byReference()` because most callers want the balance and not
     * the rows, and building forty read models to answer "what is this worth" is
     * work nobody asked for.
     *
     * @return Collection<int, LedgerEntryData>
     */
    public function ledgerFor(string $reference): Collection
    {
        $account = GiftCardAccount::query()->with('entries')->where('reference', $reference)->first();

        return $account === null
            ? new Collection()
            : $account->entries->map(fn ($entry): LedgerEntryData => LedgerEntryData::from($entry));
    }

    /**
     * Everything a customer holds — gift cards registered to them and their store
     * credit, in one list, because to a customer they are one thing.
     *
     * @return Collection<int, AccountData>
     */
    public function forCustomer(int $customerId, ?int $teamId = null): Collection
    {
        return GiftCardAccount::query()
            ->with('entries')
            ->forCustomer($customerId)
            ->when($teamId !== null, fn ($query) => $query->ofTeam($teamId))
            ->get()
            ->map(fn (GiftCardAccount $account): AccountData => AccountData::from($account));
    }

    /**
     * Balances that still have money on them and are about to stop being
     * redeemable.
     *
     * The read a "your card expires next month" reminder is built on — which is
     * the honest thing to do with an expiry a jurisdiction allows, and much
     * better than the alternative this module refuses to offer, which is quietly
     * zeroing them.
     *
     * @return Collection<int, AccountData>
     */
    public function expiringBefore(DateTimeInterface $moment, ?int $teamId = null): Collection
    {
        return GiftCardAccount::query()
            ->with('entries')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $moment)
            ->where('expires_at', '>', now())
            ->when($teamId !== null, fn ($query) => $query->ofTeam($teamId))
            ->get()
            ->map(fn (GiftCardAccount $account): AccountData => AccountData::from($account))
            ->filter(fn (AccountData $account): bool => $account->state->balance()->isPositive())
            ->values();
    }

    /**
     * Balances whose ledger says something arithmetically impossible.
     *
     * Not reachable through anything this module writes: every debit is guarded
     * against a state folded from the database under the account's row lock. A
     * row here means a raw `DB::table()` write, an import, a restore or a second
     * writer — and it is a **work queue** rather than an error condition.
     * `docs/runbook.md` says what to do with each case.
     *
     * The fold happens in PHP rather than in SQL. That is a deliberate ceiling:
     * the arithmetic lives in exactly one place, `AccountState`, and a second copy
     * of it written as a `HAVING` clause is a second answer waiting to disagree
     * with the first.
     *
     * ponytail: scans the accounts matching the filters and folds each. Fine for
     * an operational queue over one team; if a deployment ever needs it across
     * millions of rows, the upgrade is a materialised `needs_reconciliation` flag
     * written by the same code that appends the entry — not a second
     * implementation of the fold in SQL.
     *
     * @return Collection<int, AccountData>
     */
    public function needingReconciliation(?int $teamId = null): Collection
    {
        return GiftCardAccount::query()
            ->with('entries')
            ->when($teamId !== null, fn ($query) => $query->ofTeam($teamId))
            ->get()
            ->map(fn (GiftCardAccount $account): AccountData => AccountData::from($account))
            ->filter(fn (AccountData $account): bool => $account->state->needsReconciliation())
            ->values();
    }
}
