<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\IssueInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\IssueResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerEntryData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardIssued;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\InvalidMoney;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerConflict;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerInFlight;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Support\Code;

/**
 * **Bring a balance into existence — and hand the code back exactly once.**
 *
 * One action for both kinds, which is the "same ledger, different issue path"
 * decision made visible: everything below is shared except four lines that mint a
 * code for a gift card and do not for store credit.
 *
 * ## The code
 *
 * `Code::mint()` draws twenty Crockford base32 characters from the CSPRNG — a
 * hundred bits — and this method returns it on `IssueResult::$code`. That is the
 * only time it exists outside the caller's own memory.
 *
 * What is written is `code_index` (a keyed HMAC, unique, so redemption is one
 * query) and `code_hash` (a per-row bcrypt). Neither can be turned back into the
 * code, `SchemaTest` asserts that no column *could* hold one, and there is no
 * method anywhere in this package that returns a code for an account that already
 * exists. If a caller drops it, the card is unspendable and the remedy is to
 * issue another and disable this one.
 *
 * Neither column is `$fillable`; they are assigned on the model directly, here,
 * on the one path entitled to know a code.
 *
 * ## The currency has no default
 *
 * It comes off `$input->amount`, which is a `Money`, which requires one. A card
 * is denominated in the currency it was sold in — the host's
 * `->default('USD')` is the `default(1)` mistake wave 2 spent a wave unpicking.
 *
 * ## Two rows, one transaction
 *
 * The account and its `issued` entry are written together or not at all. The
 * host's equivalent is `$this->balance -= $amount; $this->save();` followed by a
 * separate `transactions()->create()`, with nothing around them.
 *
 * The entry key is **derived** (`{issueKey}:issued`) rather than reusing the
 * issue key, so the account index and the entry index stay independent and a
 * later movement using the same caller string does not collide with the issue.
 */
final class IssueAccount
{
    use AppendsToLedger;

    public function handle(IssueInput $input): IssueResult
    {
        if (! $input->amount->isPositive()) {
            throw InvalidMoney::notPositive('gift card or store credit issue', $input->amount->minor);
        }

        // Store credit belongs to somebody by definition: it is settled against a
        // customer rather than against a credential, so without one there is no
        // way to ever redeem it. A gift card is the other way round — a bearer
        // card need belong to nobody until it is spent.
        if (! $input->kind->isBearer() && $input->customerId === null) {
            throw new InvalidArgumentException('Store credit must name a customer. It is redeemed because the caller knows who is asking, not because somebody is holding a code, so credit with no customer could never be spent.');
        }

        $hash = $this->hashOf([
            'kind' => $input->kind->value,
            'minor' => $input->amount->minor,
            'currency' => $input->amount->currency,
            'exponent' => $input->amount->exponent,
            'customer_id' => $input->customerId,
            'team_id' => $input->teamId,
            'source_reference' => $input->sourceReference,
        ]);

        $existing = GiftCardAccount::query()->where('issue_key', $input->issueKey)->first();

        if ($existing !== null) {
            return $this->replayIssue($existing, $hash, $input->issueKey);
        }

        // Minted before the transaction and held only in this frame. A gift card
        // gets one; store credit gets null, and every code column stays null with
        // it.
        $code = $input->kind->isBearer() ? Code::mint() : null;
        $normalised = $code === null ? null : Code::normalise($code);

        try {
            $account = DB::transaction(function () use ($input, $hash, $normalised): GiftCardAccount {
                $account = new GiftCardAccount();

                $account->fill([
                    'reference' => GiftCardAccount::generateReference(),
                    'kind' => $input->kind,
                    'last_four' => $normalised === null ? null : Code::lastFour($normalised),
                    'customer_id' => $input->customerId,
                    'team_id' => $input->teamId,
                    'source_reference' => $input->sourceReference,
                    'currency' => $input->amount->currency,
                    'currency_exponent' => $input->amount->exponent,
                    'expires_at' => $input->expiresAt,
                    'issue_key' => $input->issueKey,
                    'issue_hash' => $hash,
                ]);

                // Assigned rather than filled. Neither is mass-assignable, so no
                // array from a request, a factory state or an import can reach
                // them.
                $account->code_index = $normalised === null ? null : Code::index($normalised);
                $account->code_hash = $normalised === null ? null : Code::hash($normalised);

                $account->save();

                $account->entries()->create([
                    'kind' => EntryKind::Issued,
                    'entry_key' => $input->issueKey.':issued',
                    'entry_hash' => $hash,
                    'amount_minor' => $input->amount->minor,
                    'currency' => $input->amount->currency,
                    'currency_exponent' => $input->amount->exponent,
                    'source_reference' => $input->sourceReference,
                    'team_id' => $input->teamId,
                    'recorded_by' => $input->recordedBy,
                    'occurred_at' => now(),
                ]);

                return $account;
            });
        } catch (QueryException $exception) {
            $winner = GiftCardAccount::query()->where('issue_key', $input->issueKey)->first();

            if ($winner === null) {
                if ($this->isUniqueViolation($exception)) {
                    throw LedgerInFlight::issue($input->issueKey);
                }

                throw $exception;
            }

            return $this->replayIssue($winner, $hash, $input->issueKey);
        }

        $result = $this->issueResult($account->refresh(), recorded: true, code: $code);

        GiftCardIssued::dispatch($result->account, $result->entry);

        return $result;
    }

    /**
     * The same key again.
     *
     * The account comes back so a caller can carry on. **The code does not**, and
     * that is not a policy decision — this module could not produce it. See
     * `IssueResult`.
     */
    private function replayIssue(GiftCardAccount $account, string $hash, string $issueKey): IssueResult
    {
        if (! $this->sameFacts($account->issue_hash, $hash)) {
            throw LedgerConflict::issue($issueKey);
        }

        return $this->issueResult($account, recorded: false, code: null);
    }

    private function issueResult(GiftCardAccount $account, bool $recorded, ?string $code): IssueResult
    {
        $account->load('entries');

        /** @var GiftCardEntry $entry */
        $entry = $account->entries->firstWhere('kind', EntryKind::Issued) ?? $account->entries->first();

        return new IssueResult(
            AccountData::from($account),
            LedgerEntryData::from($entry),
            $recorded,
            $code,
        );
    }
}
