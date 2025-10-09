<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson() || $request->wantsJson() || $request->is('api/*')) {
            return null;
        }

        if (Route::has('login')) {
            return route('login');
        }

        $fallbackUrl = (string) (config('app.url') ?? '/');

        if ($fallbackUrl === '') {
            return '/login';
        }

        return rtrim($fallbackUrl, '/') . '/login';
    }
}
