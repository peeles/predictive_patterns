<?php

namespace Tests\Unit\Services;

use App\Enums\DatasetStatus;
use App\Events\DatasetStatusChanged;
use App\Jobs\CompleteDatasetIngestion;
use App\Models\Dataset;
use App\Services\DatasetProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DatasetProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function testQueueFinaliseRunsSynchronouslyWhenUsingSyncDriver(): void
    {
        config([
            'queue.default' => 'sync',
            'queue.connections.sync.driver' => 'sync',
        ]);

        Bus::fake();
        Event::fake([
            DatasetStatusChanged::class,
        ]);

        $dataset = Dataset::factory()->create([
            'status' => DatasetStatus::Processing,
            'ingested_at' => null,
        ]);

        $service = app(DatasetProcessingService::class);

        $service->queueFinalise($dataset);

        Bus::assertNotDispatched(CompleteDatasetIngestion::class);
        Event::assertDispatched(DatasetStatusChanged::class, function (DatasetStatusChanged $event) use ($dataset) {
            return $event->datasetId === $dataset->getKey()
                && $event->status === DatasetStatus::Ready
                && $event->progress === 1.0;
        });

        $dataset->refresh();

        $this->assertSame(DatasetStatus::Ready, $dataset->status);
        $this->assertNotNull($dataset->ingested_at);
    }

    public function testQueueFinaliseThrowsQueueConnectionExceptionWhenQueueUnavailable(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'queue',
            'database.redis.queue.host' => 'redis.test',
        ]);

        Log::spy();

        $dataset = Dataset::factory()->create([
            'status' => DatasetStatus::Processing,
            'ingested_at' => null,
        ]);

        $service = app(DatasetProcessingService::class);

        $dispatcher = Mockery::mock(Dispatcher::class)->shouldIgnoreMissing();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Redis connection refused'));

        app()->instance(Dispatcher::class, $dispatcher);

        $this->expectException(\App\Exceptions\QueueConnectionException::class);
        $this->expectExceptionMessage('"redis" queue connection');

        try {
            $service->queueFinalise($dataset, [], [], true);
        } catch (\App\Exceptions\QueueConnectionException $exception) {
            Log::shouldHaveReceived('error')->once()->with(
                'Failed to queue dataset finalisation due to queue connection failure.',
                Mockery::on(function (array $context) use ($dataset) {
                    return ($context['dataset_id'] ?? null) === $dataset->getKey()
                        && ($context['connection'] ?? null) === config('queue.default')
                        && ($context['host'] ?? null) === config('database.redis.queue.host');
                })
            );

            throw $exception;
        } finally {
            app()->forgetInstance(Dispatcher::class);
        }
    }
}
