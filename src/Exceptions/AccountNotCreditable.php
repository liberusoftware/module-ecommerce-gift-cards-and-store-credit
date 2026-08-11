<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions;

use RuntimeException;

/**
 * Money cannot be put onto this balance.
 *
 * One case: the account is **disabled**. Crediting a stopped card puts a
 * merchant's money somewhere nobody can spend it, which is worse than refusing —
 * a refund that lands on a disabled card looks paid and is not.
 *
 * **An expired account is deliberately not a case here.** A refund onto an
 * expired card lands, and the money sits there, because expiry ends redeemability
 * and not ownership. That is the expiry decision showing up on the credit path,
 * and it is the reason it is written down in `docs/domain.md` rather than left to
 * whoever reads this file next.
 *
 * A negative **adjustment** against a disabled account is allowed: an operator
 * writing off the balance of a card they have just stopped is the ordinary end of
 * that story.
 */
final class AccountNotCreditable extends RuntimeException
{
    public static function disabled(string $reference): self
    {
        return new self("The balance `{$reference}` has been disabled and cannot be credited. Money put onto a stopped card cannot be spent by anybody. Issue a replacement and credit that instead.");
    }
}
