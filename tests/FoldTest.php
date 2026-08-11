<?php

declare(strict_types=1);

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountState;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountStatus;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;

/**
 * **The proof that the derivation is total.**
 *
 * This module has no balance column. Everything it reports about a gift card
 * comes out of `AccountState::fold()`, so "the fold is total" is not a nicety —
 * it is the claim the whole design rests on, and on a gift card the failure mode
 * of a partial one is money that quietly stops existing. It is made in three
 * parts here, each mechanical rather than a promise.
 *
 * 1. **Every entry kind is handled.** The fold's `match` has no `default` arm, so
 *    an unhandled kind raises `UnhandledMatchError`. A test folds a ledger
 *    containing every case of `EntryKind::cases()`, so adding a case without
 *    handling it fails the build rather than contributing a silent zero.
 * 2. **Order cannot change the answer.** Every contribution is a sum or a flag,
 *    both commutative, so folding a set of entries in any order gives a
 *    bit-identical state. Proved by folding every permutation.
 * 3. **Every sequence has exactly one status, and producing it never throws.**
 *    Proved by enumerating every sequence of kinds up to length three — 156 of
 *    them, the empty ledger included — against **both** expiry values, and every
 *    branch of the cascade by hand.
 */
it('handles every entry kind there is', function () {
    // The mechanical guard. `EntryKind::cases()` is the source of truth, so a
    // case added tomorrow without an arm in the fold fails here rather than
    // silently subtracting itself from somebody's balance.
    $rows = array_map(fn (EntryKind $kind): array => [$kind, 100], EntryKind::cases());

    $state = AccountState::fold('GBP', 2, false, ledger($rows));

    expect($state->status())->toBeInstanceOf(AccountStatus::class);
});

it('gives exactly one answer for every sequence of kinds up to length three', function () {
    $kinds = EntryKind::cases();
    $sequences = [[]];
    $level = [[]];

    foreach ([1, 2, 3] as $ignored) {
        $next = [];

        foreach ($level as $sequence) {
            foreach ($kinds as $kind) {
                $next[] = [...$sequence, $kind];
            }
        }

        $sequences = [...$sequences, ...$next];
        $level = $next;
    }

    // 1 + 5 + 25 + 125 = 156 sequences, the empty one included.
    expect($sequences)->toHaveCount(156);

    foreach ($sequences as $sequence) {
        $rows = array_map(fn (EntryKind $kind): array => [$kind, 100], $sequence);

        // Both expiry values, because expiry is an input to the fold exactly as
        // currency is, and a cascade total for one and not the other is not
        // total.
        foreach ([false, true] as $expired) {
            $status = AccountState::fold('GBP', 2, $expired, ledger($rows))->status();

            expect($status)->toBeInstanceOf(AccountStatus::class)
                ->and(AccountState::fold('GBP', 2, $expired, ledger($rows))->status())->toBe($status);
        }
    }
});

it('gives the same answer whatever order the entries arrive in', function () {
    // **The commutativity proof.** It is why `EntryKind` has no `Enabled` case:
    // disable-then-enable and enable-then-disable are different facts, and a fold
    // able to tell them apart is a fold that depends on order.
    $multisets = [
        [[EntryKind::Issued, 5000], [EntryKind::Redeemed, 3000], [EntryKind::Credited, 1000]],
        [[EntryKind::Issued, 2000], [EntryKind::Adjusted, -500], [EntryKind::Redeemed, 500]],
        [[EntryKind::Issued, 900], [EntryKind::Disabled, 0], [EntryKind::Redeemed, 400]],
        [[EntryKind::Issued, 100], [EntryKind::Credited, 40], [EntryKind::Credited, 60], [EntryKind::Redeemed, 200]],
    ];

    foreach ($multisets as $rows) {
        $expected = AccountState::fold('GBP', 2, false, ledger($rows))->toArray();

        foreach (permutations($rows) as $permutation) {
            expect(AccountState::fold('GBP', 2, false, ledger($permutation))->toArray())->toBe($expected);
        }
    }
});

it('reaches every branch of the status cascade', function (array $rows, bool $expired, AccountStatus $expected) {
    // A cascade whose last arm is unconditional is total by construction; this is
    // the other half — that none of the earlier arms is dead, so the totality is
    // not achieved by the whole thing having collapsed into one answer.
    expect(AccountState::fold('GBP', 2, $expired, ledger($rows))->status())->toBe($expected);
})->with([
    'nothing at all' => [[], false, AccountStatus::Empty],
    'sold, unspent' => [[[EntryKind::Issued, 5000]], false, AccountStatus::Active],
    'spent to the penny' => [[[EntryKind::Issued, 5000], [EntryKind::Redeemed, 5000]], false, AccountStatus::Empty],
    'part spent' => [[[EntryKind::Issued, 5000], [EntryKind::Redeemed, 2000]], false, AccountStatus::Active],
    'past its date, with money on it' => [[[EntryKind::Issued, 5000]], true, AccountStatus::Expired],
    'past its date and empty' => [[[EntryKind::Issued, 5000], [EntryKind::Redeemed, 5000]], true, AccountStatus::Expired],
    'stopped' => [[[EntryKind::Issued, 5000], [EntryKind::Disabled, 0]], false, AccountStatus::Disabled],
    'stopped and past its date, which is still stopped' => [[[EntryKind::Issued, 5000], [EntryKind::Disabled, 0]], true, AccountStatus::Disabled],
    'written off to nothing' => [[[EntryKind::Issued, 5000], [EntryKind::Adjusted, -5000]], false, AccountStatus::Empty],
    'topped up after being spent' => [[[EntryKind::Issued, 1000], [EntryKind::Redeemed, 1000], [EntryKind::Credited, 500]], false, AccountStatus::Active],
]);

