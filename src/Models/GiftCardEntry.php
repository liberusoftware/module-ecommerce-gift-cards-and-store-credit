<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\Money;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Database\Factories\GiftCardEntryFactory;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\CreditOrigin;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerIsAppendOnly;

/**
 * One row of the ledger. **Append-only, enforced rather than documented.**
 *
 * The `updating` and `deleting` model events throw. That placement is the
 * important part: a policy stops a panel from offering a button, but it does
 * nothing about a queued job, a console command, an observer somebody adds next
 * year, or a `Model::unguarded()` block. Booting the guard onto the model means
 * every write path in the application goes through it, including the ones that do
 * not exist yet.
 *
 * `LedgerBuilder` refuses the mass operations the model events cannot see —
 * `query()->update()` and `query()->delete()` fire no Eloquent events — and
 * `GiftCardEntryPolicy` refuses every write ability by name so no panel offers a
 * button. Three layers, because each alone leaves a door open, and this is the
 * table every balance this module reports is built from.
 *
 * A correction is a **new row**: `RecordAdjustment`, with a reason code and an
 * actor. That is not a workaround for the restriction, it is what a ledger is.
 *
 * @property int $id
 * @property int $account_id
 * @property EntryKind $kind
 * @property string $entry_key
 * @property string $entry_hash
 * @property int $amount_minor
 * @property string $currency
 * @property int $currency_exponent
 * @property CreditOrigin|null $origin
 * @property string|null $source_reference
 * @property string|null $reason_code
 * @property int|null $team_id
 * @property int|null $recorded_by
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GiftCardAccount $account
 */
class GiftCardEntry extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_gift_card_entries';

    protected $fillable = [
        'account_id', 'kind', 'entry_key', 'entry_hash', 'amount_minor',
        'currency', 'currency_exponent', 'origin', 'source_reference',
        'reason_code', 'team_id', 'recorded_by', 'occurred_at',
    ];

    /** Restated from the migration; `create()` does not read a default back. */
    protected $attributes = [
        'currency_exponent' => 2,
    ];

    protected $casts = [
        'kind' => EntryKind::class,
        'origin' => CreditOrigin::class,
        'account_id' => 'integer',
        'amount_minor' => 'integer',
        'currency_exponent' => 'integer',
        'team_id' => 'integer',
        'recorded_by' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * The mass-operation half of the append-only guarantee.
     *
     * Model events do not fire for `query()->update()` or `query()->delete()`, so
     * the refusal is made in the builder too. See `LedgerBuilder`, which also
     * names the one path neither can close.
     *
     * @param  Builder  $query
     * @return LedgerBuilder<$this>
     */
    public function newEloquentBuilder($query): LedgerBuilder
    {
        return new LedgerBuilder($query);
    }

    /**
     * The instance half of the append-only guarantee.
     *
     * `updated_at` exists on the table only because `timestamps()` is the fleet
     * convention; nothing ever changes it, because nothing ever updates a row.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw LedgerIsAppendOnly::update();
        });

        static::deleting(function (): never {
            throw LedgerIsAppendOnly::delete();
        });
    }

    /** @return BelongsTo<GiftCardAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GiftCardAccount::class, 'account_id');
    }

    public function amount(): Money
    {
        return new Money($this->amount_minor, $this->currency, $this->currency_exponent);
    }

    protected static function newFactory(): Factory
    {
        return GiftCardEntryFactory::new();
    }
}
