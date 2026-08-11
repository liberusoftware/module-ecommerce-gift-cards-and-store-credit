<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\DisableAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\RedeemByCode;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\RedemptionInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\RefusalReason;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardRedeemed;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\RedemptionFailed;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerConflict;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\RedemptionRefused;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Support\Code;

/**
 * **The bearer path**, which is the only place a code is accepted and the only
 * place a balance is debited.
 *
 * The controlling idea of this file is that a bearer learns **nothing** from a
 * refusal. Not from the message, not from the exception class, and not from the
 * clock. Everything else here — partial redemption, the remainder, currency — is
 * ordinary domain behaviour.
 */
it('spends part of a card and leaves the remainder on it', function () {
    Event::fake([GiftCardRedeemed::class]);

    $card = issueCard(5000);
    $result = redeem((string) $card->code, 3000);

    expect($result->recorded)->toBeTrue()
        ->and($result->entry->kind)->toBe(EntryKind::Redeemed)
        ->and($result->entry->amount->minor)->toBe(3000)
        // **The remainder decision.** The card keeps its balance; no second card
        // is minted, because that would mean a second code and a second thing to
        // lose.
        ->and($result->account->state->balance()->minor)->toBe(2000)
        ->and($result->account->reference)->toBe($card->account->reference);

    Event::assertDispatched(GiftCardRedeemed::class);
});

it('spends the rest of it later, from the same card', function () {
    $card = issueCard(5000);

    redeem((string) $card->code, 3000, 'first');
    $second = redeem((string) $card->code, 2000, 'second');

    expect($second->account->state->balance()->minor)->toBe(0)
        ->and($second->account->state->status()->value)->toBe('empty');
});

it('takes a code however a customer typed it', function () {
    $card = issueCard(1000);

    $typed = strtolower(str_replace('-', ' ', (string) $card->code));

    expect(redeem($typed, 400)->account->state->balance()->minor)->toBe(600);
});

it('answers every refusal with the same bytes', function () {
    // **The anti-enumeration control, asserted directly.** A bearer told "that
    // card has expired" has learned the code is real; one told "insufficient
    // balance" has learned that and roughly what is on it. So there is one class
    // and one message, and this compares the message of a refusal built from
    // every case of the enum.
    $messages = array_map(
        fn (RefusalReason $reason): string => RedemptionRefused::because($reason)->getMessage(),
        RefusalReason::cases(),
    );

    expect(array_unique($messages))->toHaveCount(1)
        ->and($messages[0])->toBe(RedemptionRefused::MESSAGE)
        // And there is more than one reason, so this is not vacuous.
        ->and(count(RefusalReason::cases()))->toBeGreaterThan(5);
});

it('refuses a guessed code and a real one identically', function (callable $attempt, RefusalReason $expected) {
    // Same class, same message, every time. The reason differs and it is for the
    // operator's log — a surface that shows it to a bearer has undone this.
    $refusal = null;

    try {
        $attempt();
    } catch (RedemptionRefused $caught) {
        $refusal = $caught;
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->getMessage())->toBe(RedemptionRefused::MESSAGE)
        ->and($refusal->reason)->toBe($expected);
})->with([
    'a code nobody ever minted' => [fn () => redeem('0123-4567-89AB-CDEF-GHJK', 100, 'a', throttleKey: 'p1'), RefusalReason::Unknown],
    'not even a code' => [fn () => redeem('hello', 100, 'b', throttleKey: 'p2'), RefusalReason::Unknown],
    'a real card, for more than it is worth' => [fn () => redeem((string) issueCard(500, key: 'i1')->code, 900, 'c', throttleKey: 'p3'), RefusalReason::InsufficientBalance],
    'a real card, in the wrong currency' => [fn () => redeem((string) issueCard(5000, key: 'i2')->code, 900, 'd', currency: 'EUR', throttleKey: 'p4'), RefusalReason::CurrencyMismatch],
    'a real card, past its date' => [fn () => redeem((string) issueCard(5000, expiresAt: '2020-01-01 00:00:00', key: 'i3')->code, 900, 'e', throttleKey: 'p5'), RefusalReason::Expired],
    'nothing at all' => [fn () => redeem((string) issueCard(5000, key: 'i4')->code, 0, 'f', throttleKey: 'p6'), RefusalReason::InvalidAmount],
]);

