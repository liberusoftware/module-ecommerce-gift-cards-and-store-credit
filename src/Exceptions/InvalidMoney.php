<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions;

use InvalidArgumentException;

/**
 * An amount that is not an amount of money for the operation asked for.
 *
 * Issuing a card for nothing, crediting nothing, redeeming a negative — each is a
 * caller bug rather than a domain condition, so it throws where it happens
 * instead of becoming a ledger row of zero.
 *
 * The one place a negative amount is legitimate is `RecordAdjustment`, which is
 * an operator deliberately taking money off with a reason code attached.
 */
final class InvalidMoney extends InvalidArgumentException
{
    public static function notPositive(string $operation, int $minor): self
    {
        return new self("A {$operation} must be for a positive amount, got {$minor} minor units.");
    }

    public static function zeroAdjustment(): self
    {
        return new self('An adjustment of zero corrects nothing. Record the adjustment you meant, or record none.');
    }
}
