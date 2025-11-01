<?php

namespace Tests\Feature\Jobs;

use App\Enums\ModelStatus;
use App\Jobs\TrainModelJob;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\ModelStatusService;
use App\Services\ModelTrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class TrainModelJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_job_is_dispatched_to_training_queue(): void
    {
        Queue::fake();

        $run = TrainingRun::factory()->create();

        TrainModelJob::dispatch($run->id);

        Queue::assertPushedOn('training', TrainModelJob::class);
    }

    public function test_job_is_unique(): void
    {
        Queue::fake();

        $run = TrainingRun::factory()->create();

        TrainModelJob::dispatch($run->id);
        TrainModelJob::dispatch($run->id);

        Queue::assertPushed(TrainModelJob::class, 1);
    }

    public function test_job_updates_model_status_on_completion(): void
    {
        Cache::spy(); // Spy on cache to prevent Redis connection issues in CI

        $model = PredictiveModel::factory()->create([
            'status' => ModelStatus::Draft,
            'metrics' => null,
            'hyperparameters' => null,
        ]);

        $run = TrainingRun::factory()->for($model, 'model')->create();

        $trainingService = Mockery::mock(ModelTrainingService::class);
        $trainingService->shouldReceive('train')
            ->once()
            ->andReturn([
                'metrics' => ['accuracy' => 0.95],
                'metadata' => ['artifact_path' => 'models/'.$model->getKey().'/artifact.json'],
                'version' => '20240101000000',
                'hyperparameters' => ['learning_rate' => 0.1],
            ]);

        $statusService = Mockery::mock(ModelStatusService::class);
        $statusService->shouldReceive('markProgress')->atLeast()->once();
        $statusService->shouldReceive('markIdle')->once();

        (new TrainModelJob($run->id))->handle($trainingService, $statusService);

        $this->assertEquals(
            ModelStatus::Active,
            $run->model->fresh()->status
        );
    }
}
