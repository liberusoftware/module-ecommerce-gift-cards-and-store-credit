<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerEntryData;

/**
 * **A balance came into existence — a card was sold, or credit was granted.**
 *
 * The event a host turns into an email carrying the code. **This event does not carry the code**, and it cannot: the code exists once, on the `IssueResult` the caller is holding, and a listener is not that caller. A host that emails a code does it on the call that issued, not from here.
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
final class GiftCardIssued
{
    use Dispatchable;

    public function __construct(
        public readonly AccountData $account,
        public readonly LedgerEntryData $entry,
    ) {}
}
