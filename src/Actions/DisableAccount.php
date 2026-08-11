<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions;

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountState;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardDisabled;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;

/**
 * **Stop a balance. Terminally.**
 *
 * Reported lost, reported stolen, issued in error, or the card that a
 * `HashMismatch` refusal has just told an operator about.
 *
 * ## There is no `disabled_at` column
 *
 * The host has one, beside a mutable `balance`, set by `$this->disabled_at =
 * now(); $this->save();` — and an `enable()` that sets it back to null with
 * nothing recording that either happened. Here it is a **ledger entry**: the fold
 * sets a flag, `AccountStatus::Disabled` wins the cascade, and the row says when
 * and who.
 *
 * ## There is no way to undo it, and that is a design decision
 *
 * `EntryKind` has no `Enabled` case, because it cannot have one. Every
 * contribution to the fold is a sum or a flag precisely so that folding the
 * ledger in any order gives the same answer — and disable-then-enable and
 * enable-then-disable are different facts. A fold able to tell them apart is a
 * fold that depends on order, and commutativity is load-bearing enough elsewhere
 * that a re-enable is not worth paying for it.
 *
 * So a card disabled by mistake is recovered by **issuing a replacement and
 * moving the balance**: an adjustment off the stopped card, an issue or a credit
 * onto the new one. Two rows, a trail, and a code the customer can actually use —
 * which is what they needed anyway, since the old one is in a thief's hands in
 * every case that is not a mistake. `docs/runbook.md` has the procedure.
 *
 * ## Disabling twice is allowed
 *
 * With a second entry key it writes a second row and the fold gives the same
 * answer, because a flag set twice is set. Refusing would be a guard that buys
 * nothing and one more branch to get wrong.
 */
final class DisableAccount
{
    use AppendsToLedger;

    public function handle(string $accountReference, string $entryKey, string $reasonCode, ?int $recordedBy = null): LedgerResult
    {
        $account = $this->accountByReference($accountReference);

        $hash = $this->hashOf([
            'account' => $account->reference,
            'reason_code' => $reasonCode,
        ]);

        $result = $this->appendEntry($account, $entryKey, $hash, fn (GiftCardAccount $locked, AccountState $state): array => [
            'kind' => EntryKind::Disabled,
            // Zero, in the account's own currency. A disable moves no money, and
            // an entry that claimed otherwise would be counted by the fold.
            'amount_minor' => 0,
            'currency' => $locked->currency,
            'currency_exponent' => $locked->currency_exponent,
            'reason_code' => $reasonCode,
            'recorded_by' => $recordedBy,
        ]);

        if ($result->recorded) {
            GiftCardDisabled::dispatch($result->account, $result->entry);
        }

        return $result;
    }
}
