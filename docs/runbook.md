# Runbook

Operating this module. What breaks, what it looks like, and what to do.

---

## 1. Four failures nobody will be paged about

### 1.1 Somebody is working through the code space

**Looks like:** nothing. Every attempt returns the same message a mistyped card
returns, which is the point.

**Find it:** turn telemetry on and watch `gift-card.redemption-refused`.

```
reason=unknown, throttle_key=<one value>, repeatedly
```

A burst of `unknown` against **one** throttle key is a guesser. A burst of
`unknown` across **many** keys, each below the limit, is a distributed one, and
the rate limiter will not see it because the limiter is per presenter by design.
Alert on the aggregate rate of `unknown` as well as on the per-key one.

A burst of `expired` across many keys is not an attack, it is a marketing
problem.

**Do about it:** the arithmetic is already on your side — twenty Crockford
characters is a hundred bits, and nobody guesses that. What this signals is
either a probe worth blocking upstream at the edge, or that somebody has imported
short legacy codes (see `docs/adoption.md` §1, Option C). Check the second before
assuming the first.

**Do not** make the refusals more informative to help you debug it.

### 1.2 An actor whose team id is not numeric sees nothing

**Looks like:** an empty panel and no error anywhere.

`ReadsWithinTeam::teamOf()` uses `is_numeric()`, so a ULID or UUID
`current_team_id` returns null, and null means **no**. That is the safe
direction — a misconfigured host sees nothing rather than everything — and it is
completely silent.

**Check:**

```php
User::find($id)->current_team_id;   // numeric?
```

**Do about it:** the guard needs widening for a non-integer tenancy deployment.
That is a change to this package, not a configuration.

### 1.3 A balance that is arithmetically impossible

**Looks like:** nothing, until somebody looks.

```php
(new GiftCardQuery())->needingReconciliation($teamId);
```

A negative balance is not reachable through anything this module writes — every
debit is guarded, under the account's row lock, against a state folded from the
database inside the same transaction. So a row in this queue means one of four
things:

| Cause | Tell |
| --- | --- |
| A raw `DB::table()` write | No `entry_key` pattern you recognise, or an edited `amount_minor` |
| A bad import | Several accounts, all created at the same moment |
| A restore that lost entries | The account exists and the ledger is short |
| A second writer | Entries this application did not create |

**Do about it:** work out which, then correct with `RecordAdjustment` and a
reason code. Never with an `update`, which the model, the builder and the policy
all refuse anyway.

A mismatched-currency entry also lands here
(`state->mismatchedCurrencyEntries > 0`). The write path refuses one, so its
presence means the same four causes.

### 1.4 Nobody has ever read the reconciliation queue

The most likely failure in this list. Put it on a panel or a schedule on day one.

---

## 2. Correcting things

### 2.1 Somebody redeemed the wrong amount

`RecordCredit` with `CreditOrigin::Reversal` for the full amount, carrying the
redemption's `source_reference`, then redeem the right amount. Two entries, and
the ledger reads as what happened.

Not `RecordAdjustment` — that is for a human's decision, not for undoing a
mechanical mistake, and the distinction is what makes the `adjusted` total worth
looking at.

### 2.2 A ledger entry is wrong and must not stand

It stands. Record an adjustment beside it:

```php
(new RecordAdjustment())->handle(new AdjustmentInput(
    accountReference: 'GC-…',
    entryKey: 'correction:'.$ticket,
    amount: new Money(-2500, 'GBP'),   // signed
    reasonCode: 'correction',           // a code, never prose
    recordedBy: $operator->id,
));
```

An adjustment that would take the balance below zero is refused. Writing off more
than is there is a different mistake.

### 2.3 A card was reported lost or stolen

```php
(new DisableAccount())->handle('GC-…', 'stop:'.$ticket, 'reported_stolen', $operator->id);
```

Terminal. The balance is untouched and still visible — stopping a card is not
spending it — and redemption is refused from that moment.

If the customer is entitled to the money, **issue a replacement and move the
balance**:

