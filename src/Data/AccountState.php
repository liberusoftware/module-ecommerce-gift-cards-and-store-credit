<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Data;

use JsonSerializable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountStatus;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;

/**
 * **The fold. Everything this module reports about a balance comes from here.**
 *
 * There is no `balance` column and no cached total anywhere in this module's
 * schema. What a card is worth, and whether it may be spent, are computed from
 * `ecommerce_gift_card_entries` every time they are asked for.
 *
 * The host is the argument. `gift_cards.balance` is a `decimal` column sitting
 * beside a `gift_card_transactions` table, mutated by `$this->balance -= $amount;
 * $this->save();` and then a `transactions()->create()` — two writes, two sources
 * of truth, no transaction around them, and the one anybody reads is the mutable
 * one. When they disagree the money is whatever the column says, and nothing
 * anywhere records which is right.
 *
 * A derived balance cannot disagree with the entries that produced it, because it
 * *is* those entries.
 *
 * ## The derivation is total, and here is why
 *
 * "Total" means every sequence of entries the schema admits has exactly one
 * answer, and producing it cannot throw. Three properties, each mechanical, each
 * with a test in `tests/FoldTest.php`.
 *
 * ### 1. Every entry kind is handled, and the guarantee is the `match`
 *
 * `fold()` dispatches on `EntryKind` with a `match` that lists every case and has
 * **no `default` arm**. Adding a case to the enum without adding an arm makes PHP
 * raise `UnhandledMatchError` the first time that kind is folded — and `FoldTest`
 * folds a ledger containing *every* case of `EntryKind::cases()`, so the failure
 * lands in CI rather than in somebody's balance. A `default` arm would have turned
 * that into a silent zero, which on a gift card is money that disappears.
 *
 * ### 2. The fold is commutative, so order cannot change the answer
 *
 * Every contribution is either a **sum** (`+=`) or a **flag** (`= true`), and
 * both are commutative and associative. Folding the same set of entries in any
 * order gives a bit-identical state, and `FoldTest` proves it over every
 * permutation of several ledgers.
 *
 * That property is why `EntryKind` has no `Enabled` case: disable-then-enable and
 * enable-then-disable are different facts, and a fold able to tell them apart is
 * a fold that depends on order. Disabling is terminal instead, and the recovery
 * is a replacement card.
 *
 * ### 3. `status()` is a cascade ending in an unconditional return
 *
 * Four branches over one integer and two booleans, the last of them
 * unconditional. There is no input for which it falls off the end, and `FoldTest`
 * enumerates every sequence of kinds up to length three — 156 of them, the empty
 * ledger included — against both expiry values, asserting each yields an
 * `AccountStatus` and never raises.
 *
 * ## Expiry is an input, exactly as currency is
 *
 * `fold()` takes `$expired` alongside `$currency` and `$exponent`. All three are
 * facts about the **account** rather than about the ledger, and putting expiry
 * here rather than leaving it to a caller means there is exactly one place that
 * decides whether a card may be spent. A second place would eventually answer
 * differently.
 *
 * **An expired card keeps every penny.** Nothing is zeroed, no entry is written,
 * the ledger does not change, and `balance()` reports the same number it did the
 * day before. The status changes and redemption is refused. Many jurisdictions
 * regulate or forbid expiry outright; this module does not know the law and must
 * not act as though the money were gone.
 *
 * ## What it does not do: clamp
 *
 * A negative balance is arithmetically impossible for anything this module writes
 * — every debit is guarded under a row lock. If one exists anyway, from a raw
 * `DB::table()` write, a bad import or a restore, this class reports it through
 * `needsReconciliation()` rather than flooring it into something tidy. A wrong
 * number nobody is told about is the worst outcome available to a module whose
 * job is holding somebody's money.
 *
 * Same for an entry in another currency: excluded from the sums rather than added
 * to unlike units, and counted, so the fold stays total without the totals
 * becoming lies.
 */
