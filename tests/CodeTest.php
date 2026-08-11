<?php

declare(strict_types=1);

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\CodePepperMissing;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Support\Code;

/**
 * **A gift card code is a bearer credential. Whoever has it has the money.**
 *
 * The host stores it in `gift_cards.code`, a plaintext unique `string(16)`. Every
 * test in this file is about the replacement, and the replacement is not "hide
 * the column" — it is that nothing recoverable is written down at all.
 */
it('mints a hundred bits over an alphabet a human can read off a card', function () {
    $code = Code::mint();
    $normalised = Code::normalise($code);

    expect(strlen($normalised))->toBe(20)
        ->and(strspn($normalised, Code::ALPHABET))->toBe(20)
        // Crockford's alphabet, which drops the four characters a human gets
        // wrong or would rather not find printed on a gift card.
        ->and(Code::ALPHABET)->not->toContain('I')
        ->not->toContain('L')
        ->not->toContain('O')
        ->not->toContain('U')
        // Displayed in groups of four, like every other long code a human types.
        ->and($code)->toBe(Code::format($normalised))
        ->and(substr_count($code, '-'))->toBe(4);

    // 32^20 is 2^100. Written out because the number is the argument.
    expect(strlen(Code::ALPHABET) ** 20)->toBeGreaterThan(2 ** 99);
});

it('never mints the same code twice', function () {
    // Not a uniqueness proof — that is the unique index's job, deliberately,
    // because the host's `do { … } while (exists())` is a select followed by an
    // insert with a window in between. This is a smoke test that the draw is
    // actually random.
    $codes = [];

    for ($i = 0; $i < 200; $i++) {
        $codes[] = Code::mint();
    }

    expect(array_unique($codes))->toHaveCount(200);
});

it('draws every symbol of the alphabet eventually, so the distribution is not skewed', function () {
    // The host's `strtoupper(Str::random(16))` collapses 52 letters onto 26,
    // leaving letters **twice as likely** as digits — a distribution nobody chose
    // and nobody wrote down. `random_int` over a fixed alphabet has none of that.
    $seen = [];

    for ($i = 0; $i < 400; $i++) {
        foreach (str_split(Code::normalise(Code::mint())) as $character) {
            $seen[$character] = true;
        }
    }

    expect(count($seen))->toBe(strlen(Code::ALPHABET));
});

it('forgives everything about how a code was typed', function (string $typed) {
    // A code is read off a piece of card and typed into a box. Refusing `abcd
    // efgh` would be refusing a correct answer, and telling a customer their card
    // does not exist because they typed `O` for `0` is the same failure as losing
    // it.
    expect(Code::normalise($typed))->toBe('0123456789ABCDEFGHJK');
})->with([
    'as printed' => ['0123-4567-89AB-CDEF-GHJK'],
    'lower case' => ['0123-4567-89ab-cdef-ghjk'],
    'with spaces' => ['0123 4567 89AB CDEF GHJK'],
    'run together' => ['0123456789ABCDEFGHJK'],
    'the letter O for zero' => ['O123-4567-89AB-CDEF-GHJK'],
    'the letter I for one' => ['0i23-4567-89AB-CDEF-GHJK'],
    'the letter L for one' => ['0L23-4567-89AB-CDEF-GHJK'],
]);

it('knows what is not one of ours without going near the database', function (string $presented) {
    expect(Code::isWellFormed(Code::normalise($presented)))->toBeFalse();
})->with([
    'too short' => ['ABCD-EFGH'],
    'too long' => ['0123-4567-89AB-CDEF-GHJK-MNPQ'],
    'nothing at all' => [''],
    'a letter that is not in the alphabet' => ['U123-4567-89AB-CDEF-GHJK'],
]);

it('stores a lookup index that is a fixed-width digest and not a code', function () {
    $code = Code::mint();
    $normalised = Code::normalise($code);
    $index = Code::index($normalised);

    expect(strlen($index))->toBe(64)
        ->and($index)->toMatch('/^[0-9a-f]{64}$/')
        ->and($index)->not->toContain(substr($normalised, 0, 8))
        // Deterministic, which is what makes redemption **one query** rather than
        // a scan over every card comparing hashes.
        ->and(Code::index($normalised))->toBe($index);
});

it('keys the lookup index on the pepper, so a stolen database cannot be tabled against', function () {
    $normalised = Code::normalise(Code::mint());
    $underOne = Code::index($normalised);

    config()->set('gift-cards.code_pepper', 'a completely different pepper');

    expect(Code::index($normalised))->not->toBe($underOne);
});

it('refuses to hash under the empty string rather than quietly working', function () {
    // The most important line in `Code`. Hashing under `''` would work perfectly:
    // cards would issue, codes would redeem, the suite would be green, and the
    // module's central guarantee would be switched off with nobody aware of it.
    config()->set('gift-cards.code_pepper', null);

    Code::index('0123456789ABCDEFGHJK');
})->throws(CodePepperMissing::class);

it('salts the per-row hash, so two identical codes would not look identical', function () {
    // The half that survives a pepper leak: bcrypt is per-row salted and depends
    // on no shared secret, so rotating or losing the pepper leaves it sound.
    $normalised = '0123456789ABCDEFGHJK';

    $first = Code::hash($normalised);
    $second = Code::hash($normalised);

    expect($first)->not->toBe($second)
        ->and(Code::verify($normalised, $first))->toBeTrue()
        ->and(Code::verify($normalised, $second))->toBeTrue()
        ->and(Code::verify('0123456789ABCDEFGHJM', $first))->toBeFalse();
});

it('performs a verification even when there is nothing to verify against', function () {
    // **The timing half of the anti-enumeration control.** A constant refusal
    // message is no use if a miss returns in a millisecond and a hit takes sixty:
    // the clock is an oracle too. So a miss verifies against a decoy at the
    // configured cost.
    //
    // Asserted as a ratio rather than as an absolute, because a wall clock in CI
    // is not a measuring instrument. What would fail here is the shape this test
    // exists to prevent — an early `return false` on a null hash, which makes a
    // miss essentially free.
    config()->set('gift-cards.code_hash_cost', 10);

    $normalised = '0123456789ABCDEFGHJK';
    $hash = Code::hash($normalised);

    // Warm the decoy, which is computed once per process per cost factor.
    Code::verify($normalised, null);

    $startHit = hrtime(true);
    Code::verify($normalised, $hash);
    $hit = hrtime(true) - $startHit;

    $startMiss = hrtime(true);
    expect(Code::verify($normalised, null))->toBeFalse();
    $miss = hrtime(true) - $startMiss;

    expect($miss)->toBeGreaterThan((int) ($hit / 4));
});

it('keeps four characters for display and no more', function () {
    $normalised = '0123456789ABCDEFGHJK';

    expect(Code::lastFour($normalised))->toBe('GHJK')
        ->and(strlen(Code::lastFour($normalised)))->toBe(4);
});

it('clamps a nonsense hashing cost rather than hashing badly', function () {
    // A cost of 3 from a typo in an environment file is a hashing scheme that is
    // not one. bcrypt's own range is 4 to 31.
    config()->set('gift-cards.code_hash_cost', 1);

    $hash = Code::hash('0123456789ABCDEFGHJK');

    expect($hash)->toStartWith('$2y$04$')
        ->and(Code::verify('0123456789ABCDEFGHJK', $hash))->toBeTrue();
});
