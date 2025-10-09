<?php

namespace Tests\Unit\Services;

use App\Events\Datasets\DatasetIngestionCompleted;
use App\Events\Datasets\DatasetIngestionProgressed;
use App\Enums\DatasetStatus;
use App\Events\DatasetStatusUpdated;
use App\Jobs\CompleteDatasetIngestion;
use App\Models\Dataset;
use App\Services\DatasetProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
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
            DatasetIngestionProgressed::class,
            DatasetIngestionCompleted::class,
            DatasetStatusUpdated::class,
        ]);

        $dataset = Dataset::factory()->create([
            'status' => DatasetStatus::Processing,
            'ingested_at' => null,
        ]);

        $service = app(DatasetProcessingService::class);

        $service->queueFinalise($dataset);

        Bus::assertNotDispatched(CompleteDatasetIngestion::class);
        Event::assertDispatched(DatasetIngestionCompleted::class, function (DatasetIngestionCompleted $event) use ($dataset) {
            return $event->dataset->is($dataset);
        });
        Event::assertDispatched(DatasetIngestionProgressed::class);
        Event::assertDispatched(DatasetStatusUpdated::class, function (DatasetStatusUpdated $event) use ($dataset) {
            return $event->datasetId === $dataset->getKey()
                && $event->progress === 1.0
                && $event->status === DatasetStatus::Ready;
        });

        $dataset->refresh();

        $this->assertSame(DatasetStatus::Ready, $dataset->status);
        $this->assertNotNull($dataset->ingested_at);
    }
}
