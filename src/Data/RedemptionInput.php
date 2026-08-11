<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Data;

/**
 * A code presented, and an amount asked for.
 *
 * ### `throttleKey` is required, and this module will not invent one
 *
 * A code is guessed by trying codes, and the only defence that does not depend on
 * the alphabet is a limit per presenter. This package cannot see a request, a
 * session, an IP address or a customer — so it cannot key a limiter, and a
 * default would either throttle every customer in the world together or throttle
 * nobody at all.
 *
 * So the caller names the presenter. Whatever identifies one: an IP, a session
 * id, a customer id, a till number. `RedeemByCode` refuses an empty one rather
 * than skipping the limit, because a rate limiter that silently does nothing is
 * worse than none — somebody has already ticked the box.
 *
 * ### The code is not part of the idempotency hash
 *
 * `entry_hash` is a column, and a hash of the code plus known facts is code
 * material in a column. The stored hash covers the account, the amount and the
 * source reference, which is what "the same movement" means; the code is how the
 * caller got here, not what happened.
 */
final readonly class RedemptionInput
{
    public function __construct(
        public string $code,
        public string $entryKey,
        public Money $amount,
        public string $throttleKey,
        public ?string $sourceReference = null,
        public ?int $recordedBy = null,
    ) {}
}
