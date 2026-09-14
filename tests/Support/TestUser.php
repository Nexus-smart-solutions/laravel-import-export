<?php

namespace Nexus\ImportExport\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

final class TestUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}
