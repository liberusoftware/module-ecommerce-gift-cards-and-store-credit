<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for whatever the host calls a team.
 *
 * The point of the fixture is that this package has never seen the real class.
 * `gift-cards.team_model` is resolved at call time, so anything with an id
 * works — Jetstream's `Team`, an organisations package's, a class this package
 * could not name if it wanted to.
 *
 * @property int $id
 * @property string $name
 */
class FakeTeam extends Model
{
    protected $table = 'fake_teams';

    protected $fillable = ['name'];
}
