<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Data;

use InvalidArgumentException;
use JsonSerializable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\CurrencyMismatch;

/**
 * An amount of money, as an integer number of minor units and a currency.
 *
 * ### Why this type exists at all
 *
 * The host's gift card was `decimal('balance', 10, 2)` cast to `decimal:2`, and
 * its methods took `float $amount`. A float on a money path is the one place a
 * rounding eventually shows up in somebody's reconciliation: `(int) (19.99 * 100)`
 * is **1998**, because 19.99 is not representable in binary floating point and the
 * truncation goes the wrong way. That is a penny short, per transaction,
 * silently, forever.
 *
 * So there is no float anywhere in this module. `SchemaTest` asserts no column in
 * either table is a `decimal`, `float`, `double`, `numeric` or `real`, and
 * `BoundaryTest` asserts the word `float` does not appear in any of this
 * package's code.
 *
 * ### There is no default currency
 *
 * The host's column is `char(3)->default('USD')`. A default currency is the
 * `default(1)` mistake wave 2 spent a whole wave unpicking — a value that reads
 * as deliberate and was chosen by nobody. The constructor requires one, the
 * column has no default, and `IssueAccount` refuses without one.
 *
 * ### It is a copy, not an import
 *
 * `MODULE_DEVELOPMENT.md` R7 puts shared value types in `ecommerce-commerce-core`,
 * and this module deliberately does not require it — no sibling
 * `liberusoftware/ecommerce-*` package appears in `composer.json` and no commerce
 * namespace but this one appears in `src/`. **Values cross a boundary; classes do
 * not.** The wire shape below is the contract, and it is fifty lines of arithmetic
 * rather than a dependency edge.
 *
 * ### The wire shape
 *
 *     {"minor": 1999, "currency": "GBP", "exponent": 2, "decimal": "19.99"}
 *
 * `decimal` is a **string**, produced by string arithmetic, so it survives a JSON
 * round trip through anything — including a client that parses numbers as
 * doubles. It is there so a consumer can render without knowing the exponent
 * table; it is never parsed back, and nothing in this module reads it.
 */
final readonly class Money implements JsonSerializable
{
    public function __construct(
        public int $minor,
        public string $currency,
        public int $exponent = 2,
    ) {
        // ISO 4217 is three letters. Checked because the column is `char(3)` and
        // a silently truncated currency is a wrong amount that looks right.
        if (preg_match('/^[A-Z]{3}$/', $this->currency) !== 1) {
            throw new InvalidArgumentException("`{$this->currency}` is not a three-letter currency code. An amount in minor units is meaningless without one, and this module has no default.");
        }

        // 0 for JPY, 2 for most, 3 for KWD/BHD/OMR, 4 for the outliers. Anything
        // past that is not a currency, it is a mistake about what this field is.
        if ($this->exponent < 0 || $this->exponent > 4) {
            throw new InvalidArgumentException("A currency exponent of {$this->exponent} is not a currency exponent. It is 0 for JPY, 2 for most, and at most 4.");
        }
    }

    /** The zero of this currency, which is what an empty fold sums to. */
    public static function zero(string $currency, int $exponent = 2): self
    {
        return new self(0, $currency, $exponent);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency, $this->exponent);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency, $this->exponent);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    /**
     * Refuses rather than converting.
     *
     * A card is denominated in the currency it was sold in. Redeeming a £50 card
     * against a €40 basket is not a conversion this module is entitled to make:
     * it would be picking a rate, at a moment, on somebody else's behalf, and the
     * merchant would find out at the end of the month. Wave 6 settled that money
     * is recorded and never converted, and this is the same rule one module over.
     */
    public function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    /**
     * The amount as a decimal string, by string arithmetic and never by division.
     *
     * `1999` at exponent 2 is `"19.99"`; `5` is `"0.05"`; `1000` at exponent 0 is
     * `"1000"`. Dividing by `10 ** $exponent` would produce a float, which is the
     * thing this whole class exists to avoid — and it would do it in the one
     * method whose output a human reads and believes.
     */
    public function decimal(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $digits = (string) abs($this->minor);

        if ($this->exponent === 0) {
            return $sign.$digits;
        }

        // Pad so there is always at least one digit before the point: 5 at
        // exponent 2 has to become "005" before it can become "0.05".
        $digits = str_pad($digits, $this->exponent + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$this->exponent).'.'.substr($digits, -$this->exponent);
    }

    /** @return array{minor: int, currency: string, exponent: int, decimal: string} */
    public function toArray(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'exponent' => $this->exponent,
            'decimal' => $this->decimal(),
        ];
    }

    /** @return array{minor: int, currency: string, exponent: int, decimal: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
