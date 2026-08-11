<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Data;

/**
 * What every movement against a balance answers with.
 *
 * `recorded` is false on an idempotent replay: the key had already written this
 * entry, nothing new happened, and **no event was announced**. That last part is
 * the one worth reading twice — an event is what a listener turns into an email
 * or an invoice, and announcing the same movement of money twice is how a
 * customer is charged once and told about it twice.
 *
 * `account` is re-read **after** the append and carries the folded state, so the
 * balance on it is the balance after this movement rather than before it. A
 * caller showing somebody "£30 left" wants the number that is true now.
 */
final readonly class LedgerResult
{
    public function __construct(
        public AccountData $account,
        public LedgerEntryData $entry,
        public bool $recorded,
    ) {}
}
