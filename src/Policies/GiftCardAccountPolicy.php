<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;

/**
 * Who, among staff, may act on somebody's balance.
 *
 * Registered explicitly rather than left to Laravel's convention, which maps
 * `App\Models\X` to `App\Policies\XPolicy` and would find nothing for a model in
 * neither namespace. An unregistered policy is not a closed door — the unanswered
 * gate case is permissive.
 *
 * ### The write surface is four abilities, and none of them is a form
 *
 * `redeem`, `credit`, `adjust` and `disable` are the operations the domain
 * publishes. Three are gated on the domain's own answer as well as on tenancy —
 * there is nothing to redeem against a stopped card — so a staff member holding
 * the permission still cannot get round an invariant. They are separate abilities
 * because they are different-sized mistakes: one takes a customer's money, one
 * gives the merchant's away, one is a human overriding the arithmetic, and one is
 * irreversible.
 *
 * Everything else is `false`, by name, from `RefusesEveryWrite` — including
 * `viewCode` and `revealCode`, which exist only so that "nobody may ever see a
 * code" is a denial somebody can point at rather than an absence.
 *
 * ### `redeem` here is not the bearer path
 *
 * `RedeemByCode` takes a code and no actor: a customer at a checkout is not
 * authenticated as anybody, and gating that on a policy would mean gating it on
 * whoever the session happened to belong to. This ability is for a **staff member
 * applying a card at a till**, where there is an actor and a panel button. The
 * domain guard runs either way, under the row lock.
 */
class GiftCardAccountPolicy
{
    use ReadsWithinTeam;
    use RefusesEveryWrite;

    public function viewAny(Authenticatable $actor): bool
    {
        return $this->teamOf($actor) !== null;
    }

    public function view(Authenticatable $actor, GiftCardAccount $account): bool
    {
        return $this->ownsIt($actor, $account->team_id);
    }

    /** Spend against a balance. Only while there is one to spend. */
    public function redeem(Authenticatable $actor, GiftCardAccount $account): bool
    {
        return $this->ownsIt($actor, $account->team_id) && $account->state()->isRedeemable();
    }

    /**
     * Put money on. Refused on a stopped card, matching `RecordCredit`.
     *
     * Deliberately allowed on an **expired** one: expiry ends redeemability and
     * not ownership, so a refund onto an expired card lands and the money stays
     * where it was. The panel simply stops offering a button that would throw.
     */
    public function credit(Authenticatable $actor, GiftCardAccount $account): bool
    {
        return $this->ownsIt($actor, $account->team_id) && ! $account->state()->disabled;
    }

    /**
     * Correct a balance by hand.
     *
     * Not gated on the card's state: an operator writing off a stopped or expired
     * card is the ordinary end of both stories. It is gated on tenancy, and it is
     * the ability a deployment should grant to the fewest people, because it is
     * the only one that can make the arithmetic say anything at all.
     */
    public function adjust(Authenticatable $actor, GiftCardAccount $account): bool
    {
        return $this->ownsIt($actor, $account->team_id);
    }

    /** Stop a card. Terminal, so refused once it already is. */
    public function disable(Authenticatable $actor, GiftCardAccount $account): bool
    {
        return $this->ownsIt($actor, $account->team_id) && ! $account->state()->disabled;
    }
}
