<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions;

use InvalidArgumentException;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountState;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AdjustmentInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardAdjusted;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\InvalidMoney;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;

/**
 * **An operator correcting a balance.** The only path with a signed amount, and
 * the only place a human's decision enters the ledger directly.
 *
 * This exists because the ledger is append-only. There is no way to edit an entry
 * and no way to delete one, so the answer to "that redemption should not have
 * happened" is a **new row** saying so, with a reason code and an actor on it.
 * That is not a workaround for the restriction; it is the reason the restriction
 * is affordable.
 *
 * ## `reasonCode` is required, and it is a code
 *
 * Short, from a vocabulary the deployment defines — `goodwill`, `writeoff`,
 * `correction`, `duplicate`. Never prose: free text beside money is where a
 * customer's email address ends up, which wave 4 found and wave 5 acted on twice.
 * There is no `note` column on either table in this module, so there is nowhere
 * for it to go.
 *
 * ## An adjustment cannot take a balance below zero
 *
 * Refused, under the row lock, against a state folded from the database. Writing
 * off more than is there is not a correction, it is a different mistake — and a
 * negative balance is the one condition `needsReconciliation()` exists to report,
 * so putting one there deliberately would poison the queue.
 *
 * A **disabled** card may still be adjusted. An operator writing off the balance
 * of a card they have just stopped is the ordinary end of that story, and it is
 * the reason this action does not share `RecordCredit`'s refusal.
 */
final class RecordAdjustment
{
    use AppendsToLedger;

    public function handle(AdjustmentInput $input): LedgerResult
    {
        if ($input->amount->isZero()) {
            throw InvalidMoney::zeroAdjustment();
        }

        if (trim($input->reasonCode) === '') {
            throw new InvalidArgumentException('An adjustment needs a reason code. It is the only record of why somebody moved a balance by hand, and a short code is the whole of it — this module has no free-text column to put an explanation in.');
        }

        $account = $this->accountByReference($input->accountReference);

        $hash = $this->hashOf([
            'account' => $account->reference,
            'minor' => $input->amount->minor,
            'currency' => $input->amount->currency,
            'exponent' => $input->amount->exponent,
            'reason_code' => $input->reasonCode,
            'source_reference' => $input->sourceReference,
        ]);

        $result = $this->appendEntry($account, $input->entryKey, $hash, function (GiftCardAccount $locked, AccountState $state) use ($input): array {
            $input->amount->assertSameCurrency($locked->balance());

            if ($state->balanceMinor() + $input->amount->minor < 0) {
                throw InvalidMoney::notPositive('resulting balance after an adjustment', $state->balanceMinor() + $input->amount->minor);
            }

            return [
                'kind' => EntryKind::Adjusted,
                'amount_minor' => $input->amount->minor,
                'currency' => $input->amount->currency,
                'currency_exponent' => $input->amount->exponent,
                'reason_code' => $input->reasonCode,
                'source_reference' => $input->sourceReference,
                'recorded_by' => $input->recordedBy,
            ];
        });

        if ($result->recorded) {
            GiftCardAdjusted::dispatch($result->account, $result->entry);
        }

        return $result;
    }
}
