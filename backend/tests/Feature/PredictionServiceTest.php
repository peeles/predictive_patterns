<?php

namespace Tests\Feature;

use App\Enums\PredictionStatus;
use App\Events\PredictionStatusUpdated;
use App\Jobs\GenerateHeatmapJob;
use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Services\PredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PredictionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_prediction_dispatches_broadcast_and_job(): void
    {
        Event::fake([PredictionStatusUpdated::class]);
        Bus::fake();

        $dataset = Dataset::factory()->create();
        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
        ]);

        $service = app(PredictionService::class);

        $prediction = $service->queuePrediction(
            $model,
            $dataset,
            ['center' => ['lat' => 0, 'lng' => 0]],
            false
        );

        $this->assertNotNull($prediction);
        $this->assertEquals(PredictionStatus::Queued, $prediction->status);

        Event::assertDispatched(PredictionStatusUpdated::class, function (PredictionStatusUpdated $event) use ($prediction): bool {
            return $event->predictionId === $prediction->id
                && $event->status === PredictionStatus::Queued->value
                && $event->progress === 0.0;
        });

        Bus::assertDispatched(GenerateHeatmapJob::class);
    }
}
