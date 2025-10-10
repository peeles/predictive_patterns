<?php

declare(strict_types=1);

namespace App\Support\Broadcasting;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RedisException;
use Throwable;

class BroadcastDispatcher
{
    private static bool $transportUnavailable = false;
    private static ?int $unavailableUntil = null;
    private static int $resetAfterSeconds = 300; // 5 minutes

    public static function dispatch(object $event, array $context = []): void
    {
        // Check if circuit breaker should reset
        if (self::$transportUnavailable && self::shouldResetCircuitBreaker()) {
            self::resetCircuitBreaker();
        }

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

    private static function shouldResetCircuitBreaker(): bool
    {
        return self::$unavailableUntil !== null && time() > self::$unavailableUntil;
    }

    private static function resetCircuitBreaker(): void
    {
        Log::info('Broadcast circuit breaker reset, retrying broadcasts');
        self::$transportUnavailable = false;
        self::$unavailableUntil = null;
    }

    private static function markTransportUnavailable(object $event, Throwable $exception, array $context = []): void
    {
        self::$transportUnavailable = true;
        self::$unavailableUntil = time() + self::$resetAfterSeconds;

        Log::warning('Broadcast transport unavailable, suppressing further attempts.', array_merge([
            'event' => $event::class,
            'driver' => config('broadcasting.default'),
            'exception' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'reset_at' => date('Y-m-d H:i:s', self::$unavailableUntil),
        ], $context));
    }

    private static function isTransportIssue(Throwable $exception): bool
    {
        if (class_exists('RedisException') && $exception instanceof RedisException) {
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

    public static function resetSuppressedTransport(): void
    {
        self::$transportUnavailable = false;
        self::$unavailableUntil = null;
    }
}
