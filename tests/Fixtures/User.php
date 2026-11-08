<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** The test app's user — any Authenticatable model works. */
class User extends Authenticatable
{
    use Notifiable;

    protected $guarded = [];
}
