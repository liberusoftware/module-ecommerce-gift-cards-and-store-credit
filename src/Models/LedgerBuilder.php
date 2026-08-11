<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Models;

use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Exceptions\LedgerIsAppendOnly;

/**
 * The query builder `GiftCardEntry` uses, which refuses to rewrite the ledger.
 *
 * ### Why the model events are not enough on their own
 *
 * `GiftCardEntry::booted()` throws on `updating` and `deleting`, and that catches
 * every *instance* write — `$entry->update()`, `$entry->save()`, `$entry->delete()`.
 * It does **not** catch a mass operation: `Model::query()->update([...])` and
 * `Model::query()->delete()` go straight to the base query builder and fire no
 * Eloquent events at all. That is documented Laravel behaviour and it is exactly
 * the shape somebody reaches for after `$entry->update()` has thrown at them.
 *
 * So the same refusal is made here, where a mass operation actually passes.
 *
 * ### The ceiling, named rather than implied
 *
 * ponytail: this closes the Eloquent paths. `DB::table('ecommerce_gift_card_entries')
 * ->update(...)` bypasses the model entirely and nothing in PHP can stop it —
 * only a database trigger could, and Laravel's schema builder has no portable way
 * to declare one across SQLite, MySQL and Postgres. A deployment that wants the
 * guarantee at the storage layer writes a trigger or revokes `UPDATE` and
 * `DELETE` on this table from the application's role; `docs/runbook.md` says so.
 * Between "no guard" and "a guard with a documented edge", the second is worth
 * having, and pretending otherwise is how a guarantee becomes a slogan.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class LedgerBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw LedgerIsAppendOnly::update();
    }

    public function delete(): mixed
    {
        throw LedgerIsAppendOnly::delete();
    }

    public function forceDelete(): mixed
    {
        throw LedgerIsAppendOnly::delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        // An upsert is an insert that may become an update, which is half a
        // rewrite. `insert()` is deliberately left alone: appending is the one
        // thing this table is for.
        throw LedgerIsAppendOnly::update();
    }
}
