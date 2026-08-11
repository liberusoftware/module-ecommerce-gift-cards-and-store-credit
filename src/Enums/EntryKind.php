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
 * There is deliberately nothing on this enum but its cases. A `movesMoney()`
 * helper would be a second place that classifies a kind, and the fold's `match`
 * — which has no `default` arm — is the first. Two classifications of five cases
 * is one of them being forgotten when a sixth arrives.
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
}
