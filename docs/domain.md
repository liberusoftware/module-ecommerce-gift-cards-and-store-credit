# The domain

What this module models, and every decision behind it. Written for somebody who
has to change it, or who has to decide whether a bug is a bug.

---

## 1. The two ideas

**A gift card code is a bearer credential, and no column here can hold one.**

**A balance is a fold over an append-only ledger, and there is no column for that
either.**

Everything below follows from one of those, and where they conflict the first
wins.

---

## 2. The code

### 2.1 What "bearer credential" means, concretely

Whoever has the code has the money. There is no second factor, no account to log
into, no identity to check. It is cash with a printable representation.

The host stores it in `gift_cards.code`, a plaintext unique `string(16)`. So:

- Every database backup contains spendable money.
- Every read replica does.
- A slow-query log that captured `select * from gift_cards where code = ?`
  contains it twice.
- `select *` in any support tool prints it.
- Any staff member with table access is holding it.
- `CONFORMANCE.md` records controllers in this host returning model rows
  wholesale to clients, so one careless resource is the whole table.

None of that requires a breach. It is the ordinary operation of a system that
wrote the credential down.

### 2.2 What is stored instead

| Column | Value | Purpose |
| --- | --- | --- |
| `code_index` | `hash_hmac('sha256', $normalised, $pepper)` | The **unique lookup key**. Redemption normalises what was typed, hashes it, and finds the row in one indexed query |
| `code_hash` | `password_hash($normalised, PASSWORD_BCRYPT)` | Verified after the index finds the row |
| `last_four` | four characters | Receipts, support screens, emails |

The full code is returned **once**, on `IssueResult::$code`, from the call that
minted it. There is no method anywhere in this package that returns a code for an
account that already exists, and an idempotent replay of the issue call returns
`null` — not as a policy, but because the module could not produce it.

### 2.3 Why two columns and not one

`code_index` alone would satisfy "not plaintext" and "one query". The second
column earns its place on one axis: **independence from the pepper.**

The index is deterministic under a single shared secret. If that secret leaks
alongside a backup — same `.env`, same host, same operator — an attacker can
build a lookup table for any code space they can enumerate. Twenty Crockford
characters is not enumerable, so today that is theoretical. It stops being
theoretical the moment an adopter imports eight-character codes from a legacy
system, which is a thing adopters do, or rotates a pepper and has to decide what
to do with the old index.

`code_hash` is per-row salted and depends on no shared secret, so it is sound
under both. The cost is one bcrypt per redemption on a path that was already
going to take a row lock, and that cost is doing double duty as the timing
equaliser in §2.5.

It is **deliberately not indexed**. It is verified, never searched. An index on
it is the first step towards somebody filtering by it, and both a filter and a
search term persist into a query string, a log line and a browser history.

### 2.4 The alphabet, the length, the arithmetic

**Crockford base32, twenty characters. 32^20 = 2^100.**

Crockford's alphabet drops `I`, `L`, `O` and `U`. The first three because a human
reading a code off a card confuses them with `1`, `1` and `0`; `U` because
leaving it out makes an accidental obscenity much less likely on something
printed and posted. `Code::normalise()` maps the confusable characters *back*
rather than refusing them, so a customer who typed `O` for `0` is not told their
card does not exist.

The host's `strtoupper(Str::random(16))` is worse in two ways that are easy to
miss:

1. `Str::random` is base62. Uppercasing collapses 52 letters onto 26, so the
   result is over 36 symbols with **letters twice as likely as digits** — a
   biased distribution nobody chose and nobody documented.
2. It is wrapped in `do { … } while (static::where('code', $code)->exists());` —
   a select followed by an insert, with a window between them.

Here the draw is `random_int()` over a fixed alphabet, which is CSPRNG-backed and
uniform, and uniqueness is the unique index's job. A collision is a loud
`QueryException` rather than a card quietly filed under another card's code.

### 2.5 Every refusal answers identically, in message and in cost

