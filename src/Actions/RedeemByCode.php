<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions;

use InvalidArgumentException;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountState;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\RedemptionInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountStatus;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\RefusalReason;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardRedeemed;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\RedemptionFailed;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\RedemptionRefused;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Support\AttemptLimit;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Support\Code;

/**
 * **The bearer path. Somebody presents a code and asks for an amount.**
 *
 * The only path in this package that debits a balance, and the only one that
 * accepts a code. Everything else is addressed by reference and sits behind a
 * policy.
 *
 * ## Every refusal answers identically
 *
 * There is one exception class, `RedemptionRefused`, with one constant message,
 * whether the code is unknown, expired, disabled, short of funds, in the wrong
 * currency or throttled. A bearer told "that card has expired" has learned that
 * the code is real; a bearer told "insufficient balance" has learned that and
 * roughly what is on it. Three of the six answers are worse than "unknown" and
 * one is worse again.
 *
 * `->reason` carries the truth for the operator, `RedemptionFailed` carries it to
 * a listener, and telemetry writes it. **A surface that shows `->reason` to a
 * bearer has undone this control.**
 *
 * ## And so does the timing
 *
 * A message oracle is no use if a miss returns in 1ms and a hit in 60ms. So the
 * lookup performs **exactly one password verification either way** — against the
 * row's hash when there is one, against a decoy at the same cost when there is
 * not. See `Code::verify()`.
 *
 * ## Rate limiting, per presenter, required
 *
 * `throttleKey` is the caller's and this action refuses an empty one. A hundred
 * bits does not need it; an adopter importing eight-character codes from a legacy
 * system does, and inherits it rather than discovering they needed it. A
 * successful redemption clears the counter, so a customer who mistypes four times
 * and then gets it right is not locked out by their own card.
 *
 * ## Partial redemption leaves the remainder where it is
 *
 * A £50 card meeting a £30 basket is debited £30 and is worth £20. **No new card
 * is issued and nothing is reissued** — a balance folded from a ledger has a
 * remainder by construction, and minting a second card for it would mean a second
 * code, a second credential and a second thing to lose. The host's model already
 * worked this way; it is written down here because it is a decision and not an
 * accident.
 *
 * ## The debit happens now. There is no reservation
 *
 * A gift card is not authorized and later captured. That split exists in Payment
 * Operations because a card network holds a reservation on a customer's money for
 * a merchant; here the money is already the merchant's own liability and there is
 * nobody to hold it with. A two-phase hold would need an expiry, an expiry needs
 * a sweeper, and a sweeper that stops running leaves a customer's balance locked
 * with no third party to complain to.
 *
 * So the ledger is debited when the money moves, which is what makes the
 * `reference` Checkout records against its tender a fact rather than a promise —
 * wave 4 decided a gift card is *an amount and a reference*, and this is the
 * movement that reference names. If what it paid for falls over afterwards, the
 * remedy is `RecordCredit` with `CreditOrigin::Reversal`: a new entry, carrying
 * the reference of the redemption it undoes, never a deletion.
 */
final class RedeemByCode
{
    use AppendsToLedger;

    /**
     * The account a refusal was about, when the code found one.
     *
     * Held on the instance rather than threaded through the refusal, because it
     * must never be reachable from `RedemptionRefused` — that object is what a
     * surface renders, and a reference on it is one `{$e->reference}` away from
     * telling a guesser their code was real.
     */
    private ?string $refusedReference = null;

