<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\IssueAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\IssueInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountStatus;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardIssued;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\InvalidMoney;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerConflict;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Support\Code;

it('sells a card and hands the code back exactly once', function () {
    Event::fake([GiftCardIssued::class]);

    $issued = issueCard(5000);

    expect($issued->recorded)->toBeTrue()
        ->and($issued->code)->not->toBeNull()
        ->and(Code::isWellFormed(Code::normalise((string) $issued->code)))->toBeTrue()
        ->and($issued->account->state->balance()->minor)->toBe(5000)
        ->and($issued->account->state->status())->toBe(AccountStatus::Active)
        ->and($issued->entry->kind)->toBe(EntryKind::Issued);

    // And there is no way to ask for it again. Not through the read model, not
    // through the query API, not through the model.
    $account = GiftCardAccount::query()->where('reference', $issued->account->reference)->firstOrFail();

    expect($account->toArray())->not->toHaveKey('code')
        ->and(array_keys($issued->account->toArray()))->not->toContain('code')
        ->and(method_exists($account, 'getCodeAttribute'))->toBeFalse();

    Event::assertDispatched(GiftCardIssued::class);
});

it('never puts the code on the event, because a listener is not the caller', function () {
    Event::fake([GiftCardIssued::class]);

    $issued = issueCard();
    $code = (string) $issued->code;

    Event::assertDispatched(GiftCardIssued::class, function (GiftCardIssued $event) use ($code): bool {
        $dump = (string) json_encode([$event->account->toArray(), $event->entry->toArray()]);

        return ! str_contains($dump, $code) && ! str_contains($dump, Code::normalise($code));
    });
});

it('keeps four characters for display and shows the rest as asterisks', function () {
    $issued = issueCard();

    expect($issued->account->lastFour)->toBe(Code::lastFour(Code::normalise((string) $issued->code)))
        ->and($issued->account->maskedCode())->toBe('****************'.$issued->account->lastFour)
        ->and($issued->account->maskedCode())->not->toContain(substr(Code::normalise((string) $issued->code), 0, 8));
});

it('grants store credit with no code at all', function () {
    // **The same ledger with a different issue path**, which is the decision. Four
    // lines of `IssueAccount` differ; the fold, the guards and the ledger do not.
    $granted = grantCredit(2500);

    expect($granted->code)->toBeNull()
        ->and($granted->account->kind)->toBe(AccountKind::StoreCredit)
        ->and($granted->account->lastFour)->toBeNull()
        ->and($granted->account->maskedCode())->toBeNull()
        ->and($granted->account->state->balance()->minor)->toBe(2500)
        ->and($granted->entry->kind)->toBe(EntryKind::Issued);

    $account = GiftCardAccount::query()->where('reference', $granted->account->reference)->firstOrFail();

    expect($account->code_index)->toBeNull()
        ->and($account->code_hash)->toBeNull();
});

it('refuses store credit that belongs to nobody', function () {
    // Store credit is settled because the caller knows who is asking, not because
    // somebody is holding a code. With no customer it could never be spent.
    (new IssueAccount())->handle(new IssueInput(
        kind: AccountKind::StoreCredit,
        issueKey: 'orphan-credit',
        amount: money(1000),
        teamId: GHOST_TEAM,
    ));
})->throws(InvalidArgumentException::class);

it('lets a gift card belong to nobody, because a bearer card need not', function () {
    $issued = issueCard(customerId: null);

    expect($issued->account->customerId)->toBeNull()
        ->and($issued->code)->not->toBeNull();
});

it('refuses to issue for nothing', function (int $minor) {
    (new IssueAccount())->handle(new IssueInput(
        kind: AccountKind::GiftCard,
        issueKey: 'nothing-'.$minor,
        amount: money($minor),
        teamId: GHOST_TEAM,
    ));
})->with([0, -100])->throws(InvalidMoney::class);

it('has no default currency to fall back on', function () {
    // The host's column is `->default('USD')`. Here the currency comes off the
    // `Money`, which requires one, and the column has no default at all.
    $issued = issueCard(3000, 'JPY');

    expect($issued->account->state->currency)->toBe('JPY')
        ->and($issued->account->state->balance()->decimal())->toBe('30.00');

    $inYen = (new IssueAccount())->handle(new IssueInput(
        kind: AccountKind::GiftCard,
        issueKey: 'yen-card',
        amount: money(3000, 'JPY', 0),
        teamId: GHOST_TEAM,
    ));

    expect($inYen->account->state->balance()->decimal())->toBe('3000');
});

it('writes the account and its first entry together or not at all', function () {
    $issued = issueCard(1234);

    $account = GiftCardAccount::query()->with('entries')->where('reference', $issued->account->reference)->firstOrFail();

    expect($account->entries)->toHaveCount(1)
        ->and($account->entries->first()->kind)->toBe(EntryKind::Issued)
        ->and($account->entries->first()->amount_minor)->toBe(1234)
        // Derived rather than reused, so the account index and the entry index
        // stay independent.
        ->and($account->entries->first()->entry_key)->toBe($account->issue_key.':issued');
});

it('mints one card for a double-clicked button, and refuses to say the code again', function () {
    Event::fake([GiftCardIssued::class]);

    $first = issueCard(5000, key: 'the-same-issue-key');
    $second = issueCard(5000, key: 'the-same-issue-key');

    expect($first->recorded)->toBeTrue()
        ->and($second->recorded)->toBeFalse()
        ->and($second->account->reference)->toBe($first->account->reference)
        // **Null on a replay, and not as a policy** — this module could not
        // produce it. A retry that needs the code is a caller that dropped it.
        ->and($second->code)->toBeNull()
        ->and(GiftCardAccount::query()->count())->toBe(1);

    // Announced once. A listener that emails a card must not run twice.
    Event::assertDispatchedTimes(GiftCardIssued::class, 1);
});

it('refuses the same issue key used for different facts', function () {
    issueCard(5000, key: 'reused');
    issueCard(9999, key: 'reused');
})->throws(LedgerConflict::class);

it('records what caused the card to exist without knowing what that is', function () {
    $issued = issueCard();

    expect($issued->account->sourceReference)->toBe(GHOST_ORDER_REFERENCE)
        ->and($issued->entry->sourceReference)->toBe(GHOST_ORDER_REFERENCE);
});
