<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Data;

use DateTimeInterface;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountKind;

/**
 * Everything needed to bring a balance into existence.
 *
 * `issueKey` is the caller's idempotency key, and it is the whole guarantee that
 * a double-clicked "issue card" button mints one card rather than two. It is a
 * unique index on `ecommerce_gift_card_accounts`, not a `select` followed by an
 * insert — a `select` has a window after it exactly wide enough for the second
 * worker already processing the same retry.
 *
 * `amount` has **no default currency**, because `Money` has none. A card is
 * denominated in the currency it was sold in.
 *
 * `expiresAt` is null in the ordinary case and null is the only safe default:
 * many jurisdictions regulate or forbid gift card expiry, this module does not
 * know which one a deployment is in, and a package that expired cards because
 * nobody said otherwise would be a package making a legal decision. It is written
 * once and there is no path that edits it.
 */
final readonly class IssueInput
{
    public function __construct(
        public AccountKind $kind,
        public string $issueKey,
        public Money $amount,
        public ?int $customerId = null,
        public ?int $teamId = null,
        public ?string $sourceReference = null,
        public DateTimeInterface|string|null $expiresAt = null,
        public ?int $recordedBy = null,
    ) {}
}
