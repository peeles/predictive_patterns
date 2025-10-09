<?php

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class BroadcastFallbackServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $fallback = Config::get('broadcasting.fallback.pusher');

        if (! is_array($fallback)) {
            return;
        }

        $enabled = (bool) ($fallback['enabled'] ?? false);
        $connection = (string) ($fallback['connection'] ?? 'log');
        $missing = $this->determineMissingCredentials($connection);
        $available = $this->requiresCredentials($connection) ? $missing === [] : true;

        Config::set('broadcasting.fallback.pusher', array_merge($fallback, [
            'enabled' => $enabled,
            'connection' => $connection,
            'requested' => $enabled,
            'available' => $available,
            'missing' => $missing,
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function determineMissingCredentials(string $connection): array
    {
        if ($connection === 'pusher') {
            $credentials = [
                'PUSHER_APP_ID' => Config::get('broadcasting.connections.pusher.app_id'),
                'PUSHER_APP_KEY' => Config::get('broadcasting.connections.pusher.key'),
                'PUSHER_APP_SECRET' => Config::get('broadcasting.connections.pusher.secret'),
            ];

            return $this->missingCredentialKeys($credentials);
        }

        if ($connection === 'ably') {
            $credentials = [
                'ABLY_KEY' => Config::get('broadcasting.connections.ably.key'),
            ];

            return $this->missingCredentialKeys($credentials);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<int, string>
     */
    private function missingCredentialKeys(array $credentials): array
    {
        $missing = [];

        foreach ($credentials as $key => $value) {
            if ($value === null || $value === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function requiresCredentials(string $connection): bool
    {
        return in_array($connection, ['pusher', 'ably'], true);
    }
}
