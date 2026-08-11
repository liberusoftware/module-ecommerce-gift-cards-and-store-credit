<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions;

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountState;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\CreditInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardCredited;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\AccountNotCreditable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\InvalidMoney;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;

/**
 * **Money onto a balance that already exists** — a refund, a reversal, a top-up.
 *
 * Addressed by the account's **reference**, never by a code. Nobody presents a
 * card in order to have money put on it, and a path that took a code here would
 * be a second place a code could be typed, logged and guessed against.
 *
 * The consequence is that this action is only as safe as the surface in front of
 * it: a reference is not a credential — it is in exports and support tickets — so
 * **`RecordCredit` must never be reachable by an unauthenticated caller.**
 * `GiftCardAccountPolicy::credit()` is the gate a panel uses.
 *
 * ## Refunding onto a gift card
 *
 * This is where the two modules of wave 7 meet without either importing the
 * other. A refunds module decides that a customer is owed an amount and that the
 * destination is a gift card reference. It does not call this package; this
 * package does not call it, require it, name it, or know whether it is installed.
 * A listener the host writes takes the amount and the reference off the refund's
 * event and calls this. What crosses is an integer, a currency code and two
 * strings.
 *
 * `docs/adoption.md` has the listener, written out.
 *
 * ## An expired card can still be credited
 *
 * Deliberately, and it is the expiry decision showing up where somebody would
 * otherwise be surprised by it. Expiry ends *redeemability*, not ownership: the
 * balance was never taken away, so a refund onto an expired card lands and the
 * money sits there, visible to staff and to the customer, until somebody decides
 * what to do about it. Zeroing it, or refusing the refund, would both be this
 * module deciding a jurisdiction's law.
 *
 * A **disabled** card is refused. That one is not a legal question: money on a
 * stopped card cannot be spent by anybody, and a refund that lands there looks
 * paid and is not.
 *
 * ## Cross-currency is refused, never converted
 *
 * A refund of €40 against a £50 card has no answer this module is entitled to
 * give. `CurrencyMismatch` says so by name — unlike the bearer path, where the
 * same condition has to answer identically to everything else.
 */
final class RecordCredit
{
    use AppendsToLedger;

    public function handle(CreditInput $input): LedgerResult
    {
        if (! $input->amount->isPositive()) {
            throw InvalidMoney::notPositive('credit', $input->amount->minor);
        }

        $account = $this->accountByReference($input->accountReference);

        $hash = $this->hashOf([
            'account' => $account->reference,
            'minor' => $input->amount->minor,
            'currency' => $input->amount->currency,
            'exponent' => $input->amount->exponent,
            'origin' => $input->origin->value,
            'source_reference' => $input->sourceReference,
        ]);

        $result = $this->appendEntry($account, $input->entryKey, $hash, function (GiftCardAccount $locked, AccountState $state) use ($input): array {
            if ($state->disabled) {
                throw AccountNotCreditable::disabled($locked->reference);
            }

            // Named rather than swallowed. A caller on this path is a listener or
            // an operator, not a bearer, so telling them which currency the card
            // is in helps them and confirms nothing to anybody else.
            $input->amount->assertSameCurrency($locked->balance());

            return [
                'kind' => EntryKind::Credited,
                'amount_minor' => $input->amount->minor,
                'currency' => $input->amount->currency,
                'currency_exponent' => $input->amount->exponent,
                'origin' => $input->origin,
                'source_reference' => $input->sourceReference,
                'recorded_by' => $input->recordedBy,
            ];
        });

        if ($result->recorded) {
            GiftCardCredited::dispatch($result->account, $result->entry);
        }

        return $result;
    }
}
