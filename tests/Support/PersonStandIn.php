<?php

namespace Goldnead\StatamicPayments\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A host's own user model without a morph alias, as ChoirLive has it
 * (`App\Models\User`, Statamic `users.repository = eloquent`). A purchase
 * `for` such a user stores the class name as the subject type.
 */
class PersonStandIn extends Authenticatable
{
    protected $table = 'test_people';

    protected $guarded = [];

    public $timestamps = false;
}
