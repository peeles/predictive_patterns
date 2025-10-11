<?php

namespace App\Support\Queue;

class QueueConnectionDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public static function describe(string $connection): array
    {
        $config = config(sprintf('queue.connections.%s', $connection), []);
        $driver = (string) ($config['driver'] ?? 'unknown');

        $details = [
            'connection' => $connection,
            'driver' => $driver,
        ];

        if ($driver === 'redis') {
            $redisConnection = $config['connection'] ?? 'default';

            if (! is_string($redisConnection) || trim($redisConnection) === '') {
                $redisConnection = 'default';
            }

            $redisConfig = config(sprintf('database.redis.%s', $redisConnection), []);
            $details['redis_connection'] = $redisConnection;
            $details['host'] = (string) ($redisConfig['host'] ?? '127.0.0.1');
            $details['port'] = (string) ($redisConfig['port'] ?? '6379');

            return $details;
        }

        $fallbackRedisConfig = config('database.redis.queue', []);

        if (is_array($fallbackRedisConfig) && $fallbackRedisConfig !== []) {
            $details['redis_connection'] = 'queue';
            $details['host'] = (string) ($fallbackRedisConfig['host'] ?? '127.0.0.1');
            $details['port'] = (string) ($fallbackRedisConfig['port'] ?? '6379');
        }

        return $details;
    }
}
