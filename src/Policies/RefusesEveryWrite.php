<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * **For a ledger the correct answer to almost every ability is no**, and this is
 * where every one of them is said out loud.
 *
 * Nobody edits a balance. Nobody deletes a ledger row. What a merchant may do is
 * the operations the domain publishes — redeem, credit, adjust, disable — each of
 * which takes a caller-supplied idempotency key and writes an append-only row. A
 * form over a row is not one of those, and no policy in this module will ever say
 * it is.
 *
 * ### Why every ability is named, including the ones that are always false
 *
 * A model with **no** policy is exposed and not safe: Laravel's unanswered gate
 * case is permissive, and this fleet has shipped that leak five times across
 * waves 3, 4 and 5. The sharper version is worse: Filament's
 * `get_authorization_response()` returns **allow** when a *present* policy has no
 * method for the ability asked about — so a partial policy is the same hazard as
 * no policy, and it is harder to see, because the file exists and looks like a
 * control.
 *
 * `AuthorizationTest` asserts each of these by name rather than trusting that a
 * missing method means no.
 *
 * ### The parameter is typed `Model`, and that is deliberate
 *
 * Wave 4 found a `CartPolicy` typed against `Cart` whose default gate call about
 * a `CartItem` raised a **`TypeError` from inside the policy** — which is not a
 * denial, it is a 500 that some callers swallow. A trait shared across two models
 * has to accept either, and typing the base `Model` means a gate call about the
 * wrong model is answered `false` rather than thrown.
 */
trait RefusesEveryWrite
{
    /**
     * Balances are issued by an action carrying a caller's idempotency key, never
     * by a form. A button minting a fresh key per press writes a second row on a
     * double click — and here that means a second gift card.
     */
    public function create(Authenticatable $actor): bool
    {
        return false;
    }

    /**
     * **There is nothing to edit.**
     *
     * Not the balance, which is a fold. Not the currency, which is what the card
     * was sold in. Not `expires_at`, which is a term of issue — a merchant that
     * must honour an expired card issues a replacement and moves the balance,
     * which leaves a trail that editing a date would not.
     */
    public function update(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    /** Every balance this module reports was built from these rows. */
    public function delete(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    public function restore(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    public function forceDelete(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    public function deleteAny(Authenticatable $actor): bool
    {
        return false;
    }

    public function forceDeleteAny(Authenticatable $actor): bool
    {
        return false;
    }

    public function restoreAny(Authenticatable $actor): bool
    {
        return false;
    }

    /**
     * Replicating a gift card would mint a second row pointing at one code, or a
     * second row with none. Neither is a card.
     */
    public function replicate(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    public function reorder(Authenticatable $actor): bool
    {
        return false;
    }

    /**
     * Filament's relation-manager abilities, refused explicitly.
     *
     * These are **live on a `hasMany`** and default open, which is how wave 4
     * described a tender ending up filed against somebody else's order. A ledger
     * entry associated onto a different card is the same fault about money, and
     * it is the one that would move a balance from one customer to another
     * without writing a single row.
     */
    public function associate(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    public function disassociate(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    public function disassociateAny(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    public function attach(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    public function detach(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    public function detachAny(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    /**
     * **Nobody may see a code. Ever. Including the person who issued it.**
     *
     * Named and refused rather than left absent, because "there is no such
     * ability" and "the ability exists and is denied" look identical from a
     * panel and only one of them survives somebody adding a resource. There is
     * nothing behind these two to grant: the code is not in the database in any
     * recoverable form, so a `true` here would be a promise this module could not
     * keep.
     */
    public function viewCode(Authenticatable $actor, Model $record): bool
    {
        return false;
    }

    public function revealCode(Authenticatable $actor, Model $record): bool
    {
        return false;
    }
}
