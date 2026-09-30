<?php

namespace PlinCode\PlatformAuthorizer\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model to use as a Pennant scope. It is never saved.
 */
class Person extends Model
{
    protected $guarded = [];
}
