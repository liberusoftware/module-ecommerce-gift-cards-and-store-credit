<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Support;

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\CodePepperMissing;

/**
 * **A gift card code is a bearer credential. Whoever has it has the money.**
 *
 * Everything this class does follows from that one sentence, so it is worth
 * reading before changing any of it.
 *
 * The host stores the code in `gift_cards.code`, a plaintext unique `string(16)`.
 * That means a database read, a leaked backup, a logged slow query, a `select *`
 * in a support tool or any staff member with table access is holding cash. This
 * class is what replaces it, and the replacement is not "hide the column" — it is
 * that **there is no column that could hold a code**, which `SchemaTest` asserts
 * by name. Wave 6 rejected `$hidden` for payment instruments for exactly this
 * reason: `makeVisible()` walks straight past it, a raw query never consults it,
 * and this very codebase already overrides it on purpose in a webhook controller.
 *
 * ## What is stored, and why it is two things
 *
 * **`code_index` — `hash_hmac('sha256', $normalised, $pepper)`, unique.** The
 * lookup key. Redemption normalises the presented code, hashes it with the same
 * pepper, and finds the row in **one indexed query**, which is what makes a
 * non-reversible code compatible with a till that has to answer in a moment. The
 * pepper is configuration and never a column, so a stolen database on its own has
 * no material to build a lookup table against this.
 *
 * **`code_hash` — bcrypt, per-row salted, unindexed.** Verified after the index
 * finds the row. What it buys over the index alone is **independence from the
 * pepper**: the index is deterministic under one shared secret, so a pepper that
 * leaks alongside a backup, or one a deployment rotates, weakens it — while this
 * column stays sound because it depends on nothing shared. It is deliberately not
 * indexed: it is verified, never searched, and nothing should be able to make it
 * a lookup key.
 *
 * Neither is reversible and neither is a code. `last_four` is what a receipt and
 * a support screen show, which is the concession that stops somebody re-adding
 * the full code to make support workable.
 *
 * ## The alphabet, the length, and the arithmetic
 *
 * **Crockford base32, twenty characters.** 32^20 is 2^100 — a hundred bits.
 *
 * Crockford's alphabet drops `I`, `L`, `O` and `U`: the first three because a
 * human reading a code off a card confuses them with `1`, `1` and `0`, and `U`
 * because leaving it out makes an accidental obscenity much less likely on a
 * physical product. `normalise()` maps the confusable characters back rather than
 * refusing them, so a customer who typed `O` for `0` is not told their card does
 * not exist.
 *
 * The host's `strtoupper(Str::random(16))` is worse in two ways that are easy to
 * miss. `Str::random` is base62 and uppercasing it collapses 52 letters onto 26,
 * so the result is over 36 symbols with letters **twice as likely** as digits —
 * a biased distribution nobody chose and nobody documented. And the loop around
 * it is `do { … } while (exists())`, a select-then-insert with a window in it.
 * Here the codes are drawn uniformly with `random_int()`, which is CSPRNG-backed
 * and unbiased, and uniqueness is the unique index's job — so a collision is a
 * loud `QueryException` rather than a card quietly filed under another card's
 * code.
 *
 * ## Timing tells nothing either
 *
 * `verify()` performs **exactly one password verification whether or not the row
 * was found**, against a decoy hash at the configured cost when it was not. A
 * lookup that skipped the hash on a miss would make a miss measurably faster than
 * a hit, which is the same oracle `RedemptionRefused`'s constant message closes
 * from the other side.
 */
final class Code
{
    /**
     * Crockford base32. No `I`, `L`, `O` or `U`.
     */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Twenty symbols over a thirty-two symbol alphabet is exactly 100 bits. */
    public const LENGTH = 20;

    /** Printed and typed in groups of four, like every other long code a human handles. */
    public const GROUP = 4;

    /**
     * One decoy hash per cost factor, so a miss costs what a hit costs.
     *
     * Computed lazily and kept for the life of the process: at the configured
     * cost, not at a hardcoded one, because a decoy that is cheaper than the real
     * thing reopens the timing channel it exists to close.
     *
     * @var array<int, string>
     */
    private static array $decoys = [];

