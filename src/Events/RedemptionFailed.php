<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\RefusalReason;

/**
 * **A redemption was refused.** The event a host turns into a fraud alert.
 *
 * This is the counterpart to `RedemptionRefused`'s constant message. The bearer
 * is told nothing; the **merchant** is told everything, here, where the
 * information is useful and the audience is entitled to it.
 *
 * A burst of `Unknown` against one `throttleKey` is what an enumeration attempt
 * looks like, and it is the single most valuable thing to alert on in this
 * module. A burst of `Expired` against many keys is a marketing problem instead.
 *
 * ### It carries no code, and never will
 *
 * Not the code, not the normalised code, not the lookup index. This event fires
 * on **failure**, which is exactly when somebody reaches for "let us log what
 * they typed so we can see what went wrong" — and a log of attempted gift card
 * codes is a log of real gift card codes, because most failures are a customer
 * mistyping a card they are holding. `TelemetryTest` asserts the absence.
 *
 * `accountReference` is present only when the code found a row: it is not a
 * credential, it is what an operator needs in order to look at the card that is
 * being attacked. It is null for every refusal that never got that far.
 */
final class RedemptionFailed
{
    use Dispatchable;

    public function __construct(
        public readonly RefusalReason $reason,
        public readonly string $throttleKey,
        public readonly ?string $accountReference = null,
        public readonly ?string $sourceReference = null,
    ) {}
}
