<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\Authenticate;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\BindingResolutionException;
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

    /**
     * @throws BindingResolutionException
     */
    public function test_returns_login_route_when_available(): void
    {
        Route::get('/login', static fn () => '')->name('login');

        $middleware = $this->createMiddleware();
        $request = Request::create('/protected', 'GET');

        $this->assertSame(route('login'), $middleware->callRedirectTo($request));
    }

    /**
     * @throws BindingResolutionException
     */
    public function test_returns_null_for_json_requests(): void
    {
        $middleware = $this->createMiddleware();
        $request = Request::create('/api/protected', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertNull($middleware->callRedirectTo($request));
    }

    /**
     * @throws BindingResolutionException
     */
    public function test_returns_app_url_when_no_login_route_registered(): void
    {
        $middleware = $this->createMiddleware();
        $request = Request::create('/protected', 'GET');

        $result = $middleware->callRedirectTo($request);
        $expectedUrl = config('app.url') . '/login';

        $this->assertSame($expectedUrl, $result);
        $this->assertStringEndsWith('/login', $result);
    }

    /**
     * @throws BindingResolutionException
     */
    private function createMiddleware(): object
    {
        $authFactory = $this->app->make(AuthFactory::class);

        return new class ($authFactory) extends Authenticate {
            public function __construct(AuthFactory $auth)
            {
                parent::__construct($auth);
            }

            public function callRedirectTo(Request $request): ?string
            {
                return $this->redirectTo($request);
            }
        };
    }
}
