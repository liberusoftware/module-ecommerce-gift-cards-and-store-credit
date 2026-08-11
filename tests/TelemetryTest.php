<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\DisableAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\RedemptionRefused;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Support\Code;
use Monolog\Handler\NullHandler;

/**
 * A log is the store in an application with the loosest access control and the
 * longest reach, and this is the module that must not put anything in it.
 *
 * The interesting assertions here are the **absences**, and one of them is
 * pointed: nothing is written on the refusal path either, which is exactly where
 * somebody would reach for "let us log what they typed so we can see what went
 * wrong". A log of attempted gift card codes is a log of real gift card codes,
 * because most failures are a customer mistyping a card they are holding.
 */
function captureLog(Closure $work): array
{
    $records = [];

    // A long closure with `use (&$records)`, not an arrow function: PHP arrow
    // functions capture by value at definition, so `fn (): array => $records`
    // would return the empty array it started with. Paid for in a previous wave.
    Log::listen(function (MessageLogged $message) use (&$records): void {
        $records[] = $message;
    });

    $work();

    return $records;
}

it('writes nothing at all until a deployment asks for it', function () {
    // Off by default. A package that starts filling somebody's log the moment it
    // installs has decided their retention bill.
    config()->set('gift-cards.telemetry.enabled', false);

    $records = captureLog(function (): void {
        $card = issueCard(5000);
        redeem((string) $card->code, 1000, 'quiet');
    });

    expect($records)->toBeEmpty();
});

it('records a movement with enough to alert on and nothing else', function () {
    config()->set('gift-cards.telemetry.enabled', true);

    $records = captureLog(function (): void {
        $card = issueCard(5000);
        redeem((string) $card->code, 1000, 'loud');
    });

    $redeemed = collect($records)->first(fn (MessageLogged $message): bool => $message->message === 'gift-card.redeemed');

    expect($redeemed)->not->toBeNull()
        ->and($redeemed->level)->toBe('info')
        ->and($redeemed->context)->toHaveKeys(['account', 'kind', 'team_id', 'entry_kind', 'amount_minor', 'currency', 'balance_minor', 'status'])
        ->and($redeemed->context['amount_minor'])->toBe(1000)
        ->and($redeemed->context['balance_minor'])->toBe(4000);
});

it('never writes a code, in any form, on any path', function () {
    config()->set('gift-cards.telemetry.enabled', true);

    $code = null;

    $records = captureLog(function () use (&$code): void {
        $card = issueCard(5000);
        $code = (string) $card->code;

        redeem($code, 1000, 'ok');

        try {
            // The refusal path, which is where somebody would most want the code.
            redeem($code, 999999, 'too-much');
        } catch (RedemptionRefused) {
            // Expected.
        }

        try {
            redeem('0123-4567-89AB-CDEF-GHJK', 100, 'guessed');
        } catch (RedemptionRefused) {
            // Expected.
        }
    });

    $dump = (string) json_encode(array_map(fn (MessageLogged $message): array => [$message->message, $message->context], $records));

    expect($records)->not->toBeEmpty()
        ->and($dump)->not->toContain((string) $code)
        ->not->toContain(Code::normalise((string) $code))
        // Not even the fragment kept for display: four characters plus a throttle
        // key plus a timestamp is most of a code's identity handed to whoever
        // reads logs, at the moment somebody is trying to guess one.
        ->not->toContain(Code::lastFour(Code::normalise((string) $code)))
        // And never the pepper.
        ->not->toContain(TEST_PEPPER);
});

it('writes the refusal reason the bearer was not told', function () {
    config()->set('gift-cards.telemetry.enabled', true);

    $records = captureLog(function (): void {
        try {
            redeem('0123-4567-89AB-CDEF-GHJK', 100, 'guess', throttleKey: 'a-guesser');
        } catch (RedemptionRefused) {
            // Expected.
        }
    });

    $refused = collect($records)->first(fn (MessageLogged $message): bool => $message->message === 'gift-card.redemption-refused');

    expect($refused)->not->toBeNull()
        ->and($refused->level)->toBe('warning')
        ->and($refused->context['reason'])->toBe('unknown')
        ->and($refused->context['throttle_key'])->toBe('a-guesser')
        ->and($refused->context['account'])->toBeNull();
});

it('raises the level for the things worth waking somebody for', function () {
    config()->set('gift-cards.telemetry.enabled', true);

    $records = captureLog(function (): void {
        $card = issueCard(5000);
        (new DisableAccount())->handle($card->account->reference, 'stop-1', 'reported_stolen');
    });

    $disabled = collect($records)->first(fn (MessageLogged $message): bool => $message->message === 'gift-card.disabled');

    expect($disabled->level)->toBe('error')
        ->and($disabled->context['reason_code'])->toBe('reported_stolen');
});

it('writes to a named channel when a deployment names one', function () {
    config()->set('gift-cards.telemetry.enabled', true);
    config()->set('gift-cards.telemetry.channel', 'stderr');
    config()->set('logging.channels.stderr', ['driver' => 'monolog', 'handler' => NullHandler::class]);

    $records = captureLog(function (): void {
        issueCard(5000);
    });

    expect(collect($records)->contains(fn (MessageLogged $message): bool => $message->message === 'gift-card.issued'))->toBeTrue();
});
