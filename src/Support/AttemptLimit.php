<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * How many times one presenter may get a code wrong.
 *
 * A hundred-bit code space does not need this — the arithmetic already puts
 * guessing out of reach. It is here for the deployment that comes later: an
 * adopter importing eight-character codes from a legacy system inherits a limit
 * rather than discovering they needed one, and a rate limit that was already
 * there when the alphabet shrank is worth more than one added afterwards.
 *
 * ### The key is the caller's, and there is no default
 *
 * This package cannot see a request, a session, an IP address or an
 * authenticated user, so it cannot key a limiter. A default would either throttle
 * every customer in the world against one counter or throttle nobody.
 * `RedeemByCode` refuses an empty key rather than skipping the limit, because a
 * limiter that silently does nothing is worse than no limiter — somebody has
 * already ticked the box.
 *
 * The caller's key is **hashed** into the cache key. An IP address or a customer
 * id sitting verbatim in a shared cache is a small leak into a store that is
 * usually less guarded than the database and often shared between applications.
 *
 * ponytail: one tier, `RateLimiter`'s own fixed window, and nothing pluggable. If
 * a deployment ever needs a second, slower tier — five a minute *and* twenty an
 * hour — that is a second `tooManyAttempts` call here, not an abstraction.
 */
final class AttemptLimit
{
    public static function tooMany(string $throttleKey): bool
    {
        return RateLimiter::tooManyAttempts(self::key($throttleKey), self::maxAttempts());
    }

    public static function hit(string $throttleKey): void
    {
        RateLimiter::hit(self::key($throttleKey), self::decaySeconds());
    }

    /**
     * Called on a successful redemption, so a customer who mistypes four times
     * and then gets it right starts again from zero rather than being locked out
     * by their own card.
     */
    public static function clear(string $throttleKey): void
    {
        RateLimiter::clear(self::key($throttleKey));
    }

    private static function key(string $throttleKey): string
    {
        return 'ecommerce-gift-cards:redeem:'.hash('sha256', $throttleKey);
    }

    private static function maxAttempts(): int
    {
        $max = config('gift-cards.redemption.max_attempts');

        return is_numeric($max) ? max(1, (int) $max) : 5;
    }

    private static function decaySeconds(): int
    {
        $decay = config('gift-cards.redemption.decay_seconds');

        return is_numeric($decay) ? max(1, (int) $decay) : 60;
    }
}
