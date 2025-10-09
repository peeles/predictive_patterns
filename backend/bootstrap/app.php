<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureApiTokenIsValid;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders((function (): array {
        $providers = [
            App\Providers\EventServiceProvider::class,
            App\Providers\AppServiceProvider::class,
            App\Providers\AuthServiceProvider::class,
            App\Providers\BroadcastFallbackServiceProvider::class,
            App\Providers\HorizonServiceProvider::class,
        ];

        if (class_exists(Pest\Laravel\PestServiceProvider::class)) {
            $providers[] = Pest\Laravel\PestServiceProvider::class;
        }

        return $providers;
    })())
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            HandleCors::class,
        ]);

        $middleware->group('api', [
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'auth.api' => EnsureApiTokenIsValid::class,
            'throttle' => ThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(static function (Request $request): bool {
            return $request->is('api/*');
        });

        $exceptions->render(static function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiExceptionRenderer::render($exception, $request);
        });
    })->create();