A code space is only as strong as the cheapest oracle over it.

| Told | Learned |
| --- | --- |
| "no such card" | that code is not one |
| "that card has expired" | **that code is one** |
| "that card was disabled" | **that code is one** |
| "insufficient balance" | **that code is one, and roughly what is on it** |
| "wrong currency" | **that code is one, and where it was sold** |

So there is one exception class, `RedemptionRefused`, with one constant message,
and `RedemptionTest` asserts it by building a refusal from every case of
`RefusalReason` and comparing the messages.

The clock is the same oracle from the other side. A lookup that found nothing
would return in a millisecond while a hit paid for a bcrypt, which is a
measurable difference at any distance. So `Code::verify()` performs **exactly one
password verification either way** — against the row's hash when there is one,
against a decoy at the same configured cost when there is not.

**`RefusalReason` still exists**, on the exception, on `RedemptionFailed` and in
telemetry, because a merchant is entitled to it and a bearer is not. A surface
that renders `->reason` to a bearer has undone this control. The one reason that
may safely be treated differently is `Throttled`, which tells a guesser what
being throttled already told them; a surface mapping it to `429` and everything
else to one identical `422` is fine.

### 2.6 Rate limiting, and why the key is the caller's

`RedeemByCode` **refuses an empty `throttleKey`**. It does not default one.

This package cannot see a request, a session, an IP address or an authenticated
user. Any default it invented would either throttle every customer in the world
against one counter or throttle nobody, and the second is the one that would ship
— a limiter that silently does nothing is worse than none, because somebody has
already ticked the box.

Five attempts per minute per presenter, from configuration, one tier. A
successful redemption clears the counter, so a customer who mistypes four times
and then gets it right is not locked out by their own card.

The caller's key is hashed into the cache key. An IP address or a customer id
sitting verbatim in a shared cache is a small leak into a store that is usually
less guarded than the database.

### 2.7 The pepper

Configuration, from the environment, with **no default and no fallback**.
`Code::pepper()` throws `CodePepperMissing` rather than hashing under `''`.

Hashing under the empty string would work perfectly: cards would issue, codes
would redeem, the suite would be green, and the module's central guarantee would
be switched off with nobody aware of it. A deployment that has not set a pepper
has not decided to run without one — it has not got round to it. Same shape as
Payment Operations refusing a callback when no signing secret is configured.

`SchemaTest` asserts by name that no column in either table could hold it.

---

## 3. The balance

### 3.1 There is no balance column

The host has `balance` as a mutable `decimal(10, 2)` sitting beside
`gift_card_transactions`, moved by:

```php
$this->balance -= $amount;
$this->save();

$this->transactions()->create([...]);
```

Two writes, no transaction around them. A crash between them leaves a card that
has been spent with no record of it, or a record with the money still on it, and
nothing anywhere says which. And when they disagree, the number anybody reads is
the mutable one — because `isActive()`, `canUse()` and `scopeActive()` all read
the column and none of them reads the ledger.

`AccountState::fold()` sums `ecommerce_gift_card_entries` every time it is asked.
A derived balance cannot disagree with the entries that produced it, because it
*is* those entries. There is nothing to edit, so no surface can offer it, and
`GiftCardAccountPolicy` has no `update` ability to grant.

### 3.2 The fold, and the proof that it is total

"Total" means every sequence of entries the schema admits has exactly one answer,
and producing it cannot throw. Proved three ways in `tests/FoldTest.php`.

**Every kind is handled:**

```php
match ($entry->kind) {
    EntryKind::Issued   => $issued += $minor,
    EntryKind::Credited => $credited += $minor,
    EntryKind::Redeemed => $redeemed += $minor,
    EntryKind::Adjusted => $adjusted += $minor,
    EntryKind::Disabled => $disabled = true,
};
```

**No `default` arm.** An unhandled case raises `UnhandledMatchError` the first
time it is folded, and a test folds a ledger containing every case of
`EntryKind::cases()`. A `default` arm would have converted that into a silent
zero, which on a gift card is money that stops existing.

