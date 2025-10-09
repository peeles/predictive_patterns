<?php

declare(strict_types=1);

namespace App\Support\Broadcasting;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RedisException;
use Throwable;

class BroadcastDispatcher
{
    /**
     * Dispatch a broadcastable event while swallowing transport failures.
     *
     * @param object $event
     * @param array<string, mixed> $context
     */
    public static function dispatch(object $event, array $context = []): void
    {
        $driver = config('broadcasting.default', 'pusher');
        $fallbackEnabled = (bool)config('broadcasting.fallback.pusher.enabled', false);
        $fallbackRequested = (bool)config('broadcasting.fallback.pusher.requested', false);
        $fallbackAvailable = (bool)config('broadcasting.fallback.pusher.available', false);
        $fallbackMissing = (array)config('broadcasting.fallback.pusher.missing', []);
        $fallbackConnection = (string)config('broadcasting.fallback.pusher.connection', 'pusher');

        try {
            Event::dispatch($event);

            return;
        } catch (BroadcastException $exception) {
            Log::error('Broadcast transport failed.', array_merge([
                'event' => $event::class,
                'exception' => $exception,
                'driver' => $driver,
                'pusher_fallback_enabled' => $fallbackEnabled,
                'pusher_fallback_requested' => $fallbackRequested,
                'pusher_fallback_available' => $fallbackAvailable,
                'pusher_fallback_missing' => $fallbackMissing,
            ], $context));

            if (!$fallbackEnabled || $driver === $fallbackConnection) {
                if ($fallbackRequested && !$fallbackAvailable) {
                    Log::notice('Broadcast fallback skipped because credentials are missing.', array_merge([
                        'event' => $event::class,
                        'missing_credentials' => $fallbackMissing,
                    ], $context));
                }

                return;
            }

            try {
                Config::set('broadcasting.default', $fallbackConnection);
                Event::dispatch($event);

                Log::notice('Broadcast fallback dispatched via Pusher.', array_merge([
                    'event' => $event::class,
                    'driver' => $driver,
                    'fallback_driver' => $fallbackConnection,
                    'pusher_fallback_available' => $fallbackAvailable,
                ], $context));
            } catch (BroadcastException $fallbackException) {
                Log::error('Broadcast fallback transport failed.', array_merge([
                    'event' => $event::class,
                    'driver' => $driver,
                    'fallback_driver' => $fallbackConnection,
                    'exception' => $fallbackException,
                    'pusher_fallback_available' => $fallbackAvailable,
                ], $context));
            } finally {
                Config::set('broadcasting.default', $driver);
            }
        } catch (Throwable $exception) {
            if (self::isConnectionFailure($exception)) {
                $queueConnection = (string) config('queue.default', 'sync');

                Log::warning('Broadcast dispatcher falling back to synchronous queue.', array_merge([
                    'event' => $event::class,
                    'exception' => $exception,
                    'driver' => $driver,
                    'queue_connection' => $queueConnection,
                ], $context));

                if ($queueConnection !== 'sync') {
                    Config::set('queue.default', 'sync');
                }

                try {
                    Event::dispatch($event);

                    Log::notice('Broadcast dispatcher dispatched event via synchronous queue after connection failure.', array_merge([
                        'event' => $event::class,
                        'driver' => $driver,
                        'queue_connection' => $queueConnection,
                    ], $context));
                } catch (Throwable $fallbackException) {
                    Log::error('Broadcast dispatcher synchronous fallback failed.', array_merge([
                        'event' => $event::class,
                        'exception' => $fallbackException,
                        'driver' => $driver,
                        'queue_connection' => $queueConnection,
                    ], $context));
                } finally {
                    if ($queueConnection !== 'sync') {
                        Config::set('queue.default', $queueConnection);
                    }
                }

                return;
            }

            Log::error('Broadcast dispatcher encountered an unexpected exception.', array_merge([
                'event' => $event::class,
                'exception' => $exception,
                'driver' => $driver,
                'pusher_fallback_enabled' => $fallbackEnabled,
                'pusher_fallback_requested' => $fallbackRequested,
                'pusher_fallback_available' => $fallbackAvailable,
                'pusher_fallback_missing' => $fallbackMissing,
            ], $context));
        }
    }

    private static function isConnectionFailure(Throwable $exception): bool
    {
        if ($exception instanceof RedisException) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        if ($message !== '' && str_contains($message, 'connection refused')) {
            return true;
        }

        $previous = $exception->getPrevious();

        if ($previous instanceof Throwable) {
            return self::isConnectionFailure($previous);
        }

        return false;
    }
}
