<?php

declare(strict_types=1);

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Queries\GiftCardQuery;

it('finds a balance by reference and never by code', function () {
    // **There is no `byCode()` and there never will be.** A query object is where
    // a search box, a filter and an export end up pointing, and every one of
    // those persists its argument into a query string, a log line and a browser
    // history.
    $card = issueCard(5000);

    expect(method_exists(GiftCardQuery::class, 'byCode'))->toBeFalse()
        ->and(get_class_methods(GiftCardQuery::class))->not->toContain('findByCode');

    $found = (new GiftCardQuery())->byReference($card->account->reference);

    expect($found)->toBeInstanceOf(AccountData::class)
        ->and($found->state->balance()->minor)->toBe(5000)
        ->and((new GiftCardQuery())->byReference('GC-NOTHING'))->toBeNull();
});

it('reads the ledger behind a balance, oldest first', function () {
    $card = issueCard(5000);
    redeem((string) $card->code, 1000, 'one');
    redeem((string) $card->code, 500, 'two');

    $ledger = (new GiftCardQuery())->ledgerFor($card->account->reference);

    expect($ledger)->toHaveCount(3)
        ->and($ledger->first()->kind)->toBe(EntryKind::Issued)
        ->and($ledger->last()->amount->minor)->toBe(500)
        ->and((new GiftCardQuery())->ledgerFor('GC-NOTHING'))->toBeEmpty();
});

it('lists everything a customer holds, cards and credit together', function () {
    // To a customer they are one thing: money the merchant owes them.
    issueCard(5000, key: 'card-a');
    grantCredit(1500, key: 'credit-a');

    $held = (new GiftCardQuery())->forCustomer(GHOST_CUSTOMER, GHOST_TEAM);

    expect($held)->toHaveCount(2)
        ->and($held->sum(fn (AccountData $account): int => $account->state->balanceMinor()))->toBe(6500);
});

it('finds the cards worth reminding somebody about', function () {
    // The honest thing to do with an expiry a jurisdiction allows — and much
    // better than the alternative this module refuses to offer, which is quietly
    // zeroing them.
    $soon = issueCard(5000, expiresAt: now()->addDays(10)->toDateTimeString(), key: 'soon');
    issueCard(5000, expiresAt: now()->addYears(2)->toDateTimeString(), key: 'later');
    issueCard(5000, key: 'never');
    // Already gone, so there is nothing to remind anybody about.
    issueCard(5000, expiresAt: '2020-01-01 00:00:00', key: 'gone');
    // Expiring soon but already spent, so likewise.
    $spent = issueCard(1000, expiresAt: now()->addDays(5)->toDateTimeString(), key: 'spent');
    redeem((string) $spent->code, 1000, 'spends-it');

    $expiring = (new GiftCardQuery())->expiringBefore(now()->addDays(30), GHOST_TEAM);

    expect($expiring)->toHaveCount(1)
        ->and($expiring->first()->reference)->toBe($soon->account->reference);
});

it('queues the balances that say something arithmetically impossible', function () {
    // Not reachable through anything this module writes — every debit is guarded
    // under the account's row lock. A raw write, an import or a restore can
    // produce it, and this is the queue rather than an exception.
    $healthy = issueCard(5000, key: 'fine');
    $broken = issueCard(1000, key: 'broken');

    GiftCardEntry::factory()
        ->forAccount(GiftCardAccount::query()->where('reference', $broken->account->reference)->firstOrFail())
        ->of(EntryKind::Redeemed, 4000)
        ->create();

    $queue = (new GiftCardQuery())->needingReconciliation(GHOST_TEAM);

    expect($queue)->toHaveCount(1)
        ->and($queue->first()->reference)->toBe($broken->account->reference)
        ->and($queue->first()->state->balance()->minor)->toBe(-3000)
        ->and((new GiftCardQuery())->byReference($healthy->account->reference)->state->needsReconciliation())->toBeFalse();
});

it('scopes every read to one team, and shows an orphan to nobody', function () {
    issueCard(5000, key: 'ours', teamId: GHOST_TEAM);
    issueCard(5000, key: 'theirs', teamId: GHOST_OTHER_TEAM);
    issueCard(5000, key: 'nobodys', teamId: null);

    expect((new GiftCardQuery())->forCustomer(GHOST_CUSTOMER, GHOST_TEAM))->toHaveCount(1)
        ->and((new GiftCardQuery())->forCustomer(GHOST_CUSTOMER, GHOST_OTHER_TEAM))->toHaveCount(1)
        // `where('col', null)` compiles to `is null`, which would list exactly
        // the orphan a policy denies. The scope refuses the null instead.
        ->and(GiftCardAccount::query()->ofTeam(null)->count())->toBe(0);
});

it('publishes a balance without publishing anything that could open it', function () {
    $card = issueCard(5000);

    $wire = (new GiftCardQuery())->byReference($card->account->reference)->toArray();

    expect($wire)->toHaveKeys(['reference', 'kind', 'masked_code', 'last_four', 'state'])
        ->and($wire)->not->toHaveKey('code')
        ->and($wire)->not->toHaveKey('code_index')
        ->and($wire)->not->toHaveKey('code_hash')
        ->and((string) json_encode($wire))->not->toContain((string) $card->code);
});
