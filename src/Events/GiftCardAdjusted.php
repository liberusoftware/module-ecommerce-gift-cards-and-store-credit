<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerEntryData;

/**
 * **An operator corrected a balance, in one direction or the other.**
 *
 * The only event whose amount may be negative, and the only one carrying a `reasonCode`. A listener that alerts on these is a listener watching the one path where a human's decision enters the ledger.
 *
 * Past tense, and a fact rather than a request: by the time this fires the entry
 * has been written and the transaction has committed.
 *
 * It carries **read models**, never Eloquent models — a listener in the host
 * holds values it can pass around, log and queue, not a row it could call
 * `save()` on, which for an append-only ledger is the difference between a
 * boundary and a suggestion. Neither read model carries a code in any form.
 *
 * Dispatched **once** per entry. A replayed idempotency key writes nothing and
 * announces nothing, because a listener that turns this into an email must not
 * run twice for one movement of money.
 */
final class GiftCardAdjusted
{
    use Dispatchable;

    public function __construct(
        public readonly AccountData $account,
        public readonly LedgerEntryData $entry,
    ) {}
}
