<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums;

/**
 * Gift card, or store credit.
 *
 * **The same ledger with a different issue path**, which is the decision this
 * enum records. Both are a denominated balance a merchant owes, folded from an
 * append-only ledger, redeemed by reference, and refused across currencies.
 * Modelling them as two tables would have meant two copies of the fold — two
 * implementations of one piece of arithmetic, which is two answers waiting to
 * disagree — for one behavioural difference.
 *
 * That difference is below: a gift card is a **bearer credential** and store
 * credit is not. A gift card is bought, arrives as a code on a piece of card or
 * in an email, and belongs to whoever is holding it; the module's job is to make
 * sure the code cannot be recovered from anything it stores. Store credit is
 * granted to a customer, is redeemed because the module was told who is asking,
 * and has no code to protect.
 *
 * The consequence is that `customer_id` is required for store credit and optional
 * for a gift card, and that `code_index` is the other way round. `IssueAccount`
 * enforces both.
 */
enum AccountKind: string
{
    case GiftCard = 'gift_card';
    case StoreCredit = 'store_credit';

    /**
     * Whether holding the credential is what entitles you to the money.
     *
     * True for a gift card, and it is the reason every code-storage decision in
     * this package exists. False for store credit, which is settled against a
     * customer this module was handed.
     */
    public function isBearer(): bool
    {
        return $this === self::GiftCard;
    }
}
