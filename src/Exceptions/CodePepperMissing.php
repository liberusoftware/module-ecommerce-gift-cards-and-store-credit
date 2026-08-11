<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions;

use RuntimeException;

/**
 * No `gift-cards.code_pepper` is configured.
 *
 * **Thrown rather than defaulted, deliberately.** Hashing under `''` would work
 * perfectly: cards would issue, codes would redeem, the suite would be green, and
 * the module's central guarantee would be switched off with nobody aware of it.
 * A deployment that has not set a pepper has not decided to run without one, it
 * has not got round to it.
 *
 * The same shape as Payment Operations refusing to accept a callback when no
 * signing secret is configured. An endpoint that appears to check a signature and
 * does not is worse than one with no check, because somebody has already ticked
 * the box.
 */
final class CodePepperMissing extends RuntimeException
{
    public static function make(): self
    {
        return new self('No `gift-cards.code_pepper` is configured. Set GIFT_CARDS_CODE_PEPPER to a long random string; without it this module would hash every gift card code under the empty string, which is the same as not hashing them.');
    }
}
