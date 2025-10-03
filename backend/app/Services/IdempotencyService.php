<?php

namespace App\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;

class IdempotencyService
{
    public function __construct(private readonly CacheRepository $cache)
    {
    }

    public function getCachedResponse(Request $request, string $operation, ?string $scope = null): ?array
    {
        $cacheKey = $this->resolveCacheKey($request, $operation, $scope);

        if ($cacheKey === null) {
            return null;
        }

        $cached = $this->cache->get($cacheKey);

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function storeResponse(Request $request, string $operation, array $response, ?string $scope = null): void
    {
        $cacheKey = $this->resolveCacheKey($request, $operation, $scope);

        if ($cacheKey === null) {
            return;
        }

        $ttl = (int) config('api.idempotency_ttl', 300);

        if ($ttl <= 0) {
            $ttl = 300;
        }

        $this->cache->put($cacheKey, $response, $ttl);
    }

    private function resolveCacheKey(Request $request, string $operation, ?string $scope = null): ?string
    {
        $header = $request->header('Idempotency-Key');

        if ($header === null) {
            return null;
        }

        $header = trim($header);

        if ($header === '') {
            return null;
        }

        $payloadParts = [$header];

        $identity = $this->resolveUserIdentity($request);

        if ($identity !== null) {
            array_unshift($payloadParts, $identity);
        }

        if ($scope !== null) {
            array_unshift($payloadParts, $scope);
        }

        return sprintf('idempotency:%s:%s', $operation, sha1(implode('|', $payloadParts)));
    }

    private function resolveUserIdentity(Request $request): ?string
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            $identifier = $user->getAuthIdentifier();

            if ($identifier !== null) {
                return 'user:'.$identifier;
            }
        }

        $bearerToken = $request->bearerToken();

        return $bearerToken !== null ? 'token:'.sha1($bearerToken) : null;
    }
}
