<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;

/**
 * A ledger row is read, and nothing else.
 *
 * There is no yes-able write ability on this model at all — not one. Every
 * balance this module reports is a sum over these rows, so an editable one is an
 * editable balance with extra steps, and a deletable one is a redemption that
 * never happened.
 *
 * The append-only guarantee is made in three layers and this is the third. The
 * model's `updating` and `deleting` events throw, which catches every instance
 * write including from a job or a console command; `LedgerBuilder` refuses the
 * mass operations those events never fire for; and this refuses every ability by
 * name so no panel offers a button. Each alone leaves a door open.
 *
 * Registered on the model even though nobody expects to put it on a panel. A
 * model reachable from a relation is a model somebody's gate call will reach
 * eventually, and Filament's relation-manager abilities are live on a `hasMany`
 * and default open.
 */
class GiftCardEntryPolicy
{
    use ReadsWithinTeam;
    use RefusesEveryWrite;

    public function viewAny(Authenticatable $actor): bool
    {
        return $this->teamOf($actor) !== null;
    }

    public function view(Authenticatable $actor, GiftCardEntry $entry): bool
    {
        return $this->ownsIt($actor, $entry->team_id);
    }
}
