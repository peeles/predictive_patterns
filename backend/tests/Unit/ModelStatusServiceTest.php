<?php

namespace Tests\Unit;

use App\Enums\ModelStatus;
use App\Events\ModelStatusUpdated;
use App\Models\PredictiveModel;
use App\Services\ModelStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ModelStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_progress_persists_status_and_emits_event(): void
    {
        $model = PredictiveModel::factory()->create([
            'status' => ModelStatus::Draft,
        ]);

        Event::fake();
        Redis::shouldReceive('setex')->once()->andReturnTrue();
        Redis::shouldReceive('publish')->once()->andReturnTrue();

        $service = app(ModelStatusService::class);
        $snapshot = $service->markProgress($model->id, 'training', 42.5);

        $this->assertSame('training', $snapshot['state']);
        $this->assertSame(42.5, $snapshot['progress']);
        $this->assertNotEmpty($snapshot['updated_at']);

        Event::assertDispatched(ModelStatusUpdated::class, function (ModelStatusUpdated $event) use ($model): bool {
            return $event->modelId === $model->id && $event->state === 'training' && $event->progress === 42.5;
        });
    }

    public function test_get_status_returns_database_status_when_cache_is_empty(): void
    {
        $model = PredictiveModel::factory()->create([
            'status' => ModelStatus::Active,
        ]);

        Redis::shouldReceive('get')->once()->andReturn(null);

        $service = app(ModelStatusService::class);
        $status = $service->getStatus($model);

        $this->assertSame('active', $status['state']);
        $this->assertNull($status['progress']);
        $this->assertNotEmpty($status['updated_at']);
    }
}
