<?php

namespace App\Exceptions;

use App\Support\Queue\QueueConnectionDiagnostics;
use RuntimeException;
use Throwable;

class QueueConnectionException extends RuntimeException
{
    public static function forConnection(string $connection, Throwable $previous): self
    {
        $diagnostics = QueueConnectionDiagnostics::describe($connection);
        $driver = (string) ($diagnostics['driver'] ?? 'unknown');

        $diagnosticMessage = '';

        if (($diagnostics['driver'] ?? null) === 'redis') {
            $redisConnection = (string) ($diagnostics['redis_connection'] ?? 'default');
            $host = (string) ($diagnostics['host'] ?? '127.0.0.1');
            $port = (string) ($diagnostics['port'] ?? '6379');

            $diagnosticMessage = sprintf(
                ' Attempted Redis connection "%s" at %s:%s.',
                $redisConnection,
                $host,
                $port
            );
        }

        $message = sprintf(
            'Unable to connect to the "%s" queue connection using the "%s" driver.%s Original error: %s',
            $connection,
            $driver,
            $diagnosticMessage,
            $previous->getMessage()
        );

        return new self($message, 0, $previous);
    }
}
