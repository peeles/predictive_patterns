<?php

declare(strict_types=1);

namespace App\Support\Broadcasting;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

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
        $driver = config('broadcasting.default', 'reverb');
        $fallbackEnabled = (bool) config('broadcasting.fallback.pusher.enabled', false);
        $fallbackRequested = (bool) config('broadcasting.fallback.pusher.requested', false);
        $fallbackAvailable = (bool) config('broadcasting.fallback.pusher.available', false);
        $fallbackMissing = (array) config('broadcasting.fallback.pusher.missing', []);
        $fallbackConnection = (string) config('broadcasting.fallback.pusher.connection', 'pusher');

        try {
            Event::dispatch($event);
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

            if (! $fallbackEnabled || $driver === $fallbackConnection) {
                if ($fallbackRequested && ! $fallbackAvailable) {
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
        }
    }
}
