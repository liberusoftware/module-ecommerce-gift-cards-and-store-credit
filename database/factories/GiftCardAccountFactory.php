<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Support\Code;

/**
 * @extends Factory<GiftCardAccount>
 */
class GiftCardAccountFactory extends Factory
{
    protected $model = GiftCardAccount::class;

    private static int $sequence = 0;

    /**
     * A card with **no code at all** by default.
     *
     * That is not laziness in the fixture: most of this suite is about the
     * ledger, and a factory that minted a code for every account would run a
     * bcrypt per fixture row for a value nothing was going to present. Tests that
     * redeem use `bearing()`, which is the one place a fixture knows a code — and
     * a state that has to be asked for is a state somebody notices.
     *
     * There is no `balance` here because there is no balance column. An account
     * built by this factory is worth nothing until entries exist, which is the
     * honest answer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = static::$sequence++;

        return [
            'reference' => GiftCardAccount::generateReference(),
            'kind' => AccountKind::GiftCard,
            'customer_id' => 9_000_055,
            'team_id' => 9_000_007,
            // No default currency anywhere in this module, including here. GBP is
            // a choice this fixture makes, visibly, on every row.
            'currency' => 'GBP',
            'currency_exponent' => 2,
            'issue_key' => 'issue-fixture-'.$sequence,
            'issue_hash' => hash('sha256', 'issue-fixture-'.$sequence),
        ];
    }

    /** A card that a given code opens. The only fixture that knows one. */
    public function bearing(string $code): static
    {
        $normalised = Code::normalise($code);

        return $this->state(fn (): array => [
            'code_index' => Code::index($normalised),
            'code_hash' => Code::hash($normalised),
            'last_four' => Code::lastFour($normalised),
        ]);
    }

    public function storeCredit(): static
    {
        return $this->state(fn (): array => [
            'kind' => AccountKind::StoreCredit,
            'code_index' => null,
            'code_hash' => null,
            'last_four' => null,
        ]);
    }

    public function ofTeam(?int $teamId): static
    {
        return $this->state(fn (): array => ['team_id' => $teamId]);
    }

    public function in(string $currency, int $exponent = 2): static
    {
        return $this->state(fn (): array => ['currency' => $currency, 'currency_exponent' => $exponent]);
    }

    public function expiringAt(?string $moment): static
    {
        return $this->state(fn (): array => ['expires_at' => $moment]);
    }
}