```php
// 1. Off the stopped card.
(new RecordAdjustment())->handle(new AdjustmentInput(
    accountReference: $stopped, entryKey: 'transfer-out:'.$ticket,
    amount: new Money(-$balanceMinor, $currency),
    reasonCode: 'transferred_out', recordedBy: $operator->id,
));

// 2. Onto a new one, with a code the customer can actually use.
$replacement = (new IssueAccount())->handle(new IssueInput(
    kind: AccountKind::GiftCard, issueKey: 'replacement:'.$ticket,
    amount: new Money($balanceMinor, $currency),
    customerId: $customerId, teamId: $teamId,
    sourceReference: 'replaces:'.$stopped,
));
```

### 2.4 A card was disabled by mistake

**There is no way to re-enable it.** `EntryKind` has no `Enabled` case, because
disable-then-enable and enable-then-disable are different facts, and a fold able
to tell them apart is a fold that depends on order — and order-independence is
load-bearing everywhere else.

The procedure is §2.3 step 1 and step 2. Two entries, a trail, and a code the
customer can use. In every case that is *not* a mistake the old code is in
somebody else's hands anyway, so a replacement was always the right answer.

### 2.5 A card must be honoured after its expiry date

Same procedure. `expires_at` is written once and cannot be edited — `update` is
false by name on the policy — so the way to honour an expired card is to issue a
replacement with a later date or none, and move the balance.

That is deliberately more work than editing a date, and it leaves a record of who
decided to extend somebody's card. A date somebody could quietly change is a date
that gets quietly changed.

---

## 3. The code

### 3.1 "A customer says their code does not work"

In order:

1. **Ask for the last four**, and find the card by that plus the customer.
   `GiftCardQuery::forCustomer()`. Never ask them to send you the code.
2. Read `state->status()`. `Disabled`, `Expired` and `Empty` each explain it,
   and the customer was told none of them.
3. If the status is `Active` and the balance covers it, the code they are typing
   is not the code on that card. `Code::normalise()` already forgives case,
   spacing, `O`/`0` and `I`/`L`/`1`, so what is left is a genuinely wrong code or
   a card from another merchant.
4. Check the telemetry log for `gift-card.redemption-refused` and their throttle
   key. The `reason` is there.

**You cannot look up their code and you cannot tell them what it is.** If they
have lost it, the answer is a replacement card (§2.3).

### 3.2 A `hash_mismatch` refusal

The lookup index matched a row and the row's own hash did not. That is not a
customer error — the index is a keyed hash of the same string the bcrypt covers,
so they cannot disagree by accident.

Two causes:

- **The pepper was rotated and the index was rebuilt, but from the wrong
  material.** See §5.
- **Somebody wrote a row by hand**, setting `code_index` without a matching
  `code_hash`.

Both are incidents. `RedemptionFailed` carries the account reference, so you know
which card.

### 3.3 The whole system is refusing everything with `CodePepperMissing`

`GIFT_CARDS_CODE_PEPPER` is empty on at least one application server. It throws
rather than falling back to `''`, deliberately: hashing under the empty string
would work perfectly and switch the guarantee off silently.

Check every server, not the one you are looking at.

---

## 4. Performance

### 4.1 Redemption is slow

Expected: one indexed lookup plus **one bcrypt**, at
`GIFT_CARDS_CODE_HASH_COST`. At cost 10 that is roughly 60ms of deliberate work;
at 12 it is roughly 250ms.

That cost is a **calibration knob**, not a constant. Measure your hardware:

```php
$start = hrtime(true);
password_hash('x', PASSWORD_BCRYPT, ['cost' => 10]);
(hrtime(true) - $start) / 1e6;   // milliseconds
```

Lower it if a till is waiting; raise it if you have imported short legacy codes
and want guessing to be more expensive. Do not remove it: the same verification
runs on a **miss**, against a decoy, and that is what stops the clock from telling
a guesser their code exists.

### 4.2 Reads are slow

`state()` folds the ledger every time it is called, and costs a query unless
`entries` is loaded. `GiftCardQuery` eager-loads on every read it publishes; code
that loops over `GiftCardAccount` models itself must do the same.

