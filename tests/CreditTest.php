<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\DisableAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\RecordCredit;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\CreditInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\CreditOrigin;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardCredited;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\AccountNotCreditable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\CurrencyMismatch;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\InvalidMoney;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\UnknownAccount;

/**
 * Money going **onto** a balance — a refund, a reversal, a top-up.
 *
 * Addressed by reference and never by a code, which is the decision worth
 * noticing: nobody presents a card in order to have money put on it, and a path
 * that took a code here would be a second place a code could be typed, logged and
 * guessed against.
 */
function credit(string $reference, int $minor, CreditOrigin $origin = CreditOrigin::TopUp, string $key = 'credit-1', string $currency = 'GBP', ?string $source = null): LedgerResult
{
    return (new RecordCredit())->handle(new CreditInput(
        accountReference: $reference,
        entryKey: $key,
        amount: money($minor, $currency),
        origin: $origin,
        sourceReference: $source,
    ));
}

it('records a refund onto a card, decided by somebody else entirely', function () {
    // **Where the two modules of wave 7 meet.** A refunds module decided the
    // amount and the destination; this records it. What crossed is an integer, a
    // currency code and a string.
    Event::fake([GiftCardCredited::class]);

    $card = issueCard(1000);

    $result = credit($card->account->reference, 2500, CreditOrigin::Refund, source: GHOST_REFUND_REFERENCE);

    expect($result->entry->kind)->toBe(EntryKind::Credited)
        ->and($result->entry->origin)->toBe(CreditOrigin::Refund)
        ->and($result->entry->sourceReference)->toBe(GHOST_REFUND_REFERENCE)
        ->and($result->account->state->balance()->minor)->toBe(3500);

    Event::assertDispatched(GiftCardCredited::class);
});

it('puts a redemption back as a new entry rather than deleting one', function () {
    // The other half of the debit-at-redemption decision. If what a redemption
    // paid for falls over, the remedy is a **reversal** carrying the reference of
    // the redemption it undoes — so the pair reads as a pair, and the ledger
    // still says what happened.
    $card = issueCard(5000);

    redeem((string) $card->code, 3000, 'spent');

    $reversed = credit($card->account->reference, 3000, CreditOrigin::Reversal, key: 'put-back', source: GHOST_ORDER_REFERENCE);

    expect($reversed->account->state->balance()->minor)->toBe(5000)
        ->and($reversed->account->state->redeemedMinor)->toBe(3000)
        ->and($reversed->account->state->creditedMinor)->toBe(3000)
        // Three rows: issued, redeemed, credited. Nothing was removed.
        ->and($reversed->entry->origin)->toBe(CreditOrigin::Reversal);
});

it('lands a refund on an expired card, because the money never went anywhere', function () {
    // **The expiry decision on the credit path**, and the place somebody would
    // otherwise be surprised by it. Expiry ends redeemability, not ownership.
    // Refusing the refund, or zeroing the balance, would both be this module
    // deciding a jurisdiction's law.
    $card = issueCard(1000, expiresAt: '2020-01-01 00:00:00');

    $result = credit($card->account->reference, 500, CreditOrigin::Refund, source: GHOST_REFUND_REFERENCE);

    expect($result->account->state->balance()->minor)->toBe(1500)
        ->and($result->account->state->expired)->toBeTrue()
        ->and($result->account->state->isRedeemable())->toBeFalse();
});

it('refuses to put money onto a stopped card, where nobody could spend it', function () {
    $card = issueCard(1000);
    (new DisableAccount())->handle($card->account->reference, 'stop-it', 'reported_stolen');

    credit($card->account->reference, 500, CreditOrigin::Refund);
})->throws(AccountNotCreditable::class);

it('refuses a credit in a currency the card is not denominated in', function () {
    // Named on this path, unlike the bearer path where the same condition has to
    // answer identically to everything else. A listener or an operator is
    // entitled to know; a guesser is not.
    $card = issueCard(1000, 'GBP');

    credit($card->account->reference, 500, CreditOrigin::Refund, currency: 'EUR');
})->throws(CurrencyMismatch::class);

it('refuses a credit of nothing', function (int $minor) {
    credit(issueCard(1000)->account->reference, $minor);
})->with([0, -100])->throws(InvalidMoney::class);

it('names a reference it cannot find, because a reference is not a credential', function () {
    // Safe to name for exactly the reason a reference is not a code: it is
    // already in exports, support tickets and panel URLs, and holding one redeems
    // nothing.
    credit('GC-NOTHING', 100);
})->throws(UnknownAccount::class);

it('credits once for a retried request', function () {
    Event::fake([GiftCardCredited::class]);

    $card = issueCard(1000);

    $first = credit($card->account->reference, 500, CreditOrigin::Refund, key: 'one-refund', source: GHOST_REFUND_REFERENCE);
    $second = credit($card->account->reference, 500, CreditOrigin::Refund, key: 'one-refund', source: GHOST_REFUND_REFERENCE);

    expect($first->recorded)->toBeTrue()
        ->and($second->recorded)->toBeFalse()
        ->and($second->account->state->balance()->minor)->toBe(1500);

    Event::assertDispatchedTimes(GiftCardCredited::class, 1);
});

it('tops up store credit through the same path a gift card uses', function () {
    // The same ledger. Nothing on this path knows or cares which kind it is.
    $granted = grantCredit(1000);

    expect(credit($granted->account->reference, 750, CreditOrigin::TopUp)->account->state->balance()->minor)->toBe(1750);
});