**Order cannot change the answer.** Every contribution is a sum (`+=`) or a flag
(`= true`). Both are commutative and associative, so the fold is a commutative
monoid and folding a set of entries in any order gives a bit-identical state.
Proved by folding every permutation of several multisets.

**One status per state.** `status()` is a four-branch cascade over one integer and
two booleans, ending in an unconditional `return AccountStatus::Active`.
`FoldTest` enumerates all 156 sequences of kinds up to length three — the empty
ledger included — against **both** expiry values, asserting each yields an
`AccountStatus` and that the same input yields the same answer. A second dataset
reaches every branch by hand, so the totality is not achieved by the cascade
having collapsed into one answer.

The ordering is **most permanent first**:

| Order | Rule | Why it sits there |
| --- | --- | --- |
| 1 | `disabled` → `Disabled` | Terminal, and the one somebody has to act on |
| 2 | `expired` → `Expired` | **Has a balance behind it.** See §4.1 |
| 3 | `balance <= 0` → `Empty` | Recoverable by a credit |
| 4 | — | `Active` |

### 3.3 What the fold deliberately does not do

**It does not clamp.** `balanceMinor()` can be negative, and `balance()` reports
it. That is not reachable through anything this module writes — every debit is
guarded under a row lock — so a negative balance means a raw `DB::table()` write,
an import, a restore or a second writer. `needsReconciliation()` reports it and
`GiftCardQuery::needingReconciliation()` is the queue. A wrong number nobody is
told about is the worst outcome available to a module that holds somebody's
money.

**It does not add unlike units.** An entry in a currency other than the account's
is excluded from the sums and counted, so the fold stays total without the totals
becoming lies. The write path refuses one; this is what happens if a row exists
anyway.

### 3.4 What it costs

`state()` costs a query unless `entries` is loaded, and the arithmetic runs every
time. `GiftCardQuery` eager-loads on every read it publishes. For an operational
queue over one team this is fine; `needingReconciliation()` carries a `ponytail:`
note naming the ceiling and the upgrade path. The upgrade is a materialised flag
written by the same code that appends the entry — **not** a second implementation
of the fold in SQL, because two implementations of one piece of arithmetic is two
answers waiting to disagree.

---

## 4. The six decisions

### 4.1 Expiry ends redeemability, never the money

Many jurisdictions regulate gift card expiry and several forbid it. **This module
does not know the law and must not act as though it did.**

So:

- `expires_at` is nullable with **no default**. A deployment that never passes one
  has cards that never expire, which is the safe direction and what most
  jurisdictions require.
- When it is set and passes, **nothing happens to the money.** No entry is
  written, the ledger does not change, `balance()` reports the same number it did
  the day before, and staff and the customer can both still see it.
- Only redemption is refused. `AccountStatus::Expired` is a status **with a
  balance behind it**, which is the whole decision in one line.
- A credit onto an expired card **lands**. A refund to a card that has passed its
  date is still the customer's money.

Expiry is an **input to the fold**, alongside currency and exponent, because all
three are facts about the account rather than about the ledger. Putting it there
means exactly one place decides whether a card may be spent; a second place would
eventually answer differently.

`expires_at` is written once, at issue, and **there is no path that edits it** —
`RefusesEveryWrite::update()` is false by name. A merchant that must honour an
expired card issues a replacement and moves the balance, which leaves a trail
that editing a date would not.

`GiftCardQuery::expiringBefore()` is the read a "your card expires next month"
reminder is built on, which is the honest thing to do with an expiry a
jurisdiction allows.

### 4.2 Partial redemption leaves the remainder on the card

A £50 card meeting a £30 basket is debited £30 and is worth £20. No second card
is minted, nothing is reissued, and the customer keeps the code they have.

