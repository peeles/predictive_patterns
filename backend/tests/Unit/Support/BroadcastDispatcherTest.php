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
    public function test_it_logs_transport_failure_when_fallback_disabled(): void
    {
        $this->setFallbackConfig([
            'enabled' => false,
            'connection' => 'log',
            'requested' => false,
            'available' => true,
            'missing' => [],
        ]);

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
                return $message === 'Broadcast transport failed.'
                    && ($context['driver'] ?? null) === 'pusher'
                    && ($context['event'] ?? null) === FakeBroadcastEvent::class;
            });

        Log::shouldNotHaveReceived('notice');
    }

    public function test_it_attempts_fallback_connection_when_available(): void
    {
        $this->setFallbackConfig([
            'enabled' => true,
            'connection' => 'log',
            'requested' => true,
            'available' => true,
            'missing' => [],
        ]);

        config(['broadcasting.default' => 'pusher']);

        Event::shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->withArgs(fn ($event): bool => $event instanceof FakeBroadcastEvent)
            ->andThrow(new BroadcastException('Primary transport failure.'));

        Event::shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->withArgs(fn ($event): bool => $event instanceof FakeBroadcastEvent)
            ->andReturnNull();

        Log::spy();

        BroadcastDispatcher::dispatch(new FakeBroadcastEvent());

        $this->assertSame('pusher', config('broadcasting.default'));

        Log::shouldHaveReceived('notice')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Broadcast fallback dispatched via Pusher.'
                    && ($context['event'] ?? null) === FakeBroadcastEvent::class
                    && ($context['fallback_driver'] ?? null) === 'log';
            });
    }

    public function test_it_switches_to_sync_queue_on_connection_failure(): void
    {
        $this->setFallbackConfig([
            'enabled' => true,
            'connection' => 'log',
            'requested' => true,
            'available' => true,
            'missing' => [],
        ]);

        config([
            'broadcasting.default' => 'pusher',
            'queue.default' => 'redis',
        ]);

        Event::shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->withArgs(fn ($event): bool => $event instanceof FakeBroadcastEvent)
            ->andThrow(new RuntimeException('Connection refused'));

        Event::shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->withArgs(fn ($event): bool => $event instanceof FakeBroadcastEvent)
            ->andReturnNull();

        Log::spy();

        BroadcastDispatcher::dispatch(new FakeBroadcastEvent());

        $this->assertSame('redis', config('queue.default'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Broadcast dispatcher falling back to synchronous queue.'
                    && ($context['driver'] ?? null) === 'pusher'
                    && ($context['queue_connection'] ?? null) === 'redis';
            });
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function setFallbackConfig(array $overrides): void
    {
        config(['broadcasting.fallback.pusher' => $overrides]);
    }
}

class FakeBroadcastEvent
{
}
