<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerEntryData;

/**
 * **Money was spent off a balance.**
 *
 * It may be a **partial** redemption — read `$account->state->balance()` rather than assuming the card is now empty. The remainder stays on the card; no new card is issued and nothing is reissued.
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
final class GiftCardRedeemed
{
    use Dispatchable;

    public function __construct(
        public readonly AccountData $account,
        public readonly LedgerEntryData $entry,
    ) {}
}
