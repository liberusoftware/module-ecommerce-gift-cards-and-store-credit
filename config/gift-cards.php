<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The host's team model
    |--------------------------------------------------------------------------
    |
    | A gift card belongs to a team, and the team belongs to the application
    | rather than to this package. So the class is resolved at call time and never
    | imported — a module that names `App\Models\Team` in a `use` statement
    | installs into exactly one application.
    |
    | The default is Jetstream's, which is what every Liberu application uses.
    |
    */

    'team_model' => env('GIFT_CARDS_TEAM_MODEL', 'App\\Models\\Team'),

    /*
    |--------------------------------------------------------------------------
    | The code pepper
    |--------------------------------------------------------------------------
    |
    | **The single most important setting in this package, and it is deliberately
    | not a database column.** `SchemaTest` asserts by name that no table here has
    | one.
    |
    | A gift card code is a bearer credential: whoever holds it holds the money.
    | This module never stores one. What it stores is
    | `hash_hmac('sha256', $normalisedCode, $pepper)` as a unique lookup index, so
    | that redemption still finds the row in one query, plus a per-row password
    | hash of the same code. Neither is reversible, and neither is reachable
    | without this value.
    |
    | Keeping it in the environment means a leaked database — a backup, a read
    | replica, a `select *` in a support tool — does not contain the material an
    | offline attacker would need to build a lookup table against the index.
    |
    | There is **no default**. `Code::pepper()` throws when this is empty rather
    | than hashing under `''`, because a package that quietly kept working with no
    | pepper would be a package whose central guarantee had been switched off
    | without anybody noticing.
    |
    |     GIFT_CARDS_CODE_PEPPER="$(head -c 32 /dev/urandom | base64)"
    |
    | Rotating it is a real operation with a real cost — see `docs/runbook.md`.
    |
    */

    'code_pepper' => env('GIFT_CARDS_CODE_PEPPER'),

    /*
    |--------------------------------------------------------------------------
    | Code hashing cost
    |--------------------------------------------------------------------------
    |
    | The bcrypt cost factor for the per-row code hash. A calibration knob, not a
    | feature: what the right number is depends on the hardware a deployment runs
    | on, and the tradeoff is real in both directions — too low and a stolen
    | database with a leaked pepper becomes cheaper to attack, too high and every
    | redemption at a till waits for it.
    |
    | 10 is roughly 60ms on ordinary 2026 server hardware. Measure yours.
    |
    */

    'code_hash_cost' => (int) env('GIFT_CARDS_CODE_HASH_COST', 10),

    /*
    |--------------------------------------------------------------------------
    | Redemption rate limiting
    |--------------------------------------------------------------------------
    |
    | A code is guessed by trying codes. The alphabet and length this module mints
    | put that beyond reach on their own — twenty Crockford base32 characters is a
    | hundred bits — but an adopter who later imports shorter codes from a legacy
    | system inherits this limit rather than discovering they needed one.
    |
    | The key is **the caller's**, and `RedeemByCode` requires it. This module
    | cannot see a request, a session or an IP address, and guessing at one would
    | either throttle everybody together or throttle nobody. A surface passes
    | something that identifies the presenter.
    |
    | `RateLimiter::clear()` runs on a successful redemption, so a customer who
    | mistypes four times and then gets it right starts again from zero.
    |
    */

    'redemption' => [
        'max_attempts' => (int) env('GIFT_CARDS_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('GIFT_CARDS_ATTEMPT_DECAY', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | Structured records of this module's own domain events. Off by default: a
    | package that starts filling a deployment's log the moment it installs has
    | decided somebody else's retention bill.
    |
    | **No code is ever written here, in any form** — not the code, not the
    | normalised code, not the lookup index, not the hash. Not even on a refusal,
    | which is the line somebody would most want it on. What is written is the
    | account reference, the amount, the reason and the throttle key.
    | `TelemetryTest` asserts that rather than trusting this comment.
    |
    */

    'telemetry' => [
        'enabled' => (bool) env('GIFT_CARDS_TELEMETRY', false),
        'channel' => env('GIFT_CARDS_TELEMETRY_CHANNEL'),
    ],

];
