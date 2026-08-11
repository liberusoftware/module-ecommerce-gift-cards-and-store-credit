# Ecommerce: Gift Cards and Store Credit

> This package is the authoritative, provider-neutral implementation of Gift
> Cards and Store Credit. It owns domain behavior and data; optional API,
> Filament, Livewire, React, Vue, and Nuxt packages translate its public
> contracts for their surfaces.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-gift-cards-and-store-credit?sort=semver)](https://github.com/liberusoftware/module-ecommerce-gift-cards-and-store-credit/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-gift-cards-and-store-credit/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-gift-cards-and-store-credit/actions/workflows/tests.yml)

Balances a merchant owes, held as an append-only ledger and folded on every read.
**A gift card code is a bearer credential and no column here can hold one**: what
is stored is a keyed lookup index and a per-row hash, the full code is returned
once at issue and never again, and every redemption failure answers identically
so a guess never confirms a hit. Store credit is the same ledger with a different
issue path. Integer minor units, no default currency, and a redemption in another
currency is refused rather than converted.

## Features

- Fully compatible with **Laravel 13**, **PHP 8.5**, and **Pest 5**.
- Built following the domain-driven design guidelines of the Liberu architecture.
- Reusable, presenting a clean public contract and boundaries.
- Adheres to the strict database, security, and authorization standards of Liberu.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)

## Quick start

```bash
composer require liberusoftware/ecommerce-gift-cards-and-store-credit
```

Installing boots nothing. The module ships no `extra.laravel.providers`, so
`ModuleManagerServiceProvider` is the only thing that registers it, and only when
the deployment names it:

```dotenv
MODULES_ENABLED=ecommerce-gift-cards-and-store-credit
GIFT_CARDS_CODE_PEPPER="a long random string, from a password manager"
```

**The pepper has no default and nothing works without it.** `Code::pepper()`
throws rather than hashing under `''`, because a package that quietly kept
working with no pepper would be a package whose central guarantee had been
switched off with nobody aware of it.

```php
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\{IssueAccount, RedeemByCode, RecordCredit};
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\{IssueInput, Money, RedemptionInput, CreditInput};
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\{AccountKind, CreditOrigin};

// 1. Sell a card. The code comes back **once**, here, and never again.
$issued = (new IssueAccount())->handle(new IssueInput(
    kind: AccountKind::GiftCard,
    issueKey: $idempotencyKey,        // the caller's, and the whole guarantee
    amount: new Money(5000, 'GBP'),   // integer minor units; no default currency
    customerId: 55,
    teamId: 7,
    sourceReference: 'ORD-4711',      // an opaque string; never interpreted
    expiresAt: null,                  // null is the safe default. See below
));

$issued->code;                        // 'A1B2-C3D4-…' — the only time this exists
$issued->account->reference;          // 'GC-…', quotable, and not a credential
$issued->account->state->balance();   // Money, folded from the ledger

// 2. Spend some of it. The remainder stays on the card.
$spent = (new RedeemByCode())->handle(new RedemptionInput(
    code: $whatTheCustomerTyped,      // any case, any spacing, O for 0 forgiven
    entryKey: $idempotencyKey,
    amount: new Money(3000, 'GBP'),
    throttleKey: $request->ip(),      // required — this package cannot guess one
    sourceReference: 'ORD-9001',
));

$spent->account->state->balance()->minor;   // 2000

// 3. Put money back on, by reference. A refund, a reversal, a top-up.
(new RecordCredit())->handle(new CreditInput(
    accountReference: $issued->account->reference,
    entryKey: $idempotencyKey,
    amount: new Money(1500, 'GBP'),
    origin: CreditOrigin::Refund,
    sourceReference: 'REF-3312',
));
```

## A gift card code is a bearer credential

Whoever has it has the money. That is the sentence every storage decision here
follows from.

