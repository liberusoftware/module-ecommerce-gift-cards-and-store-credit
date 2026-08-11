<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\DisableAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;

/**
 * **For a ledger the correct answer to almost every ability is no**, and this
 * file says every one of them out loud.
 *
 * Two hazards this fleet has shipped repeatedly:
 *
 * - A model with **no** policy is exposed; Laravel's unanswered gate is
 *   permissive.
 * - A **present** policy missing a method is the sharper version, because
 *   Filament's `get_authorization_response()` returns *allow* for an ability it
 *   has no method for — and the file existing makes it look like a control.
 *
 * So every ability is asserted **by name** rather than trusted to be absent, and
 * that includes `associate`, `disassociate`, `attach` and `detach`, which are live
 * on a `hasMany` and default open. A ledger entry associated onto a different card
 * would move a balance from one customer to another without writing a row.
 */
const REFUSED_ABILITIES = [
    'create', 'update', 'delete', 'restore', 'forceDelete', 'deleteAny',
    'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
    'associate', 'disassociate', 'disassociateAny', 'attach', 'detach', 'detachAny',
    'viewCode', 'revealCode',
];

it('refuses every write ability on an account, by name, to its own team', function (string $ability) {
    // Denied to the owner, which is the strong version: it is not tenancy saying
    // no, it is the domain.
    $account = GiftCardAccount::factory()->ofTeam(GHOST_TEAM)->create();
    $actor = actorInTeam(GHOST_TEAM);

    expect(Gate::forUser($actor)->allows($ability, $account))->toBeFalse();
})->with(REFUSED_ABILITIES);

it('refuses every write ability on a ledger entry, by name', function (string $ability) {
    $account = GiftCardAccount::factory()->ofTeam(GHOST_TEAM)->create();
    $entry = GiftCardEntry::factory()->forAccount($account)->create();
    $actor = actorInTeam(GHOST_TEAM);

    expect(Gate::forUser($actor)->allows($ability, $entry))->toBeFalse();
})->with(REFUSED_ABILITIES);

it('answers a gate call about the wrong model with no rather than a TypeError', function () {
    // Wave 4 found a policy typed against one model whose gate call about its
    // child raised a `TypeError` from inside the policy — which is a 500, not a
    // denial, and some callers swallow it. The trait's parameter is typed `Model`.
    $entry = GiftCardEntry::factory()->create();
    $actor = actorInTeam(GHOST_TEAM);

    expect(Gate::forUser($actor)->allows('update', $entry))->toBeFalse();
});

it('shows an account only to its own team', function () {
    $mine = GiftCardAccount::factory()->ofTeam(GHOST_TEAM)->create();
    $theirs = GiftCardAccount::factory()->ofTeam(GHOST_OTHER_TEAM)->create();
    $actor = actorInTeam(GHOST_TEAM);

    expect(Gate::forUser($actor)->allows('view', $mine))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('view', $theirs))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('viewAny', GiftCardAccount::class))->toBeTrue();
});

it('shows an orphan to nobody, so it cannot be quietly claimed', function () {
    // `where('col', null)` compiles to `is null`, so a tenancy scope written that
    // way lists exactly the rows the policy denies. Both halves are asserted.
    $orphan = GiftCardAccount::factory()->ofTeam(null)->create();
    $actor = actorInTeam(GHOST_TEAM);

    expect(Gate::forUser($actor)->allows('view', $orphan))->toBeFalse()
        ->and(GiftCardAccount::query()->ofTeam(null)->count())->toBe(0);
});

it('sees nothing at all when an actor s team is not a number', function () {
    // The guard wave 5 recorded failing silently on ULID and UUID deployments: it
    // returns null, and null here means **no**, which is the safe direction. A
    // misconfigured host sees nothing rather than everything — and
    // `docs/runbook.md` names it, because a guard that fails closed is invisible
    // until somebody asks why the panel is empty.
    $account = GiftCardAccount::factory()->ofTeam(GHOST_TEAM)->create();
    $actor = actorInTeam(null);
    $actor->current_team_id = '01HQ8ZK7ULIDNOTANUMBER';

    expect(Gate::forUser($actor)->allows('view', $account))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('viewAny', GiftCardAccount::class))->toBeFalse();
});

it('lets the four publishable abilities through, each gated on the domain as well as the team', function () {
    $card = issueCard(5000);
    $account = GiftCardAccount::query()->with('entries')->where('reference', $card->account->reference)->firstOrFail();
    $actor = actorInTeam(GHOST_TEAM);
    $stranger = actorInTeam(GHOST_OTHER_TEAM);

    expect(Gate::forUser($actor)->allows('redeem', $account))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('credit', $account))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('adjust', $account))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('disable', $account))->toBeTrue()
        // And to nobody else's team, whatever the domain says.
        ->and(Gate::forUser($stranger)->allows('redeem', $account))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('adjust', $account))->toBeFalse();
});

it('stops offering a button that would throw', function () {
    // Each ability is gated on the domain's own answer as well as on tenancy, so
    // a staff member holding the permission still cannot get round an invariant.
    $card = issueCard(5000);
    (new DisableAccount())->handle($card->account->reference, 'stop-1', 'reported_stolen');

    $account = GiftCardAccount::query()->where('reference', $card->account->reference)->firstOrFail();
    $actor = actorInTeam(GHOST_TEAM);

    expect(Gate::forUser($actor)->allows('redeem', $account))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('credit', $account))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('disable', $account))->toBeFalse()
        // Still adjustable: writing off a stopped card is the ordinary end of
        // that story.
        ->and(Gate::forUser($actor)->allows('adjust', $account))->toBeTrue();
});

it('refuses redemption against an expired card while still allowing a credit', function () {
    $card = issueCard(5000, expiresAt: '2020-01-01 00:00:00');
    $account = GiftCardAccount::query()->where('reference', $card->account->reference)->firstOrFail();
    $actor = actorInTeam(GHOST_TEAM);

    expect(Gate::forUser($actor)->allows('redeem', $account))->toBeFalse()
        // The expiry decision, in the policy: a refund onto an expired card lands.
        ->and(Gate::forUser($actor)->allows('credit', $account))->toBeTrue();
});

it('names no ability that would return a code, because there is nothing to return', function () {
    // `viewCode` and `revealCode` exist only so that "nobody may ever see a code"
    // is a denial somebody can point at rather than an absence. There is nothing
    // behind them to grant: the code is not in the database in any recoverable
    // form, so `true` would be a promise this module could not keep.
    $policy = (string) file_get_contents(__DIR__.'/../src/Policies/RefusesEveryWrite.php');

    expect($policy)->toContain('function viewCode')
        ->toContain('function revealCode');

    $account = GiftCardAccount::factory()->ofTeam(GHOST_TEAM)->create();

    expect(Gate::forUser(actorInTeam(GHOST_TEAM))->allows('viewCode', $account))->toBeFalse();
});
