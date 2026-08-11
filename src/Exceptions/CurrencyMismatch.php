<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions;

use RuntimeException;

/**
 * Two amounts in different currencies, which have no sum and no comparison.
 *
 * **Refused, never converted.** A card is denominated in the currency it was sold
 * in. Redeeming a £50 card against a €40 basket would mean this module picking a
 * rate, at a moment, on a merchant's behalf — and the merchant would find out at
 * the end of the month. Wave 6 settled that money is recorded and never
 * converted; this is the same rule one module over.
 *
 * A caller with a genuinely cross-currency case converts **outside** this module,
 * decides the rate deliberately, and either issues a card in the other currency
 * or credits one that already exists.
 *
 * On the **bearer** path this never reaches a caller as itself: `RedeemByCode`
 * catches it and answers `RedemptionRefused` with the same message every other
 * refusal answers, because "wrong currency" tells a guesser the code is real and
 * where it was sold.
 */
final class CurrencyMismatch extends RuntimeException
{
    public static function between(string $expected, string $given): self
    {
        return new self("This balance is denominated in {$expected} and the amount is in {$given}. Money is recorded, never converted: convert deliberately outside this module, or use a balance in the right currency.");
    }
}