final readonly class AccountState implements JsonSerializable
{
    public function __construct(
        public string $currency,
        public int $exponent,
        public bool $expired,
        public int $issuedMinor,
        public int $creditedMinor,
        public int $redeemedMinor,
        public int $adjustedMinor,
        public bool $disabled,
        public int $mismatchedCurrencyEntries = 0,
    ) {}

    /**
     * Fold a ledger into a state.
     *
     * The currency, the exponent and the expiry all come from the account rather
     * than from the entries, because an empty ledger still has all three — an
     * account row that exists with nothing against it is worth zero of a real
     * currency, not nothing at all.
     *
     * @param  iterable<LedgerEntryData>  $entries
     */
    public static function fold(string $currency, int $exponent, bool $expired, iterable $entries): self
    {
        $issued = 0;
        $credited = 0;
        $redeemed = 0;
        $adjusted = 0;
        $disabled = false;
        $mismatched = 0;

        foreach ($entries as $entry) {
            // Unlike units are never added. The write path refuses them; this is
            // what happens if one exists anyway, and it keeps the fold total
            // without making the totals mean something they do not.
            if ($entry->amount->currency !== $currency) {
                $mismatched++;

                continue;
            }

            $minor = $entry->amount->minor;

            // No `default` arm, deliberately. A new `EntryKind` without an arm
            // here raises `UnhandledMatchError` the first time it is folded, and
            // `FoldTest` folds every case, so the build is what finds out rather
            // than a customer whose card is suddenly worth less.
            match ($entry->kind) {
                EntryKind::Issued => $issued += $minor,
                EntryKind::Credited => $credited += $minor,
                EntryKind::Redeemed => $redeemed += $minor,
                EntryKind::Adjusted => $adjusted += $minor,
                EntryKind::Disabled => $disabled = true,
            };
        }

        return new self(
            currency: $currency,
            exponent: $exponent,
            expired: $expired,
            issuedMinor: $issued,
            creditedMinor: $credited,
            redeemedMinor: $redeemed,
            adjustedMinor: $adjusted,
            disabled: $disabled,
            mismatchedCurrencyEntries: $mismatched,
        );
    }

    /**
     * What is left on the card.
     *
     * **Not floored at zero.** A floor here would hide the one condition that
     * cannot happen and therefore matters most when it does — see
     * `needsReconciliation()`. Callers deciding whether something is spendable ask
     * `isRedeemable()`, which is about the status rather than about the sign.
     */
    public function balanceMinor(): int
    {
        return $this->issuedMinor + $this->creditedMinor + $this->adjustedMinor - $this->redeemedMinor;
    }

    public function balance(): Money
    {
        return new Money($this->balanceMinor(), $this->currency, $this->exponent);
    }

    /** What has ever been put on, ignoring what has been taken off. */
    public function fundedMinor(): int
    {
        return $this->issuedMinor + $this->creditedMinor + $this->adjustedMinor;
    }

    /**
     * The one answer, from the totals.
     *
     * Ordered **most permanent first**, so the status names the thing somebody
     * would actually have to do something about. A card that is both disabled and
     * expired reports `Disabled`, because the expiry is beside the point once it
     * has been stopped.
     *
     * `Expired` sits above `Empty` for the same reason and has the more important
     * consequence: an expired card with £30 on it reports `Expired`, and
     * `balance()` still reports £30. The money did not go anywhere.
     */
    public function status(): AccountStatus
    {
        if ($this->disabled) {
            return AccountStatus::Disabled;
        }

        if ($this->expired) {
            return AccountStatus::Expired;
        }

        if ($this->balanceMinor() <= 0) {
            return AccountStatus::Empty;
        }

        return AccountStatus::Active;
    }

    /**
     * Whether anything may be spent against this right now.
     *
     * Defined in terms of `status()` rather than restating its conditions, so the
     * two cannot drift apart. Everything a redemption checks beyond this — the
     * currency, the requested amount — is about the request rather than about the
     * card.
     */
    public function isRedeemable(): bool
    {
        return $this->status() === AccountStatus::Active;
    }

    /**
     * Whether the ledger says something arithmetically impossible.
     *
     * Not reachable through anything this module writes: every debit is guarded
     * against a state folded from the database under the account's row lock. True
     * here means a raw `DB::table()` write, an import, a restore, or a second
     * writer — and it is a **work queue** rather than an error to throw on.
     * `docs/runbook.md` says what to do with each case.
     */
    public function needsReconciliation(): bool
    {
        return $this->balanceMinor() < 0 || $this->mismatchedCurrencyEntries > 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status()->value,
            'balance' => $this->balance()->toArray(),
            'issued' => (new Money($this->issuedMinor, $this->currency, $this->exponent))->toArray(),
            'credited' => (new Money($this->creditedMinor, $this->currency, $this->exponent))->toArray(),
            'redeemed' => (new Money($this->redeemedMinor, $this->currency, $this->exponent))->toArray(),
            'adjusted' => (new Money($this->adjustedMinor, $this->currency, $this->exponent))->toArray(),
            'expired' => $this->expired,
            'disabled' => $this->disabled,
            'redeemable' => $this->isRedeemable(),
            'needs_reconciliation' => $this->needsReconciliation(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
