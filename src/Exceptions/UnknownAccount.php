<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions;

use RuntimeException;

/**
 * No account carries that reference.
 *
 * Thrown on the **reference** paths — credit, adjust, disable — which are
 * operator operations behind a policy. It names the reference, and that is safe
 * for exactly the reason a reference is not a code: it is already in exports,
 * support tickets and panel URLs, and holding one redeems nothing.
 *
 * Nothing on the bearer path ever throws this. A code that finds no row is
 * `RedemptionRefused` with the same message every other refusal carries.
 */
final class UnknownAccount extends RuntimeException
{
    public static function reference(string $reference): self
    {
        return new self("No gift card or store credit account has the reference `{$reference}`.");
    }
}
