<?php

namespace Tests\Unit\Services;

use App\Domain\Models\Events\ModelStatusChanged;
use App\Enums\ModelStatus;
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

        Event::fake([ModelStatusChanged::class]);
        Redis::shouldReceive('get')->once()->andReturn(null);
        Redis::shouldReceive('setex')->once()->andReturnTrue();
        Redis::shouldReceive('publish')->once()->andReturnTrue();

        $service = app(ModelStatusService::class);
        $snapshot = $service->markProgress($model->id, 'training', 42.5);

        $this->assertSame('training', $snapshot['state']);
        $this->assertSame(42.5, $snapshot['progress']);
        $this->assertNotEmpty($snapshot['updated_at']);

        Event::assertDispatched(ModelStatusChanged::class, function (ModelStatusChanged $event) use ($model): bool {
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

    public function test_duplicate_snapshots_do_not_emit_additional_events(): void
    {
        $model = PredictiveModel::factory()->create([
            'status' => ModelStatus::Draft,
        ]);

        Event::fake([ModelStatusChanged::class]);

        $firstSnapshot = null;

        Redis::shouldReceive('get')
            ->twice()
            ->andReturn(null, function () use (&$firstSnapshot): ?string {
                return $firstSnapshot !== null ? json_encode($firstSnapshot) : null;
            });

        Redis::shouldReceive('setex')->twice()->andReturnTrue();
        Redis::shouldReceive('publish')->twice()->andReturnTrue();

        $service = app(ModelStatusService::class);

        $firstSnapshot = $service->markQueued($model->id, 'evaluating');
        $secondSnapshot = $service->markQueued($model->id, 'evaluating');

        $this->assertSame($firstSnapshot['updated_at'], $secondSnapshot['updated_at']);

        Event::assertDispatchedTimes(ModelStatusChanged::class, 2);
    }
}
