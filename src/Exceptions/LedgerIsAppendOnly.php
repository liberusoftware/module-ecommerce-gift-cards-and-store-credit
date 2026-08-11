<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions;

use RuntimeException;

/**
 * Somebody tried to rewrite history.
 *
 * Every number this module reports is built from `ecommerce_gift_card_entries`,
 * so an update or a delete there does not correct a balance — it changes what a
 * balance has always been, with nothing left to say it happened.
 *
 * A correction is a **new row**: `RecordAdjustment` with a reason code and an
 * actor. That is not a workaround for this restriction, it is what a ledger is.
 */
final class LedgerIsAppendOnly extends RuntimeException
{
    public static function update(): self
    {
        return new self('A gift card ledger entry cannot be updated. Record an adjustment instead: it carries a reason code and an actor, and it leaves the original where it is.');
    }

    public static function delete(): self
    {
        return new self('A gift card ledger entry cannot be deleted. Every balance this module reports is built from these rows.');
    }
}
