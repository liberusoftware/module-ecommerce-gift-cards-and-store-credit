<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions;

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\RefusalReason;
use RuntimeException;

/**
 * **One class, one message, eight reasons — and the message never varies.**
 *
 * This is the anti-enumeration control, and it is the reason there are not eight
 * exception classes here.
 *
 * A gift card code is a bearer credential, so an attacker's whole game is finding
 * one that exists. Every distinguishable answer is an oracle that plays it for
 * them:
 *
 * | Told | Learned |
 * | --- | --- |
 * | "no such card" | that code is not one |
 * | "that card has expired" | **that code is one** |
 * | "that card was disabled" | **that code is one** |
 * | "insufficient balance" | **that code is one, and roughly what is on it** |
 * | "wrong currency" | **that code is one, and where it was sold** |
 *
 * Three of those are worse than the first, and the last is worse again. So every
 * refusal answers with the same bytes, and `RedemptionTest` asserts that by
 * comparing the messages of a refusal built from every case of `RefusalReason`.
 *
 * Timing is the other half of the same oracle, and it is handled in `Code`: a
 * lookup that finds nothing still performs one password verification against a
 * decoy hash, so a miss costs what a hit costs.
 *
 * ### The reason still exists, for the people entitled to it
 *
 * `$reason` is on the exception, on `RedemptionFailed`, and in the telemetry log.
 * An operator wants it and should have it.
 *
 * **A surface that shows `->reason` to a bearer has undone this control**, and
 * that sentence is in `README.md` and `docs/adoption.md` where somebody building
 * one will read it. The only reason a surface may safely treat differently is
 * `Throttled`, which tells a guesser what they already worked out from being
 * throttled — a surface mapping it to `429` and everything else to one identical
 * `422` is fine.
 */
final class RedemptionRefused extends RuntimeException
{
    /**
     * The message. Constant, by construction: there is nowhere else a message
     * comes from, and no factory takes one.
     */
    public const MESSAGE = 'That code cannot be redeemed for that amount.';

    private function __construct(public readonly RefusalReason $reason)
    {
        parent::__construct(self::MESSAGE);
    }

    public static function because(RefusalReason $reason): self
    {
        return new self($reason);
    }
}