it('leaves the money exactly where it was when a card expires', function () {
    // **The expiry decision, asserted.** Many jurisdictions regulate or forbid
    // expiry; this module does not know the law and must not act as though the
    // money were gone. Nothing is zeroed, no entry is written, and the two folds
    // below differ in precisely one field.
    $rows = [[EntryKind::Issued, 5000], [EntryKind::Redeemed, 2000]];

    $live = AccountState::fold('GBP', 2, false, ledger($rows));
    $expired = AccountState::fold('GBP', 2, true, ledger($rows));

    expect($expired->balance()->minor)->toBe(3000)
        ->and($expired->balance()->minor)->toBe($live->balance()->minor)
        ->and($expired->status())->toBe(AccountStatus::Expired)
        ->and($live->status())->toBe(AccountStatus::Active)
        // Redeemability is the only thing that changed.
        ->and($expired->isRedeemable())->toBeFalse()
        ->and($live->isRedeemable())->toBeTrue();
});

it('sums a partial redemption and leaves the remainder on the card', function () {
    // Partial redemption, and the decision that goes with it: the card keeps its
    // balance. No second card is minted for the remainder, because that would
    // mean a second code, a second credential and a second thing to lose.
    $state = AccountState::fold('GBP', 2, false, ledger([
        [EntryKind::Issued, 5000],
        [EntryKind::Redeemed, 1000],
        [EntryKind::Redeemed, 2000],
    ]));

    expect($state->issuedMinor)->toBe(5000)
        ->and($state->redeemedMinor)->toBe(3000)
        ->and($state->balance()->minor)->toBe(2000)
        ->and($state->balance()->decimal())->toBe('20.00')
        ->and($state->status())->toBe(AccountStatus::Active)
        ->and($state->needsReconciliation())->toBeFalse();
});

it('counts a disable towards nothing, because stopping a card moves no money', function () {
    $without = AccountState::fold('GBP', 2, false, ledger([[EntryKind::Issued, 5000]]));
    $with = AccountState::fold('GBP', 2, false, ledger([[EntryKind::Issued, 5000], [EntryKind::Disabled, 0]]));

    expect($with->balanceMinor())->toBe($without->balanceMinor())
        ->and($with->disabled)->toBeTrue()
        ->and($without->disabled)->toBeFalse();
});

it('adds a signed adjustment in both directions', function (int $adjustment, int $expected) {
    $state = AccountState::fold('GBP', 2, false, ledger([
        [EntryKind::Issued, 5000],
        [EntryKind::Adjusted, $adjustment],
    ]));

    expect($state->balance()->minor)->toBe($expected)
        ->and($state->adjustedMinor)->toBe($adjustment);
})->with([
    'a goodwill top-up' => [1500, 6500],
    'a write-off' => [-1500, 3500],
]);

it('reports an impossible ledger rather than clamping it into something tidy', function () {
    // Not reachable through anything this module writes — every debit is guarded
    // under the account's row lock. A raw `DB::table()` write, an import or a
    // restore can produce it, and a wrong number nobody is told about is the
    // worst outcome available to a module that holds somebody's money.
    $state = AccountState::fold('GBP', 2, false, ledger([
        [EntryKind::Issued, 1000],
        [EntryKind::Redeemed, 1500],
    ]));

    expect($state->balance()->minor)->toBe(-500)
        ->and($state->needsReconciliation())->toBeTrue()
        ->and($state->status())->toBe(AccountStatus::Empty);
});

it('excludes an entry in another currency instead of adding unlike units', function () {
    // The write path refuses one. This is what happens if a row exists anyway,
    // and it is the only shape in which this module will not add two numbers
    // together.
    $entries = [
        ...ledger([[EntryKind::Issued, 5000]]),
        ...ledger([[EntryKind::Redeemed, 4000]], currency: 'EUR'),
    ];

    $state = AccountState::fold('GBP', 2, false, $entries);

    expect($state->redeemedMinor)->toBe(0)
        ->and($state->balance()->minor)->toBe(5000)
        ->and($state->mismatchedCurrencyEntries)->toBe(1)
        ->and($state->needsReconciliation())->toBeTrue();
});

it('publishes a wire shape a surface can render without knowing the exponent table', function () {
    $state = AccountState::fold('JPY', 0, false, ledger([[EntryKind::Issued, 3000]], currency: 'JPY'));

    expect($state->toArray())->toMatchArray([
        'status' => 'active',
        'expired' => false,
        'disabled' => false,
        'redeemable' => true,
        'needs_reconciliation' => false,
    ])
        ->and($state->toArray()['balance'])->toBe(['minor' => 3000, 'currency' => 'JPY', 'exponent' => 0, 'decimal' => '3000'])
        ->and(json_decode((string) json_encode($state), true)['balance']['decimal'])->toBe('3000');
});

it('reports what was funded separately from what is left', function () {
    $state = AccountState::fold('GBP', 2, false, ledger([
        [EntryKind::Issued, 5000],
        [EntryKind::Credited, 1000],
        [EntryKind::Redeemed, 4000],
    ]));

    expect($state->fundedMinor())->toBe(6000)
        ->and($state->balanceMinor())->toBe(2000);
});
