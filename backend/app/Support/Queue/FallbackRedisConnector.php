<?php

namespace App\Support\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Connectors\RedisConnector;
use Illuminate\Queue\Queue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Log;
use RedisException;
use Throwable;

class FallbackRedisConnector extends RedisConnector
{
    /**
     * Indicates if the connector is currently suppressing connection attempts.
     */
    private bool $suppressed = false;

    /**
     * The unix timestamp until which connection attempts should be suppressed.
     */
    private ?int $suppressedUntil = null;

    /**
     * Create a new connector instance.
     */
    public function __construct(
        RedisFactory $redis,
        Dispatcher $events,
        private readonly int $suppressionSeconds = 300
    ) {
        parent::__construct($redis, $events);
    }

    /**
     * Establish a queue connection.
     *
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): Queue
    {
        $suppressedBeforeAttempt = $this->suppressed;

        if ($this->shouldResetSuppression()) {
            $this->resetSuppression();
        }

        if ($this->suppressed) {
            return $this->createFallbackQueue();
        }

        try {
            $queue = $this->connectToRedis($config);

            if ($suppressedBeforeAttempt) {
                $this->logRestored();
            }

            return $queue;
        } catch (Throwable $exception) {
            if (! $this->isConnectionIssue($exception)) {
                throw $exception;
            }

            $this->markSuppressed($exception);

            return $this->createFallbackQueue();
        }
    }

    /**
     * Attempt to connect to Redis using the base connector implementation.
     *
     * @param  array<string, mixed>  $config
     */
    protected function connectToRedis(array $config): Queue
    {
        return parent::connect($config);
    }

    private function shouldResetSuppression(): bool
    {
        return $this->suppressed && $this->suppressedUntil !== null && time() >= $this->suppressedUntil;
    }

    private function resetSuppression(): void
    {
        $this->suppressed = false;
        $this->suppressedUntil = null;
    }

    private function createFallbackQueue(): SyncQueue
    {
        return new SyncQueue($this->events);
    }

    private function markSuppressed(Throwable $exception): void
    {
        $this->suppressed = true;
        $this->suppressedUntil = time() + $this->suppressionSeconds;

        Log::warning('Redis queue connection unavailable, falling back to sync queue.', [
            'exception' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'retry_at' => $this->suppressedUntil !== null ? date('c', $this->suppressedUntil) : null,
        ]);
    }

    private function logRestored(): void
    {
        Log::info('Redis queue connection restored, resuming Redis driver usage.');
    }

    private function isConnectionIssue(Throwable $exception): bool
    {
        if ($exception instanceof RedisException) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        if ($message !== '' && (
            str_contains($message, 'connection refused')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'could not connect')
            || str_contains($message, 'connection timed out')
        )) {
            return true;
        }

        $previous = $exception->getPrevious();

        return $previous instanceof Throwable
            ? $this->isConnectionIssue($previous)
            : false;
    }
}
