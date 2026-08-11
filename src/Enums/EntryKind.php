<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums;

/**
 * The five things that can happen to a balance, and the complete list of them.
 *
 * Each case says what it contributes to the fold, and every contribution is
 * either a **sum** or a **flag**. Both are commutative and associative, which is
 * what makes folding the ledger in any order give the same answer — see
 * `AccountState`.
 *
 * There is deliberately **no `Expired` case and no `Enabled` case**, and the
 * reasons are different.
 *
 * Expiry is not an event: it is a date on the account passing, and it changes
 * what may be done with the balance rather than the balance. Writing a ledger row
 * for it would say money moved, which is exactly the thing this module refuses to
 * say when no money moved.
 *
 * Re-enabling is not offered because it would break commutativity. `Disabled`
 * then `Enabled` and `Enabled` then `Disabled` are different facts, and a fold
 * that can tell them apart is a fold that depends on order. Disabling is
 * therefore terminal, and a card disabled by mistake is recovered by issuing a
 * replacement and transferring the balance — two ledger entries, a trail, and no
 * lost commutativity. `docs/runbook.md` has the procedure.
 */
enum EntryKind: string
{
    /** The card was sold, or the credit was granted. Adds to the balance. */
    case Issued = 'issued';

    /** Money put back or added later — a refund, a reversal, a top-up. Adds. */
    case Credited = 'credited';

    /** Money spent. Subtracts. Always a positive amount; the kind is the direction. */
    case Redeemed = 'redeemed';

    /** An operator correction, signed. The only kind whose amount may be negative. */
    case Adjusted = 'adjusted';

    /** The card was stopped. Sets a flag; contributes nothing to the balance. */
    case Disabled = 'disabled';

    /**
     * Whether this kind changes what the card is worth.
     *
     * Used by the telemetry logger to pick a level, and by nothing else — the
     * fold dispatches on the case itself so that a new one without an arm fails
     * the build rather than falling into a helper's `default`.
     */
    public function movesMoney(): bool
    {
        return match ($this) {
            self::Issued, self::Credited, self::Redeemed, self::Adjusted => true,
            self::Disabled => false,
        };
    }

    /**
     * Whether a caller may ask for this kind by name.
     *
     * Nothing here is written by a `kind` parameter — each action writes exactly
     * one kind — but a surface listing what happened wants to know which rows
     * were somebody's decision and which were the card being sold.
     */
    public function isOperatorAction(): bool
    {
        return match ($this) {
            self::Adjusted, self::Disabled => true,
            self::Issued, self::Credited, self::Redeemed => false,
        };
    }
}
