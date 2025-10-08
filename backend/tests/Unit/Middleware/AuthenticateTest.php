<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticateTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Route::flushMiddlewareGroups();
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    public function test_returns_login_route_when_available(): void
    {
        Route::get('/login', static fn () => '')->name('login');

        $middleware = $this->createMiddleware();
        $request = Request::create('/protected', 'GET');

        $this->assertSame(route('login'), $middleware->callRedirectTo($request));
    }

    public function test_returns_null_for_json_requests(): void
    {
        $middleware = $this->createMiddleware();
        $request = Request::create('/api/protected', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertNull($middleware->callRedirectTo($request));
    }

    public function test_returns_app_url_when_no_login_route_registered(): void
    {
        config(['app.url' => 'https://example.test']);

        $middleware = $this->createMiddleware();
        $request = Request::create('/protected', 'GET');

        $this->assertSame('https://example.test', $middleware->callRedirectTo($request));
    }

    private function createMiddleware(): object
    {
        return new class () extends Authenticate {
            public function callRedirectTo(Request $request): ?string
            {
                return $this->redirectTo($request);
            }
        };
    }
}
