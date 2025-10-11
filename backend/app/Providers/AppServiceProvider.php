<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\Dataset;
use App\Observers\DatasetObserver;
use App\Repositories\DatasetRepositoryInterface;
use App\Repositories\PredictiveModelRepositoryInterface;
use App\Repositories\Eloquent\EloquentDatasetRepository;
use App\Repositories\Eloquent\EloquentPredictiveModelRepository;
use App\Support\ResolvesRoles;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Random\RandomException;

class AppServiceProvider extends ServiceProvider
{
    use ResolvesRoles;

    /**
     * Register any application services.
     *
     * @throws RandomException
     */
    public function register(): void
    {
        $this->ensureEncryptionKey();

        $this->app->bind(DatasetRepositoryInterface::class, EloquentDatasetRepository::class);
        $this->app->bind(PredictiveModelRepositoryInterface::class, EloquentPredictiveModelRepository::class);

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->useEphemeralCacheDuringDatabaseCommands();
        $this->configureRateLimiting();
        $this->registerSlowQueryLogger();
        Dataset::observe(DatasetObserver::class);
    }

    private function registerSlowQueryLogger(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        DB::listen(function (QueryExecuted $query): void {
            if ($query->time <= 1000) {
                return;
            }

            Log::warning('Slow query detected', [
                'sql' => $query->sql,
                'time_ms' => $query->time,
                'connection' => $query->connectionName,
                'bindings' => $query->bindings,
            ]);
        });
    }

    /**
     * @throws RandomException
     */
    private function ensureEncryptionKey(): void
    {
        $cipher = (string) config('app.cipher', 'AES-256-CBC');

        if ($this->isValidEncryptionKey(config('app.key'), $cipher)) {
            return;
        }

        $keyPath = storage_path('app/app.key');

        if (File::exists($keyPath)) {
            $storedKey = trim(File::get($keyPath));

            if ($this->isValidEncryptionKey($storedKey, $cipher)) {
                config(['app.key' => $storedKey]);

                return;
            }
        }

        File::ensureDirectoryExists(dirname($keyPath));

        $generatedKey = $this->generateEncryptionKey($cipher);

        File::put($keyPath, $generatedKey . PHP_EOL, true);
        @chmod($keyPath, 0600);

        config(['app.key' => $generatedKey]);
    }

    /**
     * @throws RandomException
     */
    private function generateEncryptionKey(string $cipher): string
    {
        return 'base64:' . base64_encode(random_bytes($this->expectedKeyLength($cipher)));
    }

    private function isValidEncryptionKey(mixed $key, string $cipher): bool
    {
        if ($key === null) {
            return false;
        }

        $key = trim((string) $key);

        if ($key === '') {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false) {
                return false;
            }

            return $this->hasExpectedLength($decoded, $cipher);
        }

        return $this->hasExpectedLength($key, $cipher);
    }

    private function hasExpectedLength(string $key, string $cipher): bool
    {
        return strlen($key) === $this->expectedKeyLength($cipher);
    }

    private function expectedKeyLength(string $cipher): int
    {
        return match (strtoupper($cipher)) {
            'AES-128-CBC', 'AES-128-GCM' => 16,
            'AES-256-CBC', 'AES-256-GCM' => 32,
            default => 32,
        };
    }

    private function useEphemeralCacheDuringDatabaseCommands(): void
    {
        if (!App::runningInConsole()) {
            return;
        }

        $command = (string) ($_SERVER['argv'][1] ?? '');

        if ($command === '') {
            return;
        }

        $databaseCommands = [
            'db:wipe',
            'migrate',
            'migrate:fresh',
            'migrate:install',
            'migrate:refresh',
            'migrate:reset',
            'migrate:status',
        ];

        if (!Str::startsWith($command, $databaseCommands)) {
            return;
        }

        config(['cache.default' => 'array']);
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $role = $this->resolveRole($request->user());
            $limitKey = sprintf('api.rate_limits.%s', $role->value);
            $perMinute = (int)config($limitKey, config('api.rate_limits.' . Role::Viewer->value, 60));
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown';

            return Limit::perMinute(max($perMinute, 1))->by((string)$identifier);
        });

        RateLimiter::for('map', function (Request $request): Limit {
            $role = $this->resolveRole($request->user());
            $limitKey = sprintf('api.map_rate_limits.%s', $role->value);
            $perMinute = (int)config($limitKey, config('api.map_rate_limits.' . Role::Viewer->value, 600));
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown';

            return Limit::perMinute(max($perMinute, 1))->by((string)$identifier);
        });

        RateLimiter::for('ingest', function (Request $request): Limit {
            $perMinute = (int)config('api.ingest_rate_limit', 5);
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown';

            return Limit::perMinute(max($perMinute, 1))->by((string)$identifier);
        });

        RateLimiter::for('model-train', function (Request $request): Limit {
            $perMinute = (int)config('api.model_training_rate_limit', 5);
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown';

            return Limit::perMinute(max($perMinute, 1))->by((string)$identifier);
        });

        RateLimiter::for('model-evaluate', function (Request $request): Limit {
            $perMinute = (int)config('api.model_evaluation_rate_limit', 5);
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown';

            return Limit::perMinute(max($perMinute, 1))->by((string)$identifier);
        });

        RateLimiter::for('auth-login', function (Request $request): Limit {
            $perMinute = (int)config('api.auth_rate_limits.login', 10);
            $identifier = $request->ip() ?? 'unknown';

            if ($email = $request->input('email')) {
                $identifier = sprintf('%s|%s', strtolower((string)$email), $identifier);
            }

            return Limit::perMinute(max($perMinute, 1))->by($identifier);
        });

        RateLimiter::for('auth-refresh', function (Request $request): Limit {
            $perMinute = (int)config('api.auth_rate_limits.refresh', 60);
            $identifier = $request->input('refreshToken') ?? $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown';

            return Limit::perMinute(max($perMinute, 1))->by((string)$identifier);
        });

        RateLimiter::for('jobs:ingest-remote-dataset', function (): Limit {
            return Limit::perMinute(10);
        });
    }

}