The host stores it in `gift_cards.code`, a plaintext unique `string(16)`. That
means a database read, a leaked backup, a logged slow query, a `select *` in a
support tool or any staff member with table access is holding cash.

**The fix is not `$hidden`.** Wave 6 rejected exactly that for payment
instruments: `$hidden` is a serialisation default that `makeVisible()` steps
over, that a raw query never consults, and that this codebase already overrides
on purpose in a webhook controller. So instead:

| Stored | What it is | Why |
| --- | --- | --- |
| `code_index` | `hash_hmac('sha256', $normalised, $pepper)`, **unique** | The lookup key. Redemption is still **one indexed query**, which is what makes a non-reversible code workable at a till |
| `code_hash` | bcrypt, per-row salted, **not indexed** | Verified after the index finds the row. Depends on no shared secret, so it survives a pepper that leaks or rotates |
| `last_four` | four characters | What a receipt and a support screen show, which is the concession that stops somebody re-adding the full code to make support workable |

Neither is reversible and neither is a code. `SchemaTest` asserts thirty-odd
plaintext-code column names absent from both tables — and then issues a card and
searches **every cell of every table in the database** for the code it just
minted, because absent column names prove a schema and this proves the running
system.

### Every refusal answers identically

There is one exception class with one constant message, whether the code is
unknown, expired, disabled, short of funds, in the wrong currency or throttled:

| Told | Learned |
| --- | --- |
| "no such card" | that code is not one |
| "that card has expired" | **that code is one** |
| "insufficient balance" | **that code is one, and roughly what is on it** |

`RedemptionRefused::$reason` carries the truth, and `RedemptionFailed` carries it
to a listener, because a merchant is entitled to it and a bearer is not. **A
surface that shows `->reason` to a bearer has undone this control.** The one
reason a surface may safely treat differently is `Throttled`, which tells a
guesser what being throttled already told them.

Timing is the same oracle from the other side, so a lookup that finds nothing
still performs **one password verification against a decoy at the same cost**.

### Enumeration

Twenty **Crockford base32** characters — 32^20, a hundred bits — drawn with
`random_int()` over a fixed alphabet. Crockford drops `I`, `L`, `O` and `U`, and
`Code::normalise()` maps the confusable ones back, so a customer who typed `O`
for `0` is not told their card does not exist.

The host's `strtoupper(Str::random(16))` collapses 52 letters onto 26, leaving
letters twice as likely as digits — a distribution nobody chose and nobody wrote
down — and wraps it in `do { … } while (exists())`, a select-then-insert with a
window in it. Uniqueness here is the unique index's job, so a collision is a loud
`QueryException` rather than a card quietly filed under another card's code.

**Rate limiting is per presenter and the key is the caller's.** This package
cannot see a request, a session or an IP, so it will not guess one:
`RedeemByCode` refuses an empty `throttleKey` rather than skipping the limit,
because a limiter that silently does nothing is worse than none. A successful
redemption clears the counter.

## There is no balance column

The host has `balance` as a mutable `decimal` sitting beside a transactions
table, moved by:

```php
$this->balance -= $amount;
$this->save();
$this->transactions()->create([...]);
```

Two writes, no transaction around them, two sources of truth — and the one
anybody reads is the mutable one. A crash between them leaves a card that has
been spent with no record of it, or a record with the money still on it, and
nothing anywhere says which.

What a card is worth is `AccountState::fold()` over
`ecommerce_gift_card_entries`, computed every time it is asked for.
`SchemaTest` asserts `balance`, `initial_value`, `status` and `disabled_at`
absent **by name**, so a convenience column cannot quietly undo it.

### The fold is total, and it is proved rather than claimed

| | How it is guaranteed |
| --- | --- |
| Every entry kind is handled | `match` over `EntryKind` with **no `default` arm**; a test folds every case, so an unhandled one fails the build rather than subtracting itself from somebody's balance |
| Order cannot change the answer | every contribution is a sum or a flag, both commutative; proved over every permutation of several ledgers |
| Every sequence has one status | a cascade ending in an unconditional return; all 156 sequences of kinds up to length three enumerated, against **both** expiry values |

