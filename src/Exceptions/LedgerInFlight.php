<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions;

use RuntimeException;

/**
 * The same idempotency key, claimed by a call that has not committed yet.
 * **Transient.**
 *
 * A surface should answer `423` with a `Retry-After`: nothing is wrong, two
 * workers are processing the same retry, and the loser should ask again shortly.
 * The opposite instruction to `LedgerConflict`, which is why they are two classes
 * rather than one with two messages.
 */
final class LedgerInFlight extends RuntimeException
{
    public static function entry(string $entryKey): self
    {
        return new self("The entry key `{$entryKey}` is being processed by another call that has not committed. Nothing is wrong: retry shortly.");
    }

    public static function issue(string $issueKey): self
    {
        return new self("The issue key `{$issueKey}` is being processed by another call that has not committed. Nothing is wrong: retry shortly.");
    }
}