    /**
     * A new code, in the form a human sees: `XXXX-XXXX-XXXX-XXXX-XXXX`.
     *
     * Returned to exactly one caller, once. Nothing here writes it anywhere.
     */
    public static function mint(): string
    {
        $code = '';

        for ($position = 0; $position < self::LENGTH; $position++) {
            // `random_int` is CSPRNG-backed and draws uniformly over the range,
            // so there is no modulo bias to reason about. `Str::random` would
            // have been shorter and would have given a distribution nobody chose.
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return self::format($code);
    }

    /**
     * What a customer typed, turned into the one form this module hashes.
     *
     * Spaces, hyphens and case are all noise — a code is read off a card and typed
     * into a box, and refusing `abcd efgh` would be refusing a correct answer.
     * `I` and `L` become `1` and `O` becomes `0`, which is Crockford's own
     * decoding rule and the reason those characters are not in the alphabet.
     *
     * `U` is **not** mapped. It is excluded from the alphabet rather than
     * confusable with anything in it, so a code containing one is simply not a
     * code this module ever minted.
     */
    public static function normalise(string $presented): string
    {
        $stripped = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $presented));

        return strtr($stripped, ['I' => '1', 'L' => '1', 'O' => '0']);
    }

    /**
     * Whether a normalised string could be one of ours at all.
     *
     * Used to skip the database on obvious rubbish — but **not** to answer
     * differently: `RedeemByCode` still pays the decoy verification and still
     * answers with the identical refusal, so this is a saved query rather than a
     * shortcut a guesser can measure.
     */
    public static function isWellFormed(string $normalised): bool
    {
        return strlen($normalised) === self::LENGTH
            && strspn($normalised, self::ALPHABET) === self::LENGTH;
    }

    /**
     * The unique lookup index. Keyed, so a stolen database cannot be tabled
     * against.
     */
    public static function index(string $normalised): string
    {
        return hash_hmac('sha256', $normalised, self::pepper());
    }

    /** The per-row hash, which depends on no shared secret. */
    public static function hash(string $normalised): string
    {
        return password_hash($normalised, PASSWORD_BCRYPT, ['cost' => self::cost()]);
    }

    /**
     * Verify a presented code against a stored hash, in constant work.
     *
     * A null hash means the lookup found nothing, or found store credit, which
     * has no code. **One verification still runs**, against the decoy, so the two
     * paths cost the same. Returning early there would have been one line shorter
     * and would have handed an attacker a timing oracle for free.
     */
    public static function verify(string $normalised, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            password_verify($normalised, self::decoy());

            return false;
        }

        return password_verify($normalised, $hash);
    }

    /** The four characters a receipt shows. */
    public static function lastFour(string $normalised): string
    {
        return substr($normalised, -self::GROUP);
    }

    /** `XXXXXXXX…` to `XXXX-XXXX-…`. Display only; nothing hashes this form. */
    public static function format(string $normalised): string
    {
        return implode('-', str_split($normalised, self::GROUP));
    }

    /**
     * The pepper, or a refusal.
     *
     * Never `?? ''`. Hashing under the empty string would work perfectly and
     * would switch the module's central guarantee off silently.
     */
    public static function pepper(): string
    {
        $pepper = config('gift-cards.code_pepper');

        if (! is_string($pepper) || $pepper === '') {
            throw CodePepperMissing::make();
        }

        return $pepper;
    }

    private static function cost(): int
    {
        $cost = config('gift-cards.code_hash_cost');

        // Clamped to bcrypt's own range rather than trusted: a cost of 3 from a
        // typo in an environment file is a hashing scheme that is not one.
        return max(4, min(31, is_numeric($cost) ? (int) $cost : 10));
    }

    private static function decoy(): string
    {
        $cost = self::cost();

        return self::$decoys[$cost] ??= password_hash('a code that redeems nothing', PASSWORD_BCRYPT, ['cost' => $cost]);
    }
}