it('refuses a stopped card, and does not say so', function () {
    $card = issueCard(5000);
    (new DisableAccount())->handle($card->account->reference, 'stop-1', 'reported_stolen', GHOST_OPERATOR);

    $refusal = null;

    try {
        redeem((string) $card->code, 100);
    } catch (RedemptionRefused $caught) {
        $refusal = $caught;
    }

    expect($refusal->reason)->toBe(RefusalReason::Disabled)
        ->and($refusal->getMessage())->toBe(RedemptionRefused::MESSAGE);
});

it('refuses a currency it was not sold in rather than converting it', function () {
    // Wave 6 settled that money is recorded and never converted. Picking a rate,
    // at a moment, on a merchant's behalf is a decision they would find out about
    // at the end of the month.
    $card = issueCard(5000, 'GBP');

    expect(fn () => redeem((string) $card->code, 1000, currency: 'EUR'))
        ->toThrow(RedemptionRefused::class);

    expect($card->account->state->balance()->minor)->toBe(5000);
});

it('refuses more than is on the card rather than clamping it to what is', function () {
    // Debiting what is there and dropping the difference turns a loud failure
    // into a basket that is short by an amount nobody is told about.
    $card = issueCard(500);

    expect(fn () => redeem((string) $card->code, 501))->toThrow(RedemptionRefused::class);

    $account = GiftCardAccount::query()->with('entries')->where('reference', $card->account->reference)->firstOrFail();

    expect($account->state()->balance()->minor)->toBe(500)
        // Nothing was written. A refused redemption is not a ledger row.
        ->and($account->entries)->toHaveCount(1);
});

it('leaves an expired card worth exactly what it was worth', function () {
    // **The expiry decision on the redemption path.** Redeemability ended; the
    // money did not move, and staff and the customer can both still see it.
    $card = issueCard(5000, expiresAt: '2020-01-01 00:00:00');

    expect(fn () => redeem((string) $card->code, 100))->toThrow(RedemptionRefused::class);

    $account = GiftCardAccount::query()->with('entries')->where('reference', $card->account->reference)->firstOrFail();

    expect($account->state()->balance()->minor)->toBe(5000)
        ->and($account->state()->expired)->toBeTrue()
        ->and($account->entries)->toHaveCount(1);
});

it('cannot be reached with store credit, which has no code', function () {
    // Store credit is settled against a customer. There is no credential to
    // present, so this path simply never finds it — and answers the way it
    // answers everything else.
    $granted = grantCredit(5000);

    expect($granted->code)->toBeNull();

    expect(fn () => redeem('0123-4567-89AB-CDEF-GHJK', 100))->toThrow(RedemptionRefused::class);
});

it('tells the merchant what it would not tell the bearer', function () {
    // The counterpart to the constant message. A burst of `unknown` against one
    // throttle key is what an enumeration attempt looks like, and it is the most
    // valuable thing in this module to alert on.
    Event::fake([RedemptionFailed::class]);

    try {
        redeem('0123-4567-89AB-CDEF-GHJK', 100, throttleKey: 'a-guesser');
    } catch (RedemptionRefused) {
        // Expected.
    }

    Event::assertDispatched(RedemptionFailed::class, function (RedemptionFailed $event): bool {
        return $event->reason === RefusalReason::Unknown
            && $event->throttleKey === 'a-guesser'
            && $event->accountReference === null;
    });
});

it('puts no code on the failure event, at the moment somebody would most want one', function () {
    Event::fake([RedemptionFailed::class]);

    $card = issueCard(100);
    $code = (string) $card->code;

    try {
        // A real card, mistyped in the amount rather than the code — which is
        // what most failures are, which is why a log of attempted codes is a log
        // of real codes.
        redeem($code, 900);
    } catch (RedemptionRefused) {
        // Expected.
    }

    Event::assertDispatched(RedemptionFailed::class, function (RedemptionFailed $event) use ($code): bool {
        $dump = (string) json_encode([$event->reason->value, $event->throttleKey, $event->accountReference, $event->sourceReference]);

        return ! str_contains($dump, $code) && ! str_contains($dump, Code::normalise($code));
    });
});

