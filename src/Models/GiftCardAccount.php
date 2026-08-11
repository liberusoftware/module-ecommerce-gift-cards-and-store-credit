<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\AccountState;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerEntryData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\Money;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Database\Factories\GiftCardAccountFactory;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountStatus;
use RuntimeException;

/**
 * A gift card, or a store credit account. The same thing with different issue
 * paths — see `AccountKind`.
 *
 * **There is no `balance` attribute on this model and no `balance` column behind
 * it.** `state()` folds the ledger every time it is called. That is the module's
 * central decision after the code storage, and the reason it is worth reading the
 * class before using it: a card cannot be *set* to £30, only credited and
 * redeemed, and there is no code path anywhere — action, model, policy, panel —
 * that offers otherwise.
 *
 * The host shows what the alternative costs:
 *
 *     $this->balance -= $amount;
 *     $this->save();
 *     $this->transactions()->create([...]);
 *
 * Two writes, no transaction around them, two sources of truth, and the one
 * anybody reads is the mutable one. A crash between them leaves a card that has
 * been spent with no record of it, or a record with the money still on it, and
 * nothing anywhere says which.
 *
 * ### `state()` is not free, and that is deliberate
 *
 * It costs a query unless the entries are already loaded, and the arithmetic runs
 * each time. Caching it in a column is precisely the thing this module refuses.
 * Eager-load `entries` and the fold is arithmetic over an in-memory collection;
 * `GiftCardQuery` does that for every read it publishes.
 *
 * ### `code_index` and `code_hash` are not `$fillable`
 *
 * Deliberately, so no array from a request, a factory state or an import can ever
 * set them. `IssueAccount` assigns them directly, on the one path that mints a
 * code. They are also `$hidden` — but **`$hidden` is not the guarantee here and
 * nothing in this module relies on it.** The guarantee is that neither column can
 * be turned back into a code; hiding them is hygiene on top of that, for a value
 * that has no business being in a JSON response.
 *
 * @property int $id
 * @property string $reference
 * @property AccountKind $kind
 * @property string|null $code_index
 * @property string|null $code_hash
 * @property string|null $last_four
 * @property int|null $customer_id
 * @property int|null $team_id
 * @property string|null $source_reference
 * @property string $currency
 * @property int $currency_exponent
 * @property CarbonImmutable|null $expires_at
 * @property string $issue_key
 * @property string $issue_hash
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, GiftCardEntry> $entries
 */
class GiftCardAccount extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_gift_card_accounts';

    /**
     * `code_index` and `code_hash` are absent on purpose. Nothing mass-assigns
     * the material behind a bearer credential.
     */
    protected $fillable = [
        'reference', 'kind', 'last_four', 'customer_id', 'team_id',
        'source_reference', 'currency', 'currency_exponent', 'expires_at',
        'issue_key', 'issue_hash',
    ];

    /** Hygiene, not the guarantee. See the class docblock. */
    protected $hidden = ['code_index', 'code_hash'];

    /**
     * Restated from the migration because `create()` does not read a column
     * default back: a freshly created account would otherwise hold `null` for its
     * exponent and every `Money` built from it would fail its own constructor.
     */
    protected $attributes = [
        'currency_exponent' => 2,
    ];

    protected $casts = [
        'kind' => AccountKind::class,
        'customer_id' => 'integer',
        'team_id' => 'integer',
        'currency_exponent' => 'integer',
        'expires_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * A public reference that is not an incrementing id.
     *
     * An id in a URL enumerates every other card, and a reference gets quoted in
     * support tickets and reconciliation exports. Forty-eight bits from the
     * CSPRNG as hex; uniqueness stays the index's job, so a collision is a loud
     * `QueryException` rather than a balance quietly filed under another card's
     * reference.
     *
     * It is **not** a credential. Presenting one redeems nothing — `RedeemByCode`
     * is the only path that debits and it takes a code.
     */
    public static function generateReference(): string
    {
        return 'GC-'.strtoupper(bin2hex(random_bytes(6)));
    }

    /** Bound by reference everywhere, so no id ever reaches a URL. */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * The ledger, oldest first.
     *
     * Ordered for a human reading it. The fold does not depend on the order —
     * that is the commutativity property `AccountState` rests on — but a list of
     * movements shown in the order we happened to write them reads as though
     * something went wrong.
     *
     * @return HasMany<GiftCardEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(GiftCardEntry::class, 'account_id')->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * The owning team, resolved from configuration at call time and never
     * imported. Throws rather than guessing.
     *
     * @return BelongsTo<Model, $this>
     */
    public function team(): BelongsTo
    {
        $model = config('gift-cards.team_model');

        if (! is_string($model) || $model === '') {
            throw new RuntimeException('No team model is configured. Set `gift-cards.team_model` before loading the `team` relation.');
        }

        return $this->belongsTo($model, 'team_id');
    }

    /**
     * Whether redeemability has ended.
     *
     * **Not whether the money has gone.** Nothing here or anywhere else in this
     * module zeroes a balance on expiry: the ledger is untouched, `balance()`
     * answers the same number it did yesterday, and a credit onto an expired card
     * still lands. Only redemption is refused.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * **The fold.** Everything this account reports about itself comes from here.
     *
     * Uses the loaded relation when there is one, so a caller that eager-loaded
     * `entries` pays nothing extra, and a caller that did not gets a query rather
     * than a wrong answer.
     */
    public function state(): AccountState
    {
        $entries = $this->relationLoaded('entries') ? $this->entries : $this->entries()->get();

        return AccountState::fold(
            $this->currency,
            $this->currency_exponent,
            $this->hasExpired(),
            $entries->map(fn (GiftCardEntry $entry): LedgerEntryData => LedgerEntryData::from($entry)),
        );
    }

    public function status(): AccountStatus
    {
        return $this->state()->status();
    }

    public function balance(): Money
    {
        return $this->state()->balance();
    }

    /** @param  Builder<self>  $query */
    public function scopeOfTeam(Builder $query, ?int $teamId): void
    {
        // `where('team_id', null)` compiles to `is null`, which lists exactly the
        // orphan rows a tenancy scope is supposed to hide. Wave 1.5 shipped that
        // bug; this refuses the null rather than translating it.
        $teamId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('team_id', $teamId);
    }

    /** @param  Builder<self>  $query */
    public function scopeForCustomer(Builder $query, int $customerId): void
    {
        $query->where('customer_id', $customerId)->orderBy('created_at');
    }

    protected static function newFactory(): Factory
    {
        return GiftCardAccountFactory::new();
    }
}