A balance folded from a ledger has a remainder by construction, so this is close
to free — but it is still a decision, because the alternative exists in the wild:
some schemes void the original and issue a new card for the change. That would
mean a second code, a second credential to protect, and a second thing for a
customer to lose. The host already worked this way; it is written down here
because it is a choice.

### 4.3 Refunding onto a gift card

**This is where wave 7's two modules meet without either importing the other.**

- A refunds module decides that a customer is owed an amount, and that the
  destination is a gift card **reference**.
- A listener the **host** writes takes the amount and the reference off the
  refund's event and calls `RecordCredit` with `CreditOrigin::Refund`.
- What crosses is an integer, a currency code and two strings.

Neither package requires, names, imports or knows about the other. There is no
listener in this module's service provider for anything it did not publish
itself, and `BoundaryTest` asserts that by reading the file. The refund test in
that suite runs with no refunds table in the database at all.

`RecordCredit` is addressed by **reference, never by a code**. Nobody presents a
card in order to have money put on it, and a path that took a code would be a
second place a code could be typed, logged and guessed against. The consequence
is that this action is only as safe as the surface in front of it — a reference
is not a credential, so **it must never be reachable by an unauthenticated
caller.**

A refund in a currency the card is not denominated in is **refused**, by name,
with `CurrencyMismatch`. Unlike the bearer path, a listener or an operator is
entitled to know why.

### 4.4 Double redemption: debited at redemption, no reservation

A card redeemed twice concurrently is the gift-card equivalent of a double
charge, so this is the decision with the most machinery behind it.

**There is no two-phase hold.** Payment Operations splits authorize from capture
because a card network holds a reservation on a *customer's* money for a
merchant. Here the money is already the merchant's own liability and there is
nobody to hold it with. A hold would need an expiry; an expiry needs a sweeper;
and a sweeper that stops running leaves a customer's balance locked with no third
party to complain to. That failure is silent and the customer's only recourse is
support.

So the ledger is debited when the money moves. That is what makes the `reference`
Checkout records against its tender a **fact rather than a promise** — wave 4
decided a gift card is *an amount and a reference*, and this is the movement that
reference names. If what it paid for falls over afterwards, the remedy is
`RecordCredit` with `CreditOrigin::Reversal`, carrying the `source_reference` of
the redemption it undoes: a new entry, never a deletion.

The invariant is held by two things:

1. **A unique index on `entry_key`.** Two workers processing one retry both
   insert; the database picks a winner and the loser replays. Not a `select`
   followed by an insert, which has a window after it exactly wide enough for the
   second worker.
2. **The lock, the fold, the guard, the append — in that order.**
   `AppendsToLedger::appendEntry()` opens a transaction, takes `lockForUpdate()`
   on the account, folds the ledger *under that lock*, evaluates the guard against
   those numbers, and only then writes. Two tills racing for the last £20 both
   pass a check made against what the caller loaded; exactly one passes a check
   made against the database.

The lock is held for a fold and an insert — microseconds, with no network call
inside it. Payment Operations names holding a lock across a provider call as its
ceiling; this module has no provider, so that ceiling does not exist here.

**What could not be proved, stated honestly.** Both guarantees are properties of
the *database*, and this suite runs on SQLite `:memory:` inside a
`RefreshDatabase` transaction on one connection. There is no second connection to
race with, so **a genuine concurrent race is not exercised** — the same
limitation wave 4's checkout module wrote into its own idempotency suite.
`LedgerTest` says so in its header and proves the three things it can: that the
index is declared, that the loser's recovery branch executes (entered by writing
the competing row from a `creating` hook, which is the exact window a real race
lands in), and — in `RedemptionTest` — that the guard reads from the database by
driving it with a deliberately stale in-memory account that still believes the
card is full.

A test that started two processes and hoped they collided would be slower,
flakier, and would still not prove the thing on a database this suite does not
run against.

### 4.5 Enumeration

Covered in §2.4 to §2.6. In one line: a hundred bits, drawn uniformly, rate
limited per caller-named presenter, with every refusal identical in message and
in cost.

