<?php

declare(strict_types=1);

use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\IssueAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\RedeemByCode;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\IssueInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\IssueResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerEntryData;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\LedgerResult;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\Money;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\RedemptionInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\AccountKind;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\EntryKind;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\TestUser;
use Liberu\PackageTestbench\UsesTestUser;

/*
 * `UsesTestUser` brings `RefreshDatabase` with it, and both halves are wanted:
 * the policies read `current_team_id` off a real actor, and the migrations this
 * package's provider loads need a database to run against.
 *
 * The pepper is set here rather than in each file because `Code::pepper()`
 * **throws** without one — deliberately, so that a deployment cannot end up
 * hashing every gift card code under the empty string. A suite that forgot it
 * would fail loudly, which is the behaviour under test in `CodeTest`.
 *
 * The bcrypt cost is dropped to bcrypt's own minimum for speed. That is a fixture
 * decision and not a claim: the cost is a calibration knob, `config/gift-cards.php`
 * says so, and nothing in the module's behaviour depends on the number.
 */
uses(PackageTestCase::class, UsesTestUser::class)
    ->beforeEach(function (): void {
        config()->set('gift-cards.code_pepper', TEST_PEPPER);
        config()->set('gift-cards.code_hash_cost', 4);
    })
    ->in(__DIR__);

/**
 * The order, the customer, the checkout and the refund this whole suite is about,
 * and none of them exists.
 *
 * Every identifier a fixture uses sits at 9,000,00x. The range keeps them clear
 * of anything `TestUser::factory()` mints, because a fixture id that collides
 * makes an authorization test pass for the wrong reason — a stranger's card
 * quietly becomes the actor's own. The *absence* is the more important half: no
 * orders module, no checkout module and no refunds module is installed in this
 * suite, none of their tables exists, and these numbers name nothing at all. They
 * are identifiers, which is the only thing this module ever holds.
 */
const GHOST_CUSTOMER = 9_000_055;
const GHOST_TEAM = 9_000_007;
const GHOST_OTHER_TEAM = 9_000_008;
const GHOST_OPERATOR = 9_000_301;
const GHOST_ORDER_REFERENCE = 'ORD-GHOST';
const GHOST_REFUND_REFERENCE = 'REF-GHOST';

/** The pepper this suite hashes under. Configuration, never a column. */
const TEST_PEPPER = 'a-pepper-that-lives-in-the-environment-and-not-in-a-row';

function money(int $minor, string $currency = 'GBP', int $exponent = 2): Money
{
    return new Money($minor, $currency, $exponent);
}

/** Sell a gift card. The result is the only thing that will ever hold the code. */
function issueCard(int $minor = 5000, string $currency = 'GBP', ?string $expiresAt = null, string $key = 'issue-1', ?int $teamId = GHOST_TEAM, ?int $customerId = GHOST_CUSTOMER): IssueResult
{
    return (new IssueAccount())->handle(new IssueInput(
        kind: AccountKind::GiftCard,
        issueKey: $key,
        amount: money($minor, $currency),
        customerId: $customerId,
        teamId: $teamId,
        sourceReference: GHOST_ORDER_REFERENCE,
        expiresAt: $expiresAt,
    ));
}

/** Grant store credit. Same ledger, no code. */
function grantCredit(int $minor = 5000, string $currency = 'GBP', string $key = 'grant-1'): IssueResult
{
    return (new IssueAccount())->handle(new IssueInput(
        kind: AccountKind::StoreCredit,
        issueKey: $key,
        amount: money($minor, $currency),
        customerId: GHOST_CUSTOMER,
        teamId: GHOST_TEAM,
    ));
}

/** Present a code and ask for an amount. */
function redeem(string $code, int $minor, string $key = 'redeem-1', string $currency = 'GBP', string $throttleKey = 'presenter-one'): LedgerResult
{
    return (new RedeemByCode())->handle(new RedemptionInput(
        code: $code,
        entryKey: $key,
        amount: money($minor, $currency),
        throttleKey: $throttleKey,
        sourceReference: GHOST_ORDER_REFERENCE,
    ));
}

/**
 * A ledger, described rather than persisted.
 *
 * The fold is a pure function over these, so most of what this module promises
 * can be proved without touching a database — which is what makes enumerating
 * 156 sequences of entry kinds against both expiry values cheap enough to do in a
 * test.
 *
 * @param  list<array{0: EntryKind, 1?: int}>  $rows
 * @return list<LedgerEntryData>
 */
function ledger(array $rows, string $currency = 'GBP'): array
{
    $entries = [];

    foreach ($rows as $index => $row) {
        $entries[] = new LedgerEntryData(
            id: $index + 1,
            accountId: 1,
            kind: $row[0],
            amount: money($row[1] ?? 0, $currency),
            occurredAt: '2026-08-11T09:00:00+00:00',
            teamId: GHOST_TEAM,
        );
    }

    return $entries;
}

/**
 * An actor working in a team, the way a team switcher leaves them.
 *
 * Team ids here start at 9,000,00x so they cannot collide with anything
 * `TestUser::factory()` mints. A fixture id that collides makes an authorization
 * test pass for the wrong reason — a "stranger's" card quietly becomes the
 * actor's own — and that failure mode is invisible in a green suite.
 */
function actorInTeam(?int $teamId): TestUser
{
    $user = TestUser::factory()->create();
    $user->current_team_id = $teamId;

    return $user;
}

/**
 * Every ordering of a list.
 *
 * @param  list<mixed>  $items
 * @return list<list<mixed>>
 */
function permutations(array $items): array
{
    if (count($items) <= 1) {
        return [$items];
    }

    $result = [];

    foreach ($items as $index => $item) {
        $rest = $items;
        array_splice($rest, $index, 1);

        foreach (permutations($rest) as $permutation) {
            $result[] = [$item, ...$permutation];
        }
    }

    return $result;
}
