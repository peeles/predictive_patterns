<?php

namespace Tests;

use App\Enums\Role;
use App\Models\User;
use App\Support\SanctumTokenManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Facade;

if (! class_exists(\Laravel\Horizon\Facades\Horizon::class)) {
    /**
     * @internal minimal facade stub for tests when Horizon is not installed.
     */
    class HorizonFacadeStub extends Facade
    {
        protected static function getFacadeAccessor(): string
        {
            return 'horizon';
        }
    }

    class_alias(HorizonFacadeStub::class, \Laravel\Horizon\Facades\Horizon::class);
}

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Force close any open transactions
        if ($this->app->bound('db')) {
            $pdo = $this->app['db']->connection()->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => env('DB_DATABASE', ':memory:'),
            'database.connections.sqlite.foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'cache.default' => 'array',
            'api.rate_limits' => [
                Role::Admin->value => 1000,
                Role::Analyst->value => 600,
                Role::Viewer->value => 300,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up transactions after each test
        if ($this->app->bound('db')) {
            $pdo = $this->app['db']->connection()->getPdo();
            while ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        parent::tearDown();
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
