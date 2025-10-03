<?php

namespace Tests;

use App\Enums\Role;
use App\Models\User;
use App\Support\SanctumTokenManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => env('DB_DATABASE', ':memory:'),
            'database.connections.sqlite.foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'api.rate_limits' => [
                Role::Admin->value => 1000,
                Role::Analyst->value => 600,
                Role::Viewer->value => 300,
            ],
        ]);
    }

    protected function issueTokensForRole(Role $role = Role::Admin): array
    {
        $user = User::factory()->create([
            'role' => $role,
        ]);

        $tokens = SanctumTokenManager::issue($user);

        return [
            'user' => $user,
            'accessToken' => $tokens['accessToken'],
            'refreshToken' => $tokens['refreshToken'],
        ];
    }

    protected function hashPersonalAccessToken(string $plainTextToken): string
    {
        [, $token] = array_pad(explode('|', $plainTextToken, 2), 2, '');

        return hash('sha256', $token);
    }
}
