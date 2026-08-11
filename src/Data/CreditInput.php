<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Data;

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\CreditOrigin;

/**
 * Money going onto a card that already exists.
 *
 * Addressed by the account's **reference**, never by a code. A credit is not a
 * bearer operation: nobody presents a card in order to have money put on it, and
 * a path that took a code here would be a second place a code could be typed,
 * logged and guessed against.
 *
 * The consequence is that this action is only as safe as the surface in front of
 * it. A reference is not a credential — it appears in exports and support tickets
 * — so **`RecordCredit` must never be reachable by an unauthenticated caller.**
 * `GiftCardAccountPolicy::credit()` is the gate a panel uses;
 * `docs/adoption.md` says it again where a host is wiring the refund listener.
 *
 * ### The refund case
 *
 * `origin: CreditOrigin::Refund` with `sourceReference` set to whatever the
 * refunds module called its refund. That is the entire integration between the
 * two modules of this wave: an integer, a currency code and two strings, carried
 * by a listener the host writes. Neither package requires the other, names the
 * other, or knows whether the other is installed.
 */
final readonly class CreditInput
{
    public function __construct(
        public string $accountReference,
        public string $entryKey,
        public Money $amount,
        public CreditOrigin $origin,
        public ?string $sourceReference = null,
        public ?int $recordedBy = null,
    ) {}
}
