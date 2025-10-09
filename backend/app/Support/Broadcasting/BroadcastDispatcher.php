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
     * Dispatch a broadcastable event with proper error handling.
     *
     * @param object $event
     * @param array<string, mixed> $context
     */
    public static function dispatch(object $event, array $context = []): void
    {
        try {
            Event::dispatch($event);
        } catch (BroadcastException $exception) {
            Log::error('Broadcast failed', array_merge([
                'event' => $event::class,
                'driver' => config('broadcasting.default'),
                'exception' => $exception->getMessage(),
            ], $context));
        } catch (Throwable $exception) {
            Log::error('Unexpected broadcast error', array_merge([
                'event' => $event::class,
                'exception' => $exception->getMessage(),
            ], $context));
        }
    }
}
