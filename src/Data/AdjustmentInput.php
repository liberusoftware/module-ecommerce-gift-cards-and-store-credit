<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Data;

/**
 * An operator correcting a balance, in either direction.
 *
 * The one place `amount` may be negative, and the one place a human's decision
 * enters the ledger. Both facts are why it carries a `reasonCode` and a
 * `recordedBy` and refuses without them.
 *
 * `reasonCode` is a **short code** the deployment defines — `goodwill`,
 * `writeoff`, `correction`, `duplicate` — and never prose. Free text beside money
 * is where a customer's email address ends up, which wave 4 found and wave 5
 * acted on twice; there is no `note` column on either table in this module.
 *
 * An adjustment that would take the balance below zero is **refused**, under the
 * account's row lock, against a state folded from the database. Writing off more
 * than is there is not a correction, it is a different mistake.
 */
final readonly class AdjustmentInput
{
    public function __construct(
        public string $accountReference,
        public string $entryKey,
        public Money $amount,
        public string $reasonCode,
        public ?int $recordedBy = null,
        public ?string $sourceReference = null,
    ) {}
}
