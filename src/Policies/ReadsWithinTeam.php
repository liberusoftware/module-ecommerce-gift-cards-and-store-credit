<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Tenancy, read off the actor so it answers the same way in a console command, a
 * queued job and an API request.
 *
 * A record belonging to nobody (`team_id` null) is nobody's to act on. Both
 * halves matter: an orphan is **not** visible, so it cannot be quietly claimed,
 * and the comparison is bound rather than written `null === null` — a leak
 * written as a tautology is the version that survives review.
 *
 * `is_numeric()` on the actor's team is the guard wave 5 recorded failing
 * silently on ULID and UUID deployments: it returns null, and null here means
 * **no**, which is the safe direction. A misconfigured host sees nothing rather
 * than everything. `docs/runbook.md` names it, because a guard that fails closed
 * is invisible until somebody asks why the panel is empty.
 */
trait ReadsWithinTeam
{
    protected function teamOf(Authenticatable $actor): ?int
    {
        $team = $actor->getAttribute('current_team_id');

        return is_numeric($team) ? (int) $team : null;
    }

    protected function ownsIt(Authenticatable $actor, ?int $teamId): bool
    {
        $team = $this->teamOf($actor);

        return $team !== null && $teamId === $team;
    }
}
