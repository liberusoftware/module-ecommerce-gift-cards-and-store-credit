<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums;

/**
 * Why money was put onto a card, recorded because the three are different
 * liabilities and a merchant is asked about them separately.
 *
 * `Refund` is **where the two modules of this wave meet without importing each
 * other.** A refunds module decides that a customer is owed an amount and that
 * the destination is a gift card reference. It does not call this package and
 * this package does not call it; a listener in the host takes the amount and the
 * reference off the refund's event and calls `RecordCredit`. What crosses is an
 * integer, a currency code and two strings.
 *
 * `Reversal` is a redemption that did not stick — the order it paid for fell over
 * after the debit. It is a **new entry**, never a deletion, and it carries the
 * `source_reference` of the redemption it undoes so the pair reads as a pair.
 */
enum CreditOrigin: string
{
    /** A refund whose destination was this card. Decided elsewhere, recorded here. */
    case Refund = 'refund';

    /** A redemption being put back, because whatever it paid for did not happen. */
    case Reversal = 'reversal';

    /** More money added to an existing card. */
    case TopUp = 'top_up';
}
