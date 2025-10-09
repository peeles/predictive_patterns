<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\AuthLogoutRequest;
use App\Http\Requests\AuthMeRequest;
use App\Http\Requests\AuthRefreshRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\SanctumTokenManager;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends BaseController
{
    private const REFRESH_COOKIE_PATH = '/api';

    /**
     * User login
     *
     * @param LoginRequest $request
     *
     * @return JsonResponse
     * @throws ValidationException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $tokens = SanctumTokenManager::issue($user);

        $response = $this->successResponse([
            'accessToken' => $tokens['accessToken'],
            'user' => new UserResource($user),
            'expiresIn' => $tokens['expiresIn'],
        ]);

        return $this->setRefreshCookie($response, $tokens['refreshToken']);
    }

    /**
     * Refresh access token
     *
     * @param AuthRefreshRequest $request
     *
     * @return JsonResponse
     */
    public function refresh(AuthRefreshRequest $request): JsonResponse
    {
        $refreshToken = $request->refreshToken();

        if ($refreshToken === null) {
            return $this->unauthorizedRefreshResponse();
        }

        $refreshToken = $this->decryptRefreshToken($refreshToken);
        $result = SanctumTokenManager::refresh($refreshToken);

        if ($result === null) {
            return $this->unauthorizedRefreshResponse();
        }

        $response = $this->successResponse([
            'accessToken' => $result['tokens']['accessToken'],
            'user' => new UserResource($result['user']),
            'expiresIn' => $result['tokens']['expiresIn'],
        ]);

        return $this->setRefreshCookie($response, $result['tokens']['refreshToken']);
    }

    /**
     * User logout
     *
     * @param AuthLogoutRequest $request
     *
     * @return JsonResponse
     */
    public function logout(AuthLogoutRequest $request): JsonResponse
    {
        SanctumTokenManager::revoke($request->bearerToken());

        $response = $this->successResponse([
            'message' => 'Logged out',
        ]);

        return $this->forgetRefreshCookie($response);
    }

    /**
     * Get the authenticated user.
     *
     * @param AuthMeRequest $request
     *
     * @return JsonResponse
     */
    public function me(AuthMeRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $this->successResponse(new UserResource($user));
        }

        return $this->errorResponse('Unauthenticated.', [], Response::HTTP_UNAUTHORIZED);
    }

    private function setRefreshCookie(JsonResponse $response, string $refreshToken): JsonResponse
    {
        $secure = (bool) (config('session.secure') ?? false);

        $cookie = cookie(
            SanctumTokenManager::REFRESH_COOKIE_NAME,
            $refreshToken,
            SanctumTokenManager::REFRESH_TOKEN_TTL_DAYS * 24 * 60,
            self::REFRESH_COOKIE_PATH,
            config('session.domain'),
            $secure,
            true,
            false,
            config('session.same_site') ?? 'lax'
        );

        return $response->withCookie($cookie);
    }

    /**
     * Forget the refresh token cookie.
     *
     * @param JsonResponse $response
     *
     * @return JsonResponse
     */
    private function forgetRefreshCookie(JsonResponse $response): JsonResponse
    {
        $secure = (bool) (config('session.secure') ?? false);

        $cookie = cookie(
            SanctumTokenManager::REFRESH_COOKIE_NAME,
            '',
            -1,
            self::REFRESH_COOKIE_PATH,
            config('session.domain'),
            $secure,
            true,
            false,
            config('session.same_site') ?? 'lax'
        );

        return $response->withCookie($cookie);
    }

    /**
     * Generate an unauthorized response for invalid refresh token.
     *
     * @return JsonResponse
     */
    private function unauthorizedRefreshResponse(): JsonResponse
    {
        $response = $this->errorResponse('Invalid refresh token.', [], Response::HTTP_UNAUTHORIZED);

        return $this->forgetRefreshCookie($response);
    }

    /**
     * Decrypt the refresh token from the cookie.
     *
     * @param string $refreshToken
     *
     * @return string
     */
    private function decryptRefreshToken(string $refreshToken): string
    {
        if ($refreshToken === '') {
            return '';
        }

        $decoded = rawurldecode($refreshToken);

        if (str_contains($decoded, '|')) {
            return $decoded;
        }

        try {
            $decrypted = Crypt::decryptString($decoded);

            return str_contains($decrypted, '|') ? $decrypted : '';
        } catch (DecryptException) {
            return '';
        }
    }
}
