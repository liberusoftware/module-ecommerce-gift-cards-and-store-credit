<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Telemetry;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerEntryData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardAdjusted;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardCredited;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardDisabled;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardIssued;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\GiftCardRedeemed;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Events\RedemptionFailed;
use Psr\Log\LoggerInterface;

/**
 * This module's own domain events, written as structured records.
 *
 * A listener, not an instrumentation layer: no metrics client, no tracer, no
 * second logging stack. What it adds is the vocabulary a foundation cannot
 * supply — an application's log has no idea that fifty `unknown` refusals against
 * one throttle key in a minute is somebody working through a code space.
 *
 * **Off by default.** A package that starts filling a deployment's log the moment
 * it installs has decided somebody else's retention bill.
 *
 * ## What is never written here
 *
 * A log is the store in an application with the loosest access control and the
 * longest reach, and this is the module that must not put anything in it.
 *
 * - **No code.** Not the code, not the normalised code, not the lookup index, not
 *   the hash. It is not on `AccountData` at all, so this class could not write it
 *   if it tried — which is the point of leaving it off the read model rather than
 *   remembering not to log it.
 * - **No pepper.** It is configuration, it is not in the schema, and nothing here
 *   reads it.
 * - **Not even the last four**, on the refusal path. Four characters plus a
 *   throttle key plus a timestamp is most of a code's identity handed to whoever
 *   reads logs, at exactly the moment somebody is trying to guess one.
 *
 * What is written is an account reference, an amount, a kind, a status and a
 * refusal reason. Enough to alert on, useless to anybody who should not have it.
 * `TelemetryTest` asserts the absences rather than trusting this docblock.
 *
 * Levels carry meaning so an alert needs no message parsing: a refused redemption
 * and an adjustment are `warning`, a disable and a balance needing reconciliation
 * are `error`, everything else is `info`.
 */
final class DomainEventLogger
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            GiftCardIssued::class => 'onIssued',
            GiftCardRedeemed::class => 'onRedeemed',
            GiftCardCredited::class => 'onCredited',
            GiftCardAdjusted::class => 'onAdjusted',
            GiftCardDisabled::class => 'onDisabled',
            RedemptionFailed::class => 'onRefused',
        ];
    }

    public function onIssued(GiftCardIssued $event): void
    {
        $this->record('gift-card.issued', $event->account, $event->entry);
    }

    public function onRedeemed(GiftCardRedeemed $event): void
    {
        $this->record('gift-card.redeemed', $event->account, $event->entry);
    }

    public function onCredited(GiftCardCredited $event): void
    {
        $this->record('gift-card.credited', $event->account, $event->entry);
    }

    public function onAdjusted(GiftCardAdjusted $event): void
    {
        $this->record('gift-card.adjusted', $event->account, $event->entry, 'warning');
    }

    public function onDisabled(GiftCardDisabled $event): void
    {
        $this->record('gift-card.disabled', $event->account, $event->entry, 'error');
    }

    /**
     * **The line an enumeration attempt shows up on.**
     *
     * A burst of `unknown` against one throttle key is somebody working through a
     * code space; a burst of `expired` across many keys is a marketing problem.
     * Telling them apart is the whole reason the reason exists at all, given that
     * the bearer is told nothing.
     */
    public function onRefused(RedemptionFailed $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->logger()->warning('gift-card.redemption-refused', [
            'reason' => $event->reason->value,
            'throttle_key' => $event->throttleKey,
            'account' => $event->accountReference,
            'source_reference' => $event->sourceReference,
        ]);
    }

    private function record(string $message, AccountData $account, LedgerEntryData $entry, string $level = 'info'): void
    {
        if (! $this->enabled()) {
            return;
        }

        // A balance that is arithmetically impossible is louder than whatever it
        // was doing at the time.
        if ($account->state->needsReconciliation()) {
            $level = 'error';
        }

        $this->logger()->log($level, $message, [
            'account' => $account->reference,
            'kind' => $account->kind->value,
            'team_id' => $account->teamId,
            'entry_kind' => $entry->kind->value,
            'origin' => $entry->origin?->value,
            'reason_code' => $entry->reasonCode,
            'amount_minor' => $entry->amount->minor,
            'currency' => $entry->amount->currency,
            'balance_minor' => $account->state->balanceMinor(),
            'status' => $account->state->status()->value,
            'needs_reconciliation' => $account->state->needsReconciliation(),
        ]);
    }

    private function enabled(): bool
    {
        return config('gift-cards.telemetry.enabled') === true;
    }

    private function logger(): LoggerInterface
    {
        $channel = config('gift-cards.telemetry.channel');

        return is_string($channel) && $channel !== '' ? Log::channel($channel) : Log::getFacadeRoot();
    }
}