The second is load-bearing and it costs something: `EntryKind` has **no `Enabled`
case**, because disable-then-enable and enable-then-disable are different facts
and a fold able to tell them apart is a fold that depends on order. Disabling is
terminal; a card stopped by mistake is recovered by issuing a replacement and
moving the balance, which leaves a trail.

## The six decisions, in one place

Each is argued in [`docs/domain.md`](docs/domain.md).

| | Decision |
| --- | --- |
| **Expiry** | Ends **redeemability**, never the money. Nothing is zeroed, no entry is written, `balance()` answers the same number, and a refund onto an expired card still lands. `expires_at` is nullable with no default, so a deployment that never sets one has cards that never expire — which is what most jurisdictions require and this module deliberately does not know |
| **Partial redemption** | The card keeps its balance. No second card, because that would mean a second code and a second thing to lose |
| **Refunding onto a card** | The refunds module decides an amount and a destination reference; a host listener calls `RecordCredit`. Neither package requires, names or knows about the other |
| **Enumeration** | Crockford base32, twenty characters, a hundred bits, uniform draw. Rate limited per caller-named presenter. Every refusal identical in message and in cost |
| **Double redemption** | **Debited at redemption; no reservation.** The guard folds the ledger inside the transaction, under the account's row lock, and the entry key is a unique index. A reversal is a new entry, never a deletion |
| **Store credit vs gift card** | One ledger, one table, `AccountKind`. They differ in exactly one behaviour — a gift card is a bearer credential and store credit is not — and two tables would have meant two copies of the fold |

### Why there is no reservation

Payment Operations splits authorize from capture because a card network holds a
reservation on a customer's money for a merchant. Here the money is already the
merchant's own liability and there is nobody to hold it with. A two-phase hold
would need an expiry, an expiry needs a sweeper, and a sweeper that stops running
leaves a customer's balance locked with no third party to complain to.

So the ledger is debited when the money moves — which makes the `reference`
Checkout records against its tender a fact rather than a promise. Wave 4 decided
a gift card is *an amount and a reference*; this is the movement that reference
names.

## Money

Integer minor units, always. The host's columns are `decimal(10, 2)` and its
methods take `float $amount`; `(int) (19.99 * 100)` is **1998**, which is a penny
short, per transaction, silently, forever. There is no float anywhere in this
package's code and no `decimal` column in its schema.

**There is no default currency.** The host's is `char(3)->default('USD')` — the
`default(1)` mistake wave 2 spent a whole wave unpicking, on the one field that
decides what a number means. A card is denominated in the currency it was sold in
and redeeming against a different one is **refused, not converted**; inventing a
rate would be this module deciding a merchant's accounting, and they would find
out at the end of the month.

## This package imports nothing

No `require` on any sibling `liberusoftware/ecommerce-*` package, and no `use` of
any commerce namespace but its own anywhere in `src/`. It does not know what an
order is, what a checkout is, what a payment is or what a refund is. It is a
balance with a ledger, redeemed by reference.

No foreign key leaves its own two tables. `customer_id`, `team_id`, `recorded_by`
and `source_reference` are plain columns.

So the host joins the two sides. A refund onto a gift card, written out:

```php
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\RecordCredit;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\{CreditInput, Money};
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\CreditOrigin;

Event::listen(RefundApproved::class, function (RefundApproved $event): void {
    if ($event->refund->destinationKind !== 'gift_card') {
        return;
    }

    (new RecordCredit())->handle(new CreditInput(
        accountReference: $event->refund->destinationReference,
        // The refund's own key, reused. A fresh one per attempt guarantees
        // nothing.
        entryKey: 'refund:'.$event->refund->reference,
        amount: new Money($event->refund->amountMinor, $event->refund->currency),
        origin: CreditOrigin::Refund,
        sourceReference: $event->refund->reference,
    ));
});
```

