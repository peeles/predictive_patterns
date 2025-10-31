<?php

namespace Tests\Feature\Observers;

use App\Enums\ModelStatus;
use App\Events\ModelStatusUpdated;
use App\Models\PredictiveModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PredictiveModelObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcasts_status_changes_with_enriched_payload(): void
    {
        Event::fake([ModelStatusUpdated::class]);

        $model = PredictiveModel::factory()->create([
            'status' => ModelStatus::Draft,
            'metrics' => ['accuracy' => 0.92],
        ]);

        $model->status = ModelStatus::Training;
        $model->save();

        $updatedAt = $model->fresh()->updated_at?->toIso8601String();

        Event::assertDispatched(ModelStatusUpdated::class, function (ModelStatusUpdated $event) use ($model): bool {
            return $event->modelId === $model->getKey()
                && $event->status === ModelStatus::Training->value
                && $event->progress === null
                && $event->trainingMetrics === ['accuracy' => 0.92]
                && $event->errorMessage === null;
        });
    }

    public function test_does_not_broadcast_when_irrelevant_attributes_change(): void
    {
        Event::fake([ModelStatusUpdated::class]);

        $model = PredictiveModel::factory()->create([
            'status' => ModelStatus::Active,
        ]);

        $model->name = 'Updated Model Name';
        $model->save();

        Event::assertNotDispatched(ModelStatusUpdated::class);
    }
}
