<?php

namespace Tests\Unit\Services;

use App\Enums\DatasetStatus;
use App\Events\DatasetStatusChanged;
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
}
