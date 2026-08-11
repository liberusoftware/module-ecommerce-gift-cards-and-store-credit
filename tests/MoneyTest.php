<?php

declare(strict_types=1);

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\Money;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\CurrencyMismatch;

it('is why there is no float anywhere in this package', function () {
    // A test of the premise rather than of this module: `19.99` is not
    // representable in binary floating point, and the truncation goes the wrong
    // way. That is a penny short, per transaction, silently, forever — and the
    // host's `canUse(float $amount)` is where it would have happened.
    expect((int) (19.99 * 100))->toBe(1998)
        ->and(new Money(1999, 'GBP')->decimal())->toBe('19.99');
});

it('renders a decimal by string arithmetic, never by division', function (int $minor, string $currency, int $exponent, string $expected) {
    expect(new Money($minor, $currency, $exponent)->decimal())->toBe($expected);
})->with([
    'pounds' => [1999, 'GBP', 2, '19.99'],
    'pennies' => [5, 'GBP', 2, '0.05'],
    'nothing' => [0, 'GBP', 2, '0.00'],
    'a whole one' => [100, 'GBP', 2, '1.00'],
    'yen, which has no minor unit' => [1000, 'JPY', 0, '1000'],
    'dinars, which have three' => [1999, 'KWD', 3, '1.999'],
    'a write-off' => [-2500, 'GBP', 2, '-25.00'],
]);

it('refuses a currency that is not one, because there is no default to fall back on', function (string $currency) {
    new Money(100, $currency);
})->with(['usd', 'POUNDS', '', 'GB', 'GBPX'])->throws(InvalidArgumentException::class);

it('refuses an exponent that is not a currency exponent', function (int $exponent) {
    new Money(100, 'GBP', $exponent);
})->with([-1, 5, 18])->throws(InvalidArgumentException::class);

it('refuses to add unlike units rather than inventing a rate', function () {
    // A card is denominated in the currency it was sold in. Converting would mean
    // picking a rate, at a moment, on a merchant's behalf.
    new Money(1000, 'GBP')->plus(new Money(1000, 'EUR'));
})->throws(CurrencyMismatch::class);

it('adds, subtracts and compares within one currency', function () {
    $fifty = new Money(5000, 'GBP');
    $thirty = new Money(3000, 'GBP');

    expect($fifty->plus($thirty)->minor)->toBe(8000)
        ->and($fifty->minus($thirty)->minor)->toBe(2000)
        ->and($fifty->isGreaterThan($thirty))->toBeTrue()
        ->and($thirty->isGreaterThan($fifty))->toBeFalse()
        ->and(Money::zero('GBP')->isZero())->toBeTrue()
        ->and($fifty->isPositive())->toBeTrue()
        ->and(new Money(-1, 'GBP')->isNegative())->toBeTrue();
});

it('publishes a wire shape whose decimal is a string', function () {
    // So it survives a JSON round trip through a client that parses numbers as
    // doubles — which is the whole reason it is not a number.
    $wire = json_decode((string) json_encode(new Money(1999, 'GBP')), true);

    expect($wire)->toBe(['minor' => 1999, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '19.99'])
        ->and($wire['decimal'])->toBeString();
});