    public function handle(RedemptionInput $input): LedgerResult
    {
        if (trim($input->throttleKey) === '') {
            // Refused rather than defaulted. A limiter keyed on `''` throttles
            // every customer in the world against one counter, and a limiter
            // skipped is a box somebody has already ticked.
            throw new InvalidArgumentException('A redemption needs a throttle key naming the presenter — an IP, a session, a customer, a till. This package cannot see a request and will not guess one.');
        }

        try {
            $result = $this->redeem($input);
        } catch (RedemptionRefused $refusal) {
            // Not counted against a presenter who is already being told to wait:
            // extending their own window every time they ask would turn a
            // one-minute lockout into an unbounded one.
            if ($refusal->reason !== RefusalReason::Throttled) {
                AttemptLimit::hit($input->throttleKey);
            }

            RedemptionFailed::dispatch(
                $refusal->reason,
                $input->throttleKey,
                $this->refusedReference,
                $input->sourceReference,
            );

            throw $refusal;
        }

        AttemptLimit::clear($input->throttleKey);

        if ($result->recorded) {
            GiftCardRedeemed::dispatch($result->account, $result->entry);
        }

        return $result;
    }

    private function redeem(RedemptionInput $input): LedgerResult
    {
        $this->refusedReference = null;

        if (AttemptLimit::tooMany($input->throttleKey)) {
            throw RedemptionRefused::because(RefusalReason::Throttled);
        }

        // About the request, not about the card, so answering it before the
        // lookup gives a guesser nothing: it refuses identically for every code.
        if (! $input->amount->isPositive()) {
            throw RedemptionRefused::because(RefusalReason::InvalidAmount);
        }

        $account = $this->resolve($input->code);

        $this->refusedReference = $account->reference;

        $hash = $this->hashOf([
            'account' => $account->reference,
            'minor' => $input->amount->minor,
            'currency' => $input->amount->currency,
            'exponent' => $input->amount->exponent,
            'source_reference' => $input->sourceReference,
        ]);

        return $this->appendEntry($account, $input->entryKey, $hash, function (GiftCardAccount $locked, AccountState $state) use ($input): array {
            // **Every check below runs against a state folded from the database,
            // inside the transaction, under the account's row lock.** Two tills
            // racing for the last £20 both pass a check made against what they
            // loaded; exactly one passes this.
            if (! $state->isRedeemable()) {
                throw RedemptionRefused::because(match ($state->status()) {
                    AccountStatus::Disabled => RefusalReason::Disabled,
                    AccountStatus::Expired => RefusalReason::Expired,
                    default => RefusalReason::InsufficientBalance,
                });
            }

            if ($input->amount->currency !== $locked->currency) {
                // Refused, never converted. Picking a rate on a merchant's behalf
                // is a decision they would find out about at the end of the month.
                throw RedemptionRefused::because(RefusalReason::CurrencyMismatch);
            }

            if ($input->amount->minor > $state->balanceMinor()) {
                // Refused, never clamped. Debiting what is there and dropping the
                // difference turns a loud failure into a basket that is short by
                // an amount nobody is told about.
                throw RedemptionRefused::because(RefusalReason::InsufficientBalance);
            }

            return [
                'kind' => EntryKind::Redeemed,
                'amount_minor' => $input->amount->minor,
                'currency' => $input->amount->currency,
                'currency_exponent' => $input->amount->exponent,
                'source_reference' => $input->sourceReference,
                'recorded_by' => $input->recordedBy,
            ];
        });
    }

    /**
     * Code to account, in one indexed query and one password verification —
     * **whether or not it finds anything.**
     */
    private function resolve(string $presented): GiftCardAccount
    {
        $normalised = Code::normalise($presented);

        $account = Code::isWellFormed($normalised)
            ? GiftCardAccount::query()->where('code_index', Code::index($normalised))->first()
            : null;

        // Runs on both paths. On a miss it verifies against a decoy at the
        // configured cost, so a miss costs what a hit costs and the timing
        // channel the constant message closes is not reopened underneath it.
        $verified = Code::verify($normalised, $account?->code_hash);

        if ($account === null) {
            throw RedemptionRefused::because(RefusalReason::Unknown);
        }

        if (! $verified) {
            // The index matched and the hash did not. Either the pepper has been
            // rotated without re-indexing, or somebody has written a row by hand.
            // `docs/runbook.md` names both.
            $this->refusedReference = $account->reference;

            throw RedemptionRefused::because(RefusalReason::HashMismatch);
        }

        return $account;
    }
}
