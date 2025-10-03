<?php

namespace App\Http\Middleware;

use App\Support\SanctumTokenManager;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenIsValid
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $providedToken = $this->extractToken($request);

        if ($providedToken === null) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid API token.');
        }

        $accessToken = SanctumTokenManager::resolveAccessToken($providedToken);

        if (! $accessToken instanceof PersonalAccessToken) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid API token.');
        }

        $user = $accessToken->tokenable;

        if (! $user instanceof Authenticatable) {
            $accessToken->delete();
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid API token.');
        }

        $request->setUserResolver(fn (): Authenticatable => $user);
        Auth::setUser($user);

        $accessToken->forceFill([
            'last_used_at' => now(),
        ])->save();

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $token = $request->bearerToken();

        if ($token === null) {
            $token = $request->header('X-API-Key');
        }

        if ($token === null) {
            return null;
        }

        $token = trim((string) $token);

        return $token === '' ? null : $token;
    }
}
