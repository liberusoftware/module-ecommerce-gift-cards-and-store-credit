# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-11

First release. Replaces the host's `gift_cards` and `gift_card_transactions`
tables and the `GiftCard` and `GiftCardTransaction` models rather than adopting
them — the host's schema stores a bearer credential in plain text beside a
mutable balance in a `decimal` column with a defaulted currency, which is four
faults and three of them are unfixable in place.

### Added

- **A code that cannot be recovered from anything stored.** `code_index` is a
  keyed HMAC-SHA256 under a configured pepper, unique, so redemption is still one
  indexed query; `code_hash` is a per-row bcrypt that stays sound if the pepper
  leaks or is rotated. The full code is returned once, on `IssueResult`, at issue.
  There is no method anywhere that returns a code for an account that already
  exists.
- **`SchemaTest` asserts the guarantee twice.** Thirty-odd plaintext-code column
  names absent from both tables, and then a card issued and every cell of every
  table in the database searched for the code just minted.
- **One refusal, one message.** Every redemption failure — unknown, expired,
  disabled, insufficient, wrong currency, throttled — answers with identical
  bytes, so a guess never confirms a hit. `RefusalReason` travels on the
  exception, on `RedemptionFailed` and in telemetry, for the operator.
- **Uniform-cost lookup.** A miss performs one password verification against a
  decoy at the configured cost, so timing is not the oracle the message is not.
- **Crockford base32, twenty characters, a hundred bits**, drawn uniformly with
  `random_int()`. Normalisation forgives case, spacing and the `O`/`0`, `I`/`1`,
  `L`/`1` confusions.
- **Rate limiting per presenter**, keyed on a string the caller supplies and
  refused if they do not supply one. Cleared on a successful redemption.
- **Balance as a fold over an append-only ledger.** No `balance` column, no
  `status`, no `disabled_at`, no cached totals — asserted absent by name.
- **The fold proved total three ways**: a `match` with no `default` arm, a fold
  over every `EntryKind` case, and all 156 sequences of kinds up to length three
  enumerated against both expiry values.
- **Integer minor units** throughout, with `Money::decimal()` by string
  arithmetic. No `decimal` column, no float anywhere in `src/`.
- **No default currency.** Required at issue, fixed for the life of the balance,
  and a movement in another currency is refused rather than converted.
- **Expiry that ends redeemability and never the money.** Nullable with no
  default, written once, never edited. A credit onto an expired card lands.
- **Store credit as the same ledger with a different issue path**, distinguished
  by `AccountKind` and by one behaviour: a gift card is a bearer credential.
- **Idempotency on every movement**, guaranteed by unique indexes on `issue_key`
  and `entry_key`, with `LedgerConflict` (permanent, `409`) and `LedgerInFlight`
  (transient, `423`) as two classes from day one.
- **A guard folded under the account's row lock**, inside the transaction that
  writes the debit, so a caller holding a stale balance cannot get round it.
- **Append-only in three layers** — model events, `LedgerBuilder`, policy — with
  the one path none of them closes named in `docs/runbook.md` and asserted open
  by a test rather than pretended shut.
- **Every unpublished ability false by name** on both models, including
  Filament's relation-manager abilities and `viewCode` / `revealCode`.
- **Team-scoped reads**, with orphans invisible and a non-numeric team id failing
  closed.
- Domain events, read models, `GiftCardQuery`, and telemetry that cannot write a
  code because no read model carries one.

### Deliberately not included

- **No reservation or two-phase hold.** The money is the merchant's own
  liability and there is no third party to hold it with. A hold needs an expiry,
  an expiry needs a sweeper, and a sweeper that stops leaves a customer's balance
  locked. The debit happens at redemption and a reversal is a new entry.
- **No re-enable.** `EntryKind` has no `Enabled` case, because it would break the
  commutativity the fold rests on. The recovery is a replacement card.
- **No `byCode()` on the query API**, ever. Lookup by code exists to spend a
  card, not to find one.
- **No free-text column** on either table. An adjustment carries a short reason
  code.
- **No escheatment, no promotions, no gateway, no delivery channel.**

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-gift-cards-and-store-credit/releases/tag/0.1.0
