<?php

namespace Tests\Unit\Listeners;

use App\Domain\Models\Events\ModelStatusChanged;
use App\Domain\Models\Events\ModelTrained;
use App\Enums\ModelStatus;
use App\Events\ModelStatusUpdated;
use App\Listeners\BroadcastModelStatusUpdate;
use App\Models\PredictiveModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastModelStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_broadcasts_model_status_changes(): void
    {
        Event::fake([ModelStatusUpdated::class]);

        $listener = app(BroadcastModelStatusUpdate::class);
        $listener->handle(new ModelStatusChanged('model-123', 'training', 50.0, now()->toIso8601String(), 'Halfway there'));

        Event::assertDispatched(ModelStatusUpdated::class, function (ModelStatusUpdated $event): bool {
            return $event->modelId === 'model-123'
                && $event->status === 'training'
                && $event->progress === 50.0
                && $event->message === 'Halfway there';
        });
    }

    public function test_listener_broadcasts_model_trained_event(): void
    {
        $model = PredictiveModel::factory()->create([
            'status' => ModelStatus::Active,
            'trained_at' => now(),
        ]);

        Event::fake([ModelStatusUpdated::class]);

        $listener = app(BroadcastModelStatusUpdate::class);
        $listener->handle(new ModelTrained($model));

        Event::assertDispatched(ModelStatusUpdated::class, function (ModelStatusUpdated $event) use ($model): bool {
            return $event->modelId === $model->getKey()
                && $event->status === ModelStatus::Active->value
                && $event->progress === 100.0;
        });
    }
}