it('stops a presenter who keeps guessing', function () {
    config()->set('gift-cards.redemption.max_attempts', 3);

    $reasons = [];

    foreach (range(1, 5) as $attempt) {
        try {
            redeem('0123-4567-89AB-CDEF-GHJK', 100, 'guess-'.$attempt, throttleKey: 'one-guesser');
        } catch (RedemptionRefused $refusal) {
            $reasons[] = $refusal->reason;
        }
    }

    expect($reasons)->toHaveCount(5)
        ->and($reasons[0])->toBe(RefusalReason::Unknown)
        ->and($reasons[2])->toBe(RefusalReason::Unknown)
        // Throttled from the fourth, and still the same message.
        ->and($reasons[3])->toBe(RefusalReason::Throttled)
        ->and($reasons[4])->toBe(RefusalReason::Throttled);
});

it('does not throttle a customer who mistypes and then gets it right', function () {
    config()->set('gift-cards.redemption.max_attempts', 3);

    $card = issueCard(5000);

    foreach (['x1', 'x2'] as $key) {
        try {
            redeem('0123-4567-89AB-CDEF-GHJK', 100, $key, throttleKey: 'one-customer');
        } catch (RedemptionRefused) {
            // Expected.
        }
    }

    // The right code clears the counter, so their own card does not lock them out.
    expect(redeem((string) $card->code, 100, 'x3', throttleKey: 'one-customer')->recorded)->toBeTrue();

    foreach (['x4', 'x5', 'x6'] as $key) {
        try {
            redeem('0123-4567-89AB-CDEF-GHJK', 100, $key, throttleKey: 'one-customer');
        } catch (RedemptionRefused $refusal) {
            expect($refusal->reason)->toBe(RefusalReason::Unknown);
        }
    }
});

it('refuses to redeem without being told who is presenting', function () {
    // Refused rather than defaulted. A limiter keyed on `''` throttles every
    // customer in the world against one counter, and a limiter skipped is a box
    // somebody has already ticked.
    (new RedeemByCode())->handle(new RedemptionInput(
        code: '0123-4567-89AB-CDEF-GHJK',
        entryKey: 'no-presenter',
        amount: money(100),
        throttleKey: '   ',
    ));
})->throws(InvalidArgumentException::class);

it('cannot be got round by a caller holding a stale balance', function () {
    // **The double-redemption argument.** The guard runs inside the transaction,
    // under the account's row lock, against a state folded from the database —
    // never against whatever the caller loaded.
    //
    // Here the caller's copy is deliberately frozen before the card is spent, so
    // it still believes there is £50 on it. The second redemption is refused
    // anyway, because nothing consults that copy.
    $card = issueCard(5000);

    $stale = GiftCardAccount::query()->with('entries')->where('reference', $card->account->reference)->firstOrFail();

    redeem((string) $card->code, 5000, 'spends-it-all');

    expect($stale->state()->balance()->minor)->toBe(5000);

    expect(fn () => redeem((string) $card->code, 5000, 'tries-again'))
        ->toThrow(RedemptionRefused::class);

    expect($stale->fresh()->state()->balance()->minor)->toBe(0);
});

it('debits once for a retried request, and announces once', function () {
    Event::fake([GiftCardRedeemed::class]);

    $card = issueCard(5000);

    $first = redeem((string) $card->code, 2000, 'the-same-entry-key');
    $second = redeem((string) $card->code, 2000, 'the-same-entry-key');

    expect($first->recorded)->toBeTrue()
        ->and($second->recorded)->toBeFalse()
        ->and($second->entry->id)->toBe($first->entry->id)
        ->and($second->account->state->balance()->minor)->toBe(3000);

    // A replay announces nothing: an event is what a listener turns into an
    // email, and telling a customer twice about one debit is the failure.
    Event::assertDispatchedTimes(GiftCardRedeemed::class, 1);
});

it('refuses one entry key used for two different movements', function () {
    $card = issueCard(5000);

    redeem((string) $card->code, 2000, 'reused-key');
    redeem((string) $card->code, 1000, 'reused-key');
})->throws(LedgerConflict::class);
