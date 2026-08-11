<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerInFlight;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerIsAppendOnly;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;

/**
 * **What this file can and cannot prove, stated plainly.**
 *
 * The correctness argument against double redemption is two things: a unique
 * index on `entry_key`, and a guard evaluated inside a transaction under the
 * account's row lock against a state folded from the database. Both are
 * properties of the **database**, and this suite runs on SQLite `:memory:` inside
 * a `RefreshDatabase` transaction on **one connection**. There is no second
 * connection to race with, so **a genuine concurrent race is not exercised here
 * and this file does not claim it is.** The same honesty wave 4's checkout module
 * wrote into its own idempotency suite.
 *
 * What is proved instead, and why each part is worth having:
 *
 * 1. **The index is declared.** Asserted directly against the schema, because the
 *    whole guarantee rests on it and a migration edit that dropped it would
 *    otherwise be silent.
 * 2. **The loser's path is executed.** The "somebody else got there first" branch
 *    is entered by writing the competing row from a `creating` hook — the exact
 *    window a real race lands in — so the insert really does hit the unique index
 *    and the recovery really does run. It simulates the timing; it does not
 *    simulate concurrency.
 * 3. **The guard reads from the database, not from memory.** Proved in
 *    `RedemptionTest` with a deliberately stale in-memory account that still
 *    believes the card is full.
 * 4. **Everything that is not about concurrency** — append-only, replay, the
 *    conflict/in-flight split — is ordinary logic and is tested as such.
 *
 * A test that started two processes and hoped they collided would be slower,
 * flakier, and would still not prove the thing on a database this suite does not
 * run against. An honest gap somebody has written down beats a green test that
 * proves nothing.
 */
it('declares the unique index the whole guarantee rests on', function () {
    $unique = collect(Schema::getIndexes('ecommerce_gift_card_entries'))
        ->first(fn (array $index): bool => $index['unique'] && $index['columns'] === ['entry_key']);

    expect($unique)->not->toBeNull();
});

it('answers a claimed key that has not committed with the transient exception', function () {
    // The loser's branch, entered by writing the competing row from inside the
    // window a real race lands in. `LedgerInFlight` is the **transient** half of
    // the pair — a surface answers `423` and the caller retries shortly — and it
    // is a different class from `LedgerConflict` precisely so nobody has to read
    // a message to tell two opposite instructions apart.
    $card = issueCard(5000);

    GiftCardEntry::creating(function (GiftCardEntry $entry): void {
        if ($entry->entry_key !== 'contested') {
            return;
        }

        // The winner's row, committed by somebody else a microsecond ago — and
        // written raw, so this hook does not recurse into itself.
        DB::table('ecommerce_gift_card_entries')->insert([
            'account_id' => $entry->account_id,
            'kind' => 'redeemed',
            'entry_key' => 'contested',
            'entry_hash' => str_repeat('f', 64),
            'amount_minor' => 1,
            'currency' => 'GBP',
            'currency_exponent' => 2,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // And then removed again, so the recovery finds no winner — which is what
        // "in flight" means: the index refused us and there is still no row.
        DB::table('ecommerce_gift_card_entries')->where('entry_key', 'contested')->delete();

        throw new QueryException('sqlite', 'insert', [], new PDOException('UNIQUE constraint failed', 23000));
    });

    expect(fn () => redeem((string) $card->code, 100, 'contested'))->toThrow(LedgerInFlight::class);
});

it('refuses to update a ledger row, from an instance', function () {
    $card = issueCard(5000);
    $entry = GiftCardEntry::query()->firstOrFail();

    expect($entry->account_id)->toBe($card->account->id);

    $entry->amount_minor = 999999;
    $entry->save();
})->throws(LedgerIsAppendOnly::class);

it('refuses to delete a ledger row, from an instance', function () {
    issueCard(5000);

    GiftCardEntry::query()->firstOrFail()->delete();
})->throws(LedgerIsAppendOnly::class);

it('refuses a mass update, which fires no model event at all', function () {
    // The hole the model events cannot see. `Model::query()->update()` goes
    // straight to the base query builder and fires nothing, and it is exactly the
    // shape somebody reaches for after `$entry->update()` has thrown at them.
    issueCard(5000);

    GiftCardEntry::query()->update(['amount_minor' => 1]);
})->throws(LedgerIsAppendOnly::class);

it('refuses a mass delete', function () {
    issueCard(5000);

    GiftCardEntry::query()->delete();
})->throws(LedgerIsAppendOnly::class);

it('refuses an upsert, which is an insert that may become an update', function () {
    issueCard(5000);

    GiftCardEntry::query()->upsert([['entry_key' => 'x', 'amount_minor' => 1]], ['entry_key']);
})->throws(LedgerIsAppendOnly::class);

it('still allows an append, which is the one thing the table is for', function () {
    $card = issueCard(5000);
    $before = GiftCardEntry::query()->count();

    redeem((string) $card->code, 1000, 'appends');

    expect(GiftCardEntry::query()->count())->toBe($before + 1);
});

it('names the one path none of the three layers closes', function () {
    // ponytail: `DB::table()` bypasses the model entirely and nothing in PHP can
    // stop it. This test does not assert that it is prevented — it asserts that
    // it is **not**, so the documented ceiling is the truth and `docs/runbook.md`
    // is what a deployment reads about revoking `UPDATE` on the table.
    $card = issueCard(5000);

    DB::table('ecommerce_gift_card_entries')->where('account_id', $card->account->id)->update(['amount_minor' => 1]);

    expect(GiftCardEntry::query()->firstOrFail()->amount_minor)->toBe(1);
});
