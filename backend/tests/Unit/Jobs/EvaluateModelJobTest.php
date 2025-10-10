<?php

namespace Tests\Unit\Jobs;

use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Jobs\EvaluateModelJob;
use App\Jobs\Factories\ModelJobFactory;
use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\ModelEvaluationService;
use App\Services\ModelStatusService;
use App\Services\ModelTrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class EvaluateModelJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_dispatches_on_training_queue(): void
    {
        Queue::fake();

        config(['queue.connections.training.queue' => 'training']);

        dispatch(ModelJobFactory::evaluation('model-id'));

        Queue::assertPushed(EvaluateModelJob::class, function (EvaluateModelJob $job): bool {
            return $job->connection === 'training' && $job->queue === 'training';
        });
    }

    public function test_handle_persists_evaluation_metrics(): void
    {
        Storage::fake('local');
        Event::fake();
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/evaluate.csv',
            'mime_type' => 'text/csv',
        ]);

        Storage::disk('local')->put($dataset->file_path, $this->datasetCsv());

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => ModelStatus::Draft,
            'metadata' => [],
            'metrics' => null,
            'hyperparameters' => null,
        ]);

        $run = TrainingRun::query()->create([
            'model_id' => $model->id,
            'status' => TrainingStatus::Queued,
            'queued_at' => now(),
        ]);

        $trainingService = app(ModelTrainingService::class);
        $trainingResult = $trainingService->train($run, $model);

        $model->update([
            'metadata' => array_merge($model->metadata ?? [], ['artifact_path' => $trainingResult['artifact_path']]),
        ]);

        $job = new EvaluateModelJob($model->id, $dataset->id);
        $job->handle(
            app(ModelStatusService::class),
            app(ModelEvaluationService::class),
        );

        $model->refresh();

        $this->assertArrayHasKey('evaluations', $model->metadata);
        $this->assertCount(1, $model->metadata['evaluations']);

        $evaluation = $model->metadata['evaluations'][0];

        $this->assertSame($dataset->id, $evaluation['dataset_id']);
        $this->assertEquals(
            ['accuracy', 'macro_precision', 'macro_recall', 'macro_f1', 'weighted_precision', 'weighted_recall', 'weighted_f1', 'per_class', 'confusion_matrix', 'auc'],
            array_keys($evaluation['metrics'])
        );
        $this->assertEqualsWithDelta(1.0, $evaluation['metrics']['accuracy'], 1.0);
    }

    public function test_handle_marks_status_failed_on_evaluation_error(): void
    {
        Storage::fake('local');
        Event::fake();
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/missing-evaluate.csv',
        ]);

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => ModelStatus::Draft,
            'metadata' => [],
        ]);

        Storage::disk('local')->put('models/fake-artifact.json', json_encode([
            'model_file' => 'models/fake-artifact.model',
            'feature_means' => array_fill(0, 5, 0.0),
            'feature_std_devs' => array_fill(0, 5, 1.0),
            'categories' => [],
            'normalization' => ['type' => 'l2'],
            'imputer' => ['strategy' => 'mean', 'statistics' => array_fill(0, 5, 0.0)],
            'metrics' => ['accuracy' => 1.0],
            'hyperparameters' => ['model_type' => 'logistic_regression'],
            'feature_importances' => [],
        ]));
        Storage::disk('local')->put('models/fake-artifact.model', 'stub');

        $model->update([
            'metadata' => ['artifact_path' => 'models/fake-artifact.json'],
        ]);

        $job = new EvaluateModelJob($model->id, $dataset->id);

        try {
            $job->handle(
                app(ModelStatusService::class),
                app(ModelEvaluationService::class),
            );
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Evaluation dataset', $exception->getMessage());
        }

        $model->refresh();

        $this->assertArrayNotHasKey('evaluations', $model->metadata ?? []);
    }

    private function datasetCsv(): string
    {
        return implode("\n", [
            'timestamp,latitude,longitude,category,risk_score,label',
            '2024-01-01T00:00:00Z,40.0,-73.9,burglary,0.10,0',
            '2024-01-02T00:00:00Z,40.0,-73.9,burglary,0.12,0',
            '2024-01-03T00:00:00Z,40.0,-73.9,burglary,0.14,0',
            '2024-01-04T00:00:00Z,40.0,-73.9,burglary,0.18,0',
            '2024-01-05T00:00:00Z,40.0,-73.9,assault,0.72,1',
            '2024-01-06T00:00:00Z,40.0,-73.9,assault,0.74,1',
            '2024-01-07T00:00:00Z,40.0,-73.9,assault,0.78,1',
            '2024-01-08T00:00:00Z,40.0,-73.9,assault,0.82,1',
            '2024-01-09T00:00:00Z,40.0,-73.9,burglary,0.28,0',
            '2024-01-10T00:00:00Z,40.0,-73.9,assault,0.88,1',
            '',
        ]);
    }
}
