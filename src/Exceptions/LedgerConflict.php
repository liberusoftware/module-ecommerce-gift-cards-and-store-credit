<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions;

use RuntimeException;

/**
 * The same idempotency key, used twice for two different things. **Permanent.**
 *
 * Retrying cannot help and a surface over this module should answer `409`: the
 * caller reused a key across two genuinely different payloads, and the only fixes
 * are a new key or the facts the first call sent.
 *
 * This is one of a deliberate **pair**, and the pair is the whole point.
 * `LedgerInFlight` is its opposite number: transient, clears by itself, retry
 * shortly. **They are opposite instructions to a caller**, and publishing one
 * class for both means a consumer has to decode a message string to find out
 * which instruction it just received.
 *
 * Wave 4's checkout module shipped exactly one class for both and its API had to
 * rebuild the in-flight message from the domain's own factory to tell a `409`
 * from a `423`. Fulfillment fixed it; Returns still carries the issue. Payment
 * Operations started from the fixed version and so does this. A surface answers
 * with `instanceof` and nothing downstream parses a message.
 */
final class LedgerConflict extends RuntimeException
{
    public static function entry(string $entryKey): self
    {
        return new self("The entry key `{$entryKey}` has already recorded a different movement against a balance. Retrying will not help: use a new key, or send the facts the first call sent.");
    }

    public static function issue(string $issueKey): self
    {
        return new self("The issue key `{$issueKey}` has already issued a balance from different facts. Retrying will not help: use a new key, or send the facts the first call sent.");
    }
}
