<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Data;

use Illuminate\Support\Collection;
use JsonSerializable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;

/**
 * A gift card or a store credit account, as anything outside this module may see
 * it — including its folded state.
 *
 * ### What is deliberately not on it
 *
 * **The code.** Not the code, not the normalised form, not the lookup index, not
 * the hash. This is the type that goes into events, into telemetry, into a
 * `toArray()` a surface renders and an API serialises, and a bearer credential
 * that reaches any of those has stopped being a credential.
 *
 * The full code exists exactly once, on `IssueResult`, on the single call that
 * minted it. After that there is `lastFour`, which is what a receipt and a support
 * screen show, and nothing else.
 *
 * The **balance is on `$state`**, folded, and there is no column behind it.
 */
final readonly class AccountData implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $reference,
        public AccountKind $kind,
        public AccountState $state,
        public ?string $lastFour = null,
        public ?int $customerId = null,
        public ?int $teamId = null,
        public ?string $sourceReference = null,
        public ?string $expiresAt = null,
        public ?string $issuedAt = null,
    ) {}

    public static function from(GiftCardAccount $account): self
    {
        return new self(
            id: $account->id,
            reference: $account->reference,
            kind: $account->kind,
            state: $account->state(),
            lastFour: $account->last_four,
            customerId: $account->customer_id,
            teamId: $account->team_id,
            sourceReference: $account->source_reference,
            expiresAt: $account->expires_at?->toIso8601String(),
            issuedAt: $account->created_at?->toIso8601String(),
        );
    }

    /**
     * The ledger as read models, for a surface showing somebody what happened.
     *
     * Separate from `from()` because most callers want the state and not the
     * rows, and building forty read models to answer "what is this worth" is
     * work nobody asked for.
     *
     * @return Collection<int, LedgerEntryData>
     */
    public static function entriesOf(GiftCardAccount $account): Collection
    {
        return $account->entries()->get()->map(fn (GiftCardEntry $entry): LedgerEntryData => LedgerEntryData::from($entry));
    }

    /**
     * The display form of the code, which is four characters and some asterisks.
     *
     * Null for store credit, which never had one.
     */
    public function maskedCode(): ?string
    {
        return $this->lastFour === null ? null : str_repeat('*', 16).$this->lastFour;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'kind' => $this->kind->value,
            'masked_code' => $this->maskedCode(),
            'last_four' => $this->lastFour,
            'customer_id' => $this->customerId,
            'team_id' => $this->teamId,
            'source_reference' => $this->sourceReference,
            'expires_at' => $this->expiresAt,
            'issued_at' => $this->issuedAt,
            'state' => $this->state->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
