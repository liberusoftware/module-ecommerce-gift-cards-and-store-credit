<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;

/**
 * @extends Factory<GiftCardEntry>
 */
class GiftCardEntryFactory extends Factory
{
    protected $model = GiftCardEntry::class;

    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = static::$sequence++;

        return [
            'account_id' => GiftCardAccount::factory(),
            'kind' => EntryKind::Issued,
            'entry_key' => 'entry-fixture-'.$sequence,
            'entry_hash' => hash('sha256', 'entry-fixture-'.$sequence),
            'amount_minor' => 5000,
            'currency' => 'GBP',
            'currency_exponent' => 2,
            'team_id' => 9_000_007,
            'occurred_at' => now(),
        ];
    }

    public function of(EntryKind $kind, int $minor, string $currency = 'GBP', int $exponent = 2): static
    {
        return $this->state(fn (): array => [
            'kind' => $kind,
            'amount_minor' => $minor,
            'currency' => $currency,
            'currency_exponent' => $exponent,
        ]);
    }

    public function forAccount(GiftCardAccount $account): static
    {
        return $this->state(fn (): array => [
            'account_id' => $account->id,
            'currency' => $account->currency,
            'currency_exponent' => $account->currency_exponent,
            'team_id' => $account->team_id,
        ]);
    }
}
