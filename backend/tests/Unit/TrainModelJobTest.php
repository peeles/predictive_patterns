<?php

declare(strict_types=1);

use App\Domain\Models\Events\ModelTrained;
use App\Events\ProgressUpdated;
use App\Jobs\TrainModelJob;
use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\ModelStatusService;
use App\Services\ModelTrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('local');
});

afterEach(function (): void {
    Mockery::close();
});

class TestableTrainModelJob extends TrainModelJob
{
    /**
     * @var list<array{progress: int, message: string, metrics: array|null}>
     */
    public array $progressEvents = [];

    protected function updateProgress(int $progress, string $message, ?array $metrics = null): void
    {
        $this->progressEvents[] = [
            'progress' => $progress,
            'message' => $message,
            'metrics' => $metrics,
        ];

        parent::updateProgress($progress, $message, $metrics);
    }
}

it('emits staged progress updates with metrics during training', function (): void {
    Event::fake([ProgressUpdated::class, ModelTrained::class]);

    $dataset = Dataset::factory()->create([
        'file_path' => 'datasets/training.csv',
    ]);
    Storage::disk('local')->put('datasets/training.csv', "f1,label\n1,A");

    $model = PredictiveModel::factory()->create([
        'dataset_id' => $dataset->id,
        'metadata' => [],
        'metrics' => [],
    ]);

    $run = TrainingRun::factory()->create([
        'model_id' => $model->id,
        'hyperparameters' => ['epochs' => 4],
    ]);

    $job = new TestableTrainModelJob($run->id, null, null, (string) $run->initiated_by);

    $trainingService = Mockery::mock(ModelTrainingService::class);
    $trainingService
        ->shouldReceive('train')
        ->once()
        ->andReturnUsing(function ($actualRun, $actualModel, $hyperparameters, $callback) use ($run, $model) {
            expect($actualRun->is($run))->toBeTrue();
            expect($actualModel->is($model))->toBeTrue();
            expect($hyperparameters)->toBe($run->hyperparameters ?? []);

            $callback(55.0, 'Halfway through training');
            $callback(82.0, null);

            return [
                'metrics' => ['loss' => 0.1234, 'accuracy' => 0.91],
                'artifact_path' => 'models/example.model',
                'version' => '2.0.0',
                'metadata' => ['source' => 'test'],
                'hyperparameters' => ['epochs' => 4],
            ];
        });

    $statusService = Mockery::mock(ModelStatusService::class);
    $statusService->shouldReceive('markProgress')->andReturn([]);
    $statusService->shouldReceive('markIdle')->once()->andReturn([]);
    $statusService->shouldReceive('markFailed')->never();

    $job->handle($trainingService, $statusService);

    $progresses = array_column($job->progressEvents, 'progress');
    expect($progresses)->toContain(0, 5, 15, 25, 35, 45, 55, 75, 95, 100);

    $finalProgress = end($job->progressEvents);
    expect($finalProgress)
        ->toBeArray()
        ->and($finalProgress['progress'])->toBe(100)
        ->and($finalProgress['metrics']['loss'])->toBe(0.1234)
        ->and($finalProgress['metrics']['accuracy'])->toBe(0.91)
        ->and($finalProgress['metrics']['current_epoch'])->toBe(4)
        ->and($finalProgress['metrics']['total_epochs'])->toBe(4);

    $cacheKey = sprintf('progress.%s.training', $model->id);
    $payload = Cache::get($cacheKey);

    expect($payload)
        ->toBeArray()
        ->and($payload['percent'])->toBe(100.0)
        ->and($payload['accuracy'])->toBe(0.91)
        ->and($payload['loss'])->toBe(0.1234)
        ->and($payload['current_epoch'])->toBe(4)
        ->and($payload['total_epochs'])->toBe(4)
        ->and($payload['message'])->toBe('Training complete');

    Event::assertDispatched(ProgressUpdated::class, function (ProgressUpdated $event) use ($model): bool {
        return $event->entityId === $model->id
            && abs($event->percent - 100.0) < 0.001
            && ($event->metrics['accuracy'] ?? null) === 0.91;
    });

    $model->refresh();
    $run->refresh();

    expect($run->status->value)->toBe('completed')
        ->and($model->status->value)->toBe('active')
        ->and($model->metadata['training_progress']['percent'])->toEqual(100.0);
});
