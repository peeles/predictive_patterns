<?php

namespace Tests\Unit\Support\Queue;

use App\Support\Queue\FallbackRedisConnector;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Log\LogRecord;
use Illuminate\Queue\Queue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Log;
use RedisException;
use Tests\TestCase;

class FallbackRedisConnectorTest extends TestCase
{
    public function test_it_returns_redis_connection_when_available(): void
    {
        Log::fake();

        $connector = new class(
            $this->app->make(RedisFactory::class),
            $this->app['events'],
            1
        ) extends FallbackRedisConnector {
            protected function connectToRedis(array $config): Queue
            {
                return new SyncQueue($this->events);
            }
        };

        $queue = $connector->connect([]);

        $this->assertInstanceOf(SyncQueue::class, $queue);
        Log::assertNothingLogged();
    }

    public function test_it_falls_back_to_sync_and_recovers_when_redis_returns(): void
    {
        Log::fake();

        $connector = new class(
            $this->app->make(RedisFactory::class),
            $this->app['events'],
            0
        ) extends FallbackRedisConnector {
            public bool $shouldFail = true;

            protected function connectToRedis(array $config): Queue
            {
                if ($this->shouldFail) {
                    throw new RedisException('Connection refused');
                }

                return new SyncQueue($this->events);
            }
        };

        $firstQueue = $connector->connect([]);

        $this->assertInstanceOf(SyncQueue::class, $firstQueue);

        Log::assertLogged('warning', function (LogRecord $record) {
            return str_contains($record->message, 'Redis queue connection unavailable')
                && ($record->context['exception'] ?? null) === 'Connection refused';
        });

        $connector->shouldFail = false;

        $secondQueue = $connector->connect([]);

        $this->assertInstanceOf(SyncQueue::class, $secondQueue);

        Log::assertLogged('info', function (LogRecord $record) {
            return str_contains($record->message, 'Redis queue connection restored');
        });
    }
}
