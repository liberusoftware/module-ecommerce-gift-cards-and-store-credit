<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Data;

/**
 * **The only object in this package that has ever held a gift card code, and it
 * holds it once.**
 *
 * The code is returned from `IssueAccount::handle()` and from nowhere else. It is
 * not stored, not logged, not put on an event, not on `AccountData`, and not
 * recoverable from anything in the database. The caller has exactly one chance to
 * do whatever it is going to do with it — print it, email it, hand it over — and
 * if it drops it, the card is unspendable and the remedy is to issue another one
 * and disable this.
 *
 * That is a deliberately unforgiving contract, and it is the difference between a
 * bearer credential and a row somebody can look up. `docs/adoption.md` leads with
 * what it means for a host holding plaintext codes today.
 *
 * ### On a replay it is null, and that is not a bug
 *
 * `recorded` is false when the idempotency key had already minted this card. The
 * card is returned so the caller can carry on; the code is **not**, because this
 * module could not produce it if it wanted to. A retry that needs the code is a
 * caller that dropped it, and the answer is a new card rather than a way to read
 * an old one back.
 */
final readonly class IssueResult
{
    public function __construct(
        public AccountData $account,
        public LedgerEntryData $entry,
        public bool $recorded,
        /**
         * The full code, in its display form, or null.
         *
         * Non-null only on the call that minted it, and only for a gift card —
         * store credit has no code at all.
         */
        public ?string $code = null,
    ) {}
}
