<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A minimal host user model.
 *
 * Pandora never assumes anything about the host's user beyond Authorizable,
 * so the fixture is deliberately plain.
 */
final class TestUser extends Authenticatable
{
    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'tenant_id'];

    /** @var list<string> */
    protected $hidden = ['password'];
}