### 4.6 Store credit and gift cards are one ledger

**One table, one ledger, one fold, distinguished by `AccountKind`.**

They are the same thing: a denominated balance a merchant owes, folded from an
append-only ledger, redeemed by reference, refused across currencies. Every
guard, every invariant and all of the arithmetic is identical.

They differ in exactly one behaviour, and it is the one this whole module is
about: **a gift card is a bearer credential and store credit is not.** A gift
card is bought, arrives as a code, and belongs to whoever is holding it. Store
credit is granted, has no code, and is redeemed because the caller knows who is
asking. That difference shows up as four lines in `IssueAccount` and two
constraints:

- Store credit **must** name a customer, because without one it could never be
  spent.
- A gift card **need not**, because a bearer card belongs to nobody until it is
  presented.

Modelling them as two tables would have meant two migrations, two models, two
policies and — the one that matters — **two copies of the fold**, which is two
answers waiting to disagree. There is therefore no separate store-credit table
prefix: both live under `ecommerce_gift_card_`, and the module name covers both.

---

## 5. Money

Integer minor units everywhere. The host's columns are `decimal(10, 2)` and its
methods take `float $amount`; `(int) (19.99 * 100)` is 1998, which `MoneyTest`
asserts as a test of the premise rather than of this module. No `decimal`,
`float`, `double`, `numeric` or `real` column exists in this schema and no float
appears in this package's code.

`Money::decimal()` is string arithmetic — padding and `substr`, never division —
so the wire value `"19.99"` is exact and survives a client that parses numbers as
doubles.

```json
{"minor": 1999, "currency": "GBP", "exponent": 2, "decimal": "19.99"}
```

`Money` is a **copy of a value type, not an import of one**. R7 puts shared value
types in `ecommerce-commerce-core`; requiring it would be a sibling dependency
this module does not have. Values cross a boundary; classes do not.

### There is no default currency

The host's is `char(3)->default('USD')`. A default currency is the `default(1)`
mistake wave 2 spent a whole wave unpicking: a value that reads as deliberate and
was chosen by nobody, on the one field that decides what a number means. A
`SchemaTest` case asserts the column has no default and is not nullable.

A card is denominated in the currency it was sold in, that currency is fixed for
the life of the balance, and every entry must match it. **A movement in another
currency is refused, not converted.** Inventing a rate would be this module
deciding a merchant's accounting on their behalf, at a moment nobody chose, and
they would find out at the end of the month. Wave 6 settled that money is
recorded and never converted; this is the same rule one module over.

A caller with a genuinely cross-currency case converts **outside** this module,
deliberately, and either issues a card in the other currency or credits one that
already exists.

---

## 6. Tenancy

**Team scope**, matching Payment Operations and wave 1.5.

A gift card is a **liability of the merchant that sold it**, and in this platform
the merchant entity is the team. `team_id` is a plain, unconstrained column on
both tables — teams belong to the host, and a package that constrained a table it
does not own could not be installed without it. The model is reached through
`config('gift-cards.team_model')`, resolved at call time and never imported.

**Not store scope.** A card redeemable in one of a merchant's stores but not
another is a *restriction* — a rule about where money may be spent — rather than
a tenancy boundary, and restrictions are policy that nobody has asked for. Adding
a `store_id` speculatively would be a column with one value in it forever.

Two details that are easy to get wrong and are both tested:

- **An orphan is visible to nobody.** `where('col', null)` compiles to `is null`,
  so a scope written that way lists exactly the rows the policy denies.
  `scopeOfTeam()` refuses the null rather than translating it.
- **A non-numeric team id fails closed.** `is_numeric()` on the actor's team
  returns null on a ULID or UUID deployment, and null means *no*. A misconfigured
  host sees nothing rather than everything. `docs/runbook.md` names it, because a
  guard that fails closed is invisible until somebody asks why the panel is
  empty.

---

## 7. Boundaries