`needingReconciliation()` scans and folds in PHP. It carries a `ponytail:` note
naming the ceiling: if a deployment outgrows it, the upgrade is a materialised
flag written by the same code that appends the entry — **not** a second
implementation of the fold in SQL, because two implementations of one piece of
arithmetic is two answers waiting to disagree.

---

## 5. Rotating the pepper

A real operation, so plan it rather than doing it on a Friday.

The pepper keys `code_index`, which is the **lookup key**. Change it and every
existing index stops matching, and every existing card stops redeeming. The
`code_hash` column is unaffected — it depends on no shared secret — which is
exactly why it is there, but it cannot be searched, so it cannot rebuild the
index on its own.

**You cannot re-derive `code_index` under a new pepper, because you do not have
the codes.** That is the design working as intended, and it means rotation is
not a background job. The options are:

- **Do not rotate.** Treat the pepper like a signing key: long, random, in a
  secret manager, backed up. This is the right answer almost always.
- **Rotate as a re-issue.** Mint new codes for every live card under the new
  pepper, move each balance across with the §2.3 procedure, and email the
  customers. Same shape as `docs/adoption.md` §1 Option A.

If the pepper has actually leaked, the second is what you have to do anyway,
because the leak means somebody can test a candidate code against your index
offline.

**Losing the pepper with no backup makes every card unredeemable.** Back it up.

---

## 6. Telemetry

Off by default.

```dotenv
GIFT_CARDS_TELEMETRY=true
GIFT_CARDS_TELEMETRY_CHANNEL=       # blank for the default channel
```

| Message | Level |
| --- | --- |
| `gift-card.issued` / `.redeemed` / `.credited` | `info` |
| `gift-card.adjusted` | `warning` — a human moved a balance by hand |
| `gift-card.redemption-refused` | `warning` |
| `gift-card.disabled` | `error` |
| anything on an account needing reconciliation | `error` |

**No code is ever written, in any form** — not the code, not the normalised code,
not the lookup index, not the hash, and not even the last four on the refusal
path. `TelemetryTest` asserts that rather than trusting the docblock. The pepper
is never written either.

Nothing here is exclusive: everything the logger writes is a domain event any
listener can subscribe to.

---

## 7. The append-only guarantee, and its one hole

Three layers:

| Layer | Closes |
| --- | --- |
| `GiftCardEntry::booted()` throws on `updating` / `deleting` | Every instance write, including from a job, a console command or a `Model::unguarded()` block |
| `LedgerBuilder` throws on `update` / `delete` / `upsert` / `forceDelete` | Mass operations, which fire **no** Eloquent events at all |
| `GiftCardEntryPolicy` refuses every write ability by name | Any panel offering a button |

**The hole:** `DB::table('ecommerce_gift_card_entries')->update(...)` bypasses the
model entirely, and nothing in PHP can stop it. A test asserts that the hole is
open rather than pretending otherwise.

A deployment that wants the guarantee at the storage layer:

```sql
-- Postgres / MySQL: revoke it from the application's role.
REVOKE UPDATE, DELETE ON ecommerce_gift_card_entries FROM app_user;
```

…or a `BEFORE UPDATE` trigger that raises. Laravel's schema builder has no
portable way to declare one across SQLite, MySQL and Postgres, which is why this
package ships neither.

Between "no guard" and "a guard with a documented edge", the second is worth
having, and pretending otherwise is how a guarantee becomes a slogan.

---

## 8. Things that are not incidents

- **An expired card reporting a full balance.** That is the expiry decision. The
  money never went anywhere.
- **A card that cannot be re-enabled.** By design. §2.4.
- **A redemption refused with no useful message.** By design. §1.1.
- **An idempotent replay returning `recorded: false` and no code.** By design.
- **`LedgerInFlight`.** Transient. The caller retries.
- **An account with no `team_id` invisible to everybody.** Correct, and it is why
  the scope refuses a null rather than translating it into `is null`.
- **Store credit and gift cards in the same table.** Deliberate. One ledger, one
  fold.
