<?php

namespace Goldnead\StatamicPayments\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A team as statamic-teams registers it: an Eloquent model behind the morph
 * alias `team`. Only what this package touches: a key and a name.
 */
class TeamStandIn extends Model
{
    protected $table = 'test_teams';

    protected $guarded = [];

    public $timestamps = false;
}