The suite here runs with no orders, checkout, payments or refunds module
installed and none of their tables present, under tests named for the fact.

## Authorization

For a ledger the correct answer to almost every ability is **no**. Nobody edits a
balance; nobody deletes a ledger row; nobody edits an expiry date. The write
surface is the four operations the domain publishes — `redeem`, `credit`,
`adjust`, `disable` — three of them additionally gated on the domain's own answer,
so a staff member with the permission still cannot get round an invariant.

Everything else is `false`, **by name**, on both models, including `associate` /
`disassociate` / `attach` / `detach`, which are live on a `hasMany` and default
open. A ledger entry associated onto a different card would move a balance from
one customer to another without writing a row.

`viewCode` and `revealCode` are named and refused so that "nobody may ever see a
code" is a denial somebody can point at rather than an absence. There is nothing
behind them to grant.

The ledger is append-only in three layers: the model's `updating` and `deleting`
events throw, `LedgerBuilder` refuses the mass operations those events never see,
and the policy refuses every write ability. The one path none of them closes is a
raw `DB::table()` write — [`docs/runbook.md`](docs/runbook.md) says what a
deployment does about that, and a test asserts the hole exists rather than
pretending otherwise.

## What this module is not

- **Not a tender model.** Checkout ([#867](https://github.com/liberusoftware/ecommerce-laravel/issues/867)) already decided a gift card is an amount and a reference. This owns the balance behind that reference.
- **Not refund policy** ([#901](https://github.com/liberusoftware/ecommerce-laravel/issues/901)). Deciding an amount is owed is not here; recording it onto a card is.
- **Not payments** ([#875](https://github.com/liberusoftware/ecommerce-laravel/issues/875)). No gateway, no provider, no money leaving the business.
- **Not promotions or discounts.** A gift card is money already paid for; a discount is money never charged.
- **Not escheatment or unclaimed-property reporting.** A jurisdiction question, and this module deliberately knows no law. `GiftCardQuery::expiringBefore()` is the read somebody builds it on.
- **Not a code delivery channel.** The code comes back once, to the caller. Emailing it is the host's.

## Configuration

```bash
php artisan vendor:publish --tag=gift-cards-config
```

```dotenv
GIFT_CARDS_CODE_PEPPER=           # required; no default, and it throws without one
GIFT_CARDS_CODE_HASH_COST=10      # a calibration knob — measure your hardware
GIFT_CARDS_MAX_ATTEMPTS=5
GIFT_CARDS_ATTEMPT_DECAY=60
GIFT_CARDS_TEAM_MODEL="App\Models\Team"
GIFT_CARDS_TELEMETRY=false
```

Telemetry is off by default and never writes a code in any form — not the code,
not the lookup index, not the hash, and not even the last four on the refusal
path.

## Compatibility

| | Tested |
| --- | --- |
| PHP | 8.5 |
| Laravel | 13 |
| Database | SQLite, MySQL, PostgreSQL |
| Test runner | Pest 5 |

Evidence: the [Tests](https://github.com/liberusoftware/module-ecommerce-gift-cards-and-store-credit/actions/workflows/tests.yml), [Install](https://github.com/liberusoftware/module-ecommerce-gift-cards-and-store-credit/actions/workflows/install.yml) and [Compatibility](https://github.com/liberusoftware/module-ecommerce-gift-cards-and-store-credit/actions/workflows/compatibility.yml) workflows.

## Documentation

- [`docs/domain.md`](docs/domain.md) — the model, and every decision behind it.
- [`docs/adoption.md`](docs/adoption.md) — what a host has to do, starting with the plaintext codes it can no longer recover.
- [`docs/runbook.md`](docs/runbook.md) — operating it.

## License

MIT. See [LICENSE.md](LICENSE.md).
