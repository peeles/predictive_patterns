<?php

namespace Tests\Unit\Support;

use App\Support\Broadcasting\BroadcastDispatcher;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class BroadcastDispatcherTest extends TestCase
{
    public function test_it_logs_broadcast_exception_details(): void
    {
        config(['broadcasting.default' => 'pusher']);

        Event::shouldReceive('dispatch')
            ->once()
            ->withArgs(fn ($event): bool => $event instanceof FakeBroadcastEvent)
            ->andThrow(new BroadcastException('Transport failure.'));

        Log::spy();

        BroadcastDispatcher::dispatch(new FakeBroadcastEvent());

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Broadcast failed'
                    && ($context['driver'] ?? null) === 'pusher'
                    && ($context['event'] ?? null) === FakeBroadcastEvent::class
                    && ($context['exception'] ?? null) === 'Transport failure.';
            });
    }

    public function test_it_logs_unexpected_exceptions_without_fallback(): void
    {
        Event::shouldReceive('dispatch')
            ->once()
            ->withArgs(fn ($event): bool => $event instanceof FakeBroadcastEvent)
            ->andThrow(new RuntimeException('Unexpected failure.'));

        Log::spy();

        BroadcastDispatcher::dispatch(new FakeBroadcastEvent());

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Unexpected broadcast error'
                    && ($context['event'] ?? null) === FakeBroadcastEvent::class
                    && ($context['exception'] ?? null) === 'Unexpected failure.';
            });
    }
}

class FakeBroadcastEvent
{
}