No `require` on any sibling `liberusoftware/ecommerce-*` package and no `use` of
any commerce namespace but this one anywhere in `src/`. What crosses is
identifiers and values: an amount in minor units, a currency code, and opaque
strings — `source_reference` on both tables — that this module never interprets.

No foreign key leaves its own two tables. The only one declared is
`entries.account_id → accounts.id`, cascading, because an entry means nothing
without its account.

| | Theirs | Ours |
| --- | --- | --- |
| Checkout ([#867](https://github.com/liberusoftware/ecommerce-laravel/issues/867)) | a tender: an amount, a kind and a reference | the balance behind that reference |
| Refunds ([#901](https://github.com/liberusoftware/ecommerce-laravel/issues/901)) | whether a customer is owed anything, and where it goes | recording it against a card |
| Payment Operations ([#875](https://github.com/liberusoftware/ecommerce-laravel/issues/875)) | money moving in and out of the business | money that never leaves it |
| Orders | what was bought | what paid for it, by reference |
| Promotions | money never charged | money already paid for |
| Accounting | breakage, escheatment, revenue recognition | what the balance is, and every movement of it |

---

## 8. Authorization

For a ledger the correct answer to almost every ability is **no**.

Four abilities are yes-able on an account — `redeem`, `credit`, `adjust`,
`disable` — and three of them are gated on the domain's own answer as well as on
tenancy, so a staff member with the permission still cannot get round an
invariant. `adjust` is the exception and is the ability to grant to the fewest
people: it is the only one that can make the arithmetic say anything at all.

Nothing on a ledger entry is yes-able at all.

Two hazards this fleet has shipped repeatedly:

- A model with **no** policy is exposed; Laravel's unanswered gate is permissive.
- A **present** policy missing a method is the sharper version, because
  Filament's `get_authorization_response()` returns *allow* for an ability it has
  no method for — and the file existing makes it look like a control.

So `RefusesEveryWrite` names eighteen abilities including `associate`,
`disassociate`, `attach` and `detach`, which are live on a `hasMany` and default
open — associating a ledger entry onto a different card would move a balance from
one customer to another without writing a row. The parameter is typed `Model`
rather than a concrete class, because wave 4 found a policy typed against one
model whose gate call about its child raised a `TypeError` from inside the policy,
which is a 500 rather than a denial.

**`viewCode` and `revealCode` are named and refused.** There is nothing behind
them to grant — the code is not in the database in any recoverable form — but
"there is no such ability" and "the ability exists and is denied" look identical
from a panel, and only one of them survives somebody adding a resource.

The ledger is append-only in three layers — model events, `LedgerBuilder`, policy
— because each alone leaves a door open. The one path none of them closes is a
raw `DB::table()` write; a test asserts that hole is open rather than pretending
it shut, and `runbook.md` says what a deployment does about it.

---

## 9. Things that will surprise somebody

- **`state()` is a method, not a property.** It costs a query if `entries` is not
  loaded. That is the price of not caching, and it is deliberate.
- **An expired card still reports its full balance.** That is not a bug and it is
  the single most important line in §4.1.
- **A disabled card cannot be re-enabled**, ever, by anybody. `EntryKind` has no
  `Enabled` case because it would break the fold's commutativity. The recovery is
  a replacement card and a transfer.
- **`expires_at` cannot be changed after issue.** Same recovery.
- **A replayed issue returns a null code.** The module could not produce it.
- **`RedemptionRefused` says nothing useful on purpose.** Read `->reason` in the
  log, never in a response body.
- **`RecordCredit` refuses a disabled card but not an expired one.** Two
  different questions: one is "can anybody spend this", the other is "whose money
  is it".
- **An adjustment may be negative; nothing else may.** It is the only path where
  a human's decision enters the ledger, and it is why it demands a reason code.
- **An account with no team is invisible to everybody**, including the person who
  created it.
- **There is no `byCode()` on the query API.** Lookup by code exists to spend a
  card, not to find one.
