<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class SanctumTokenManager
{
    public const ACCESS_TOKEN_TTL_MINUTES = 60;

    public const REFRESH_TOKEN_TTL_DAYS = 30;

    public const ACCESS_ABILITY = 'access-api';

    public const REFRESH_ABILITY = 'refresh-api';

    public const REFRESH_COOKIE_NAME = 'refresh_token';

    private const TOKEN_PREFIX = 'auth';

    /**
     * Issue a new pair of access and refresh tokens for the provided user.
     *
     * @return array{accessToken: string, refreshToken: string, expiresIn: int}
     */
    public static function issue(User $user): array
    {
        $sessionId = (string) Str::uuid();

        $accessToken = $user->createToken(
            self::accessTokenName($sessionId),
            abilities: [self::ACCESS_ABILITY],
            expiresAt: Carbon::now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES),
        );

        $refreshToken = $user->createToken(
            self::refreshTokenName($sessionId),
            abilities: [self::REFRESH_ABILITY],
            expiresAt: Carbon::now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
        );

        return [
            'accessToken' => $accessToken->plainTextToken,
            'refreshToken' => $refreshToken->plainTextToken,
            'expiresIn' => self::ACCESS_TOKEN_TTL_MINUTES * 60,
        ];
    }

    /**
     * Attempt to rotate tokens using the provided refresh token.
     *
     * @return array{tokens: array{accessToken: string, refreshToken: string, expiresIn: int}, user: User}|null
     */
    public static function refresh(string $plainRefreshToken): ?array
    {
        $token = PersonalAccessToken::findToken($plainRefreshToken);

        if ($token === null || ! $token->can(self::REFRESH_ABILITY)) {
            return null;
        }

        if (self::isExpired($token)) {
            $token->delete();

            return null;
        }

        $user = $token->tokenable;

        if (! $user instanceof User) {
            $token->delete();

            return null;
        }

        $sessionId = self::extractSessionId($token->name);

        if ($sessionId === null) {
            $token->delete();

            return null;
        }

        self::revokeSessionTokens($user, $sessionId);

        return [
            'tokens' => self::issue($user),
            'user' => $user,
        ];
    }

    public static function revoke(?string $plainToken): void
    {
        if ($plainToken === null) {
            return;
        }

        $token = PersonalAccessToken::findToken($plainToken);

        if ($token === null) {
            return;
        }

        $user = $token->tokenable;
        $sessionId = self::extractSessionId($token->name);

        if ($user instanceof User && $sessionId !== null) {
            self::revokeSessionTokens($user, $sessionId);

            return;
        }

        $token->delete();
    }

    public static function resolveAccessToken(?string $plainToken): ?PersonalAccessToken
    {
        if ($plainToken === null) {
            return null;
        }

        $token = PersonalAccessToken::findToken($plainToken);

        if ($token === null || ! $token->can(self::ACCESS_ABILITY)) {
            return null;
        }

        if (self::isExpired($token)) {
            $token->delete();

            return null;
        }

        $user = $token->tokenable;

        if (! $user instanceof User) {
            $token->delete();

            return null;
        }

        return $token;
    }

    private static function revokeSessionTokens(User $user, string $sessionId): void
    {
        PersonalAccessToken::query()
            ->where('tokenable_id', $user->getKey())
            ->where('tokenable_type', $user->getMorphClass())
            ->whereIn('name', [
                self::accessTokenName($sessionId),
                self::refreshTokenName($sessionId),
            ])->delete();
    }

    private static function accessTokenName(string $sessionId): string
    {
        return sprintf('%s:access|%s', self::TOKEN_PREFIX, $sessionId);
    }

    private static function refreshTokenName(string $sessionId): string
    {
        return sprintf('%s:refresh|%s', self::TOKEN_PREFIX, $sessionId);
    }

    private static function extractSessionId(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        [$prefix, $sessionId] = array_pad(explode('|', $name, 2), 2, null);

        if (! is_string($prefix) || ! str_starts_with($prefix, self::TOKEN_PREFIX.':')) {
            return null;
        }

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private static function isExpired(PersonalAccessToken $token): bool
    {
        return $token->expires_at !== null && $token->expires_at->lte(Carbon::now());
    }
}
