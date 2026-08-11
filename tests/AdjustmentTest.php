<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\DisableAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\RecordAdjustment;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AdjustmentInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountStatus;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardAdjusted;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardDisabled;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\InvalidMoney;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\RedemptionRefused;

/**
 * The two operator paths: correcting a balance by hand, and stopping a card.
 *
 * They exist because the ledger is append-only. There is no way to edit an entry
 * and no way to delete one, so the answer to "that should not have happened" is a
 * **new row** saying so, with a reason code and an actor on it.
 */
function adjust(string $reference, int $minor, string $reason = 'goodwill', string $key = 'adjust-1'): LedgerResult
{
    return (new RecordAdjustment())->handle(new AdjustmentInput(
        accountReference: $reference,
        entryKey: $key,
        amount: money($minor),
        reasonCode: $reason,
        recordedBy: GHOST_OPERATOR,
    ));
}

it('corrects a balance upwards and downwards, and records who and why', function (int $minor, int $expected) {
    Event::fake([GiftCardAdjusted::class]);

    $card = issueCard(5000);
    $result = adjust($card->account->reference, $minor, 'correction');

    expect($result->entry->kind)->toBe(EntryKind::Adjusted)
        ->and($result->entry->amount->minor)->toBe($minor)
        ->and($result->entry->reasonCode)->toBe('correction')
        ->and($result->entry->recordedBy)->toBe(GHOST_OPERATOR)
        ->and($result->account->state->balance()->minor)->toBe($expected);

    Event::assertDispatched(GiftCardAdjusted::class);
})->with([
    'a goodwill top-up' => [1500, 6500],
    'a write-off' => [-1500, 3500],
]);

it('refuses to take a balance below zero, because that is a different mistake', function () {
    // A negative balance is the one condition `needsReconciliation()` exists to
    // report, so putting one there deliberately would poison the queue.
    adjust(issueCard(1000)->account->reference, -1001);
})->throws(InvalidMoney::class);

it('refuses an adjustment of nothing', function () {
    adjust(issueCard(1000)->account->reference, 0);
})->throws(InvalidMoney::class);

it('refuses an adjustment with no reason', function () {
    // A short code is the whole record of why somebody moved a balance by hand,
    // and there is no free-text column in this module to put an explanation in.
    (new RecordAdjustment())->handle(new AdjustmentInput(
        accountReference: issueCard(1000)->account->reference,
        entryKey: 'no-reason',
        amount: money(-100),
        reasonCode: '  ',
    ));
})->throws(InvalidArgumentException::class);

it('lets an operator write off a card they have just stopped', function () {
    // The ordinary end of that story, and the reason `RecordAdjustment` does not
    // share `RecordCredit`'s refusal on a disabled account.
    $card = issueCard(5000);

    (new DisableAccount())->handle($card->account->reference, 'stop-1', 'reported_stolen', GHOST_OPERATOR);

    $written = adjust($card->account->reference, -5000, 'writeoff');

    expect($written->account->state->balance()->minor)->toBe(0)
        ->and($written->account->state->status())->toBe(AccountStatus::Disabled);
});

it('stops a card with a ledger entry rather than a column', function () {
    Event::fake([GiftCardDisabled::class]);

    $card = issueCard(5000);
    $result = (new DisableAccount())->handle($card->account->reference, 'stop-1', 'reported_lost', GHOST_OPERATOR);

    expect($result->entry->kind)->toBe(EntryKind::Disabled)
        ->and($result->entry->amount->minor)->toBe(0)
        ->and($result->entry->reasonCode)->toBe('reported_lost')
        ->and($result->account->state->disabled)->toBeTrue()
        ->and($result->account->state->status())->toBe(AccountStatus::Disabled)
        // The money is still there and still reported. Stopping a card is not
        // spending it.
        ->and($result->account->state->balance()->minor)->toBe(5000);

    expect(fn () => redeem((string) $card->code, 100))->toThrow(RedemptionRefused::class);

    Event::assertDispatched(GiftCardDisabled::class);
});

it('lets a card be stopped twice, because a flag set twice is set', function () {
    // Refusing would be a guard that buys nothing and one more branch to get
    // wrong. The fold gives the same answer either way, which is the point of
    // every contribution being a sum or a flag.
    $card = issueCard(5000);

    (new DisableAccount())->handle($card->account->reference, 'stop-1', 'reported_lost');
    $second = (new DisableAccount())->handle($card->account->reference, 'stop-2', 'reported_stolen');

    expect($second->account->state->disabled)->toBeTrue()
        ->and($second->account->state->balance()->minor)->toBe(5000);
});

it('has no way to un-stop a card, so the balance moves to a replacement instead', function () {
    // `EntryKind` has no `Enabled` case and cannot have one: disable-then-enable
    // and enable-then-disable are different facts, and a fold able to tell them
    // apart is a fold that depends on order. The recovery is the runbook's.
    expect(array_map(fn ($case): string => $case->value, EntryKind::cases()))
        ->not->toContain('enabled')
        ->not->toContain('restored');

    $stopped = issueCard(5000, key: 'the-wrong-one');
    (new DisableAccount())->handle($stopped->account->reference, 'oops', 'issued_in_error');

    // Off the stopped card…
    $emptied = adjust($stopped->account->reference, -5000, 'transferred_out', 'move-out');

    // …and onto a new one, with a code the customer can actually use — which is
    // what they needed anyway, since in every case that is not a mistake the old
    // code is in somebody else's hands.
    $replacement = issueCard(5000, key: 'the-replacement');

    expect($emptied->account->state->balance()->minor)->toBe(0)
        ->and($emptied->account->state->disabled)->toBeTrue()
        ->and($replacement->code)->not->toBeNull();

    // And the replacement spends, which the stopped one never will again.
    expect(redeem((string) $replacement->code, 5000, 'spends-the-new-one')->recorded)->toBeTrue();
});
