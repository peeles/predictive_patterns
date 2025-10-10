<?php

namespace Tests\Feature\Listeners;

use App\Domain\Models\Events\ModelStatusChanged;
use App\Events\ModelStatusUpdated;
use App\Listeners\BroadcastModelStatusUpdate;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastModelStatusUpdateTest extends TestCase
{
    public function test_broadcasts_model_status_update(): void
    {
        Event::fake([ModelStatusUpdated::class]);

        $event = new ModelStatusChanged(
            modelId: '123',
            state: 'training',
            progress: 50.0,
            updatedAt: now()->toIso8601String(),
            message: 'Training in progress'
        );

        (new BroadcastModelStatusUpdate())->handle($event);

        Event::assertDispatched(ModelStatusUpdated::class, function (ModelStatusUpdated $e): bool {
            return $e->modelId === '123'
                && $e->progress === 50.0;
        });
    }
}
