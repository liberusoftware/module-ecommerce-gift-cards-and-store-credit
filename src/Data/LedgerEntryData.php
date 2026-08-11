<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Data;

use JsonSerializable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\CreditOrigin;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;

/**
 * One thing that happened to a balance, as anything outside this module may see
 * it.
 *
 * Carried by every event this module publishes, so a listener never holds an
 * Eloquent model belonging to a package it does not depend on — and never holds
 * one it could call `save()` on, which for an append-only ledger matters more
 * than usual.
 *
 * **There is no code on it, in any form.** Not the code, not the normalised code,
 * not the lookup index, not the hash. An event goes to every listener a
 * deployment has registered, and several of them will write it somewhere.
 *
 * `occurredAt` is when the movement happened and `recordedAt` is when this module
 * heard about it. They are kept apart because the gap is the interesting number
 * when a reversal lands late.
 */
final readonly class LedgerEntryData implements JsonSerializable
{
    public function __construct(
        public int $id,
        public int $accountId,
        public EntryKind $kind,
        public Money $amount,
        public string $occurredAt,
        public ?CreditOrigin $origin = null,
        public ?string $sourceReference = null,
        public ?string $reasonCode = null,
        public ?int $teamId = null,
        public ?int $recordedBy = null,
        public ?string $recordedAt = null,
    ) {}

    public static function from(GiftCardEntry $entry): self
    {
        return new self(
            id: $entry->id,
            accountId: $entry->account_id,
            kind: $entry->kind,
            amount: new Money($entry->amount_minor, $entry->currency, $entry->currency_exponent),
            occurredAt: $entry->occurred_at->toIso8601String(),
            origin: $entry->origin,
            sourceReference: $entry->source_reference,
            reasonCode: $entry->reason_code,
            teamId: $entry->team_id,
            recordedBy: $entry->recorded_by,
            recordedAt: $entry->created_at?->toIso8601String(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->accountId,
            'kind' => $this->kind->value,
            'amount' => $this->amount->toArray(),
            'origin' => $this->origin?->value,
            'source_reference' => $this->sourceReference,
            'reason_code' => $this->reasonCode,
            'team_id' => $this->teamId,
            'recorded_by' => $this->recordedBy,
            'occurred_at' => $this->occurredAt,
            'recorded_at' => $this->recordedAt,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
