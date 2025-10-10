<?php

namespace Tests\Unit\Listeners;

use App\Enums\DatasetStatus;
use App\Events\DatasetStatusChanged;
use App\Events\DatasetStatusUpdated;
use App\Listeners\BroadcastDatasetStatus;
use App\Models\Dataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastDatasetStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_broadcasts_dataset_status_update(): void
    {
        $dataset = Dataset::factory()->create([
            'status' => DatasetStatus::Processing,
        ]);

        Event::fake([DatasetStatusUpdated::class]);

        $listener = app(BroadcastDatasetStatus::class);
        $listener->handle(DatasetStatusChanged::fromDataset($dataset, 0.5, 'Processing dataset'));

        Event::assertDispatched(DatasetStatusUpdated::class, function (DatasetStatusUpdated $event) use ($dataset) {
            return $event->datasetId === $dataset->getKey()
                && $event->status === DatasetStatus::Processing
                && $event->progress === 0.5
                && $event->message === 'Processing dataset';
        });
    }
}
