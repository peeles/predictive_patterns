<?php

declare(strict_types=1);

namespace App\Support\Broadcasting;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class BroadcastDispatcher
{
    /**
     * Flag used to prevent repeated broadcast attempts once the transport is known to be unavailable.
     */
    private static bool $transportUnavailable = false;

    /**
     * Dispatch a broadcastable event with proper error handling.
     *
     * @param object $event
     * @param array<string, mixed> $context
     */
    public static function dispatch(object $event, array $context = []): void
    {
        if (self::$transportUnavailable) {
            return;
        }

        try {
            Event::dispatch($event);
        } catch (BroadcastException $exception) {
            if (self::isTransportIssue($exception)) {
                self::markTransportUnavailable($event, $exception, $context);

                return;
            }

            Log::error('Broadcast failed', array_merge([
                'event' => $event::class,
                'driver' => config('broadcasting.default'),
                'exception' => $exception->getMessage(),
            ], $context));
        } catch (Throwable $exception) {
            if (self::isTransportIssue($exception)) {
                self::markTransportUnavailable($event, $exception, $context);

                return;
            }

            Log::error('Unexpected broadcast error', array_merge([
                'event' => $event::class,
                'exception' => $exception->getMessage(),
            ], $context));
        }
    }

    private static function isTransportIssue(Throwable $exception): bool
    {
        if (class_exists('RedisException') && $exception instanceof \RedisException) {
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

        if ($previous instanceof Throwable) {
            return self::isTransportIssue($previous);
        }

        return false;
    }

    /**
     * Mark the broadcast transport as unavailable and log a warning with context.
     *
     * @param object $event
     * @param Throwable $exception
     * @param array<string, mixed> $context
     */
    private static function markTransportUnavailable(object $event, Throwable $exception, array $context = []): void
    {
        self::$transportUnavailable = true;

        Log::warning('Broadcast transport unavailable, suppressing further attempts.', array_merge([
            'event' => $event::class,
            'driver' => config('broadcasting.default'),
            'exception' => $exception->getMessage(),
            'exception_class' => $exception::class,
        ], $context));
    }

    /**
     * Reset the internal suppression flag. Intended for testing purposes.
     */
    public static function resetSuppressedTransport(): void
    {
        self::$transportUnavailable = false;
    }
}
