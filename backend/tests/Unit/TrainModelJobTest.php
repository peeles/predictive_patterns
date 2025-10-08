<?php

namespace Tests\Unit;

use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Jobs\TrainModelJob;
use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\ModelStatusService;
use App\Services\ModelTrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TrainModelJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_persists_metrics_and_artifact_reference(): void
    {
        Storage::fake('local');
        Event::fake();
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/job.csv',
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

        $job = new TrainModelJob($run->id, [
            'learning_rate' => 0.25,
            'iterations' => 800,
            'validation_split' => 0.2,
        ]);

        $job->handle(
            app(ModelTrainingService::class),
            app(ModelStatusService::class),
        );

        $run->refresh();
        $model->refresh();

        $this->assertEquals(TrainingStatus::Completed, $run->status);
        $this->assertEquals(ModelStatus::Active, $model->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($model->trained_at);
        $this->assertEquals(
            ['accuracy', 'macro_precision', 'macro_recall', 'macro_f1', 'weighted_precision', 'weighted_recall', 'weighted_f1', 'per_class', 'confusion_matrix', 'auc'],
            array_keys($model->metrics)
        );
        $this->assertEqualsWithDelta(1.0, $model->metrics['accuracy'], 1.0);
        $this->assertEqualsWithDelta(1.0, $run->metrics['accuracy'], 1.0);
        $this->assertSame($run->metrics, $model->metrics);
        $this->assertNotEmpty($model->version);
        $this->assertNotEmpty($model->metadata['artifact_path']);
        $this->assertTrue(Storage::disk('local')->exists($model->metadata['artifact_path']));
        $this->assertEqualsWithDelta(0.25, $model->hyperparameters['learning_rate'], 0.5);
        $this->assertEqualsWithDelta(0.01, $model->hyperparameters['l2_penalty'], 0.5);
        $this->assertSame(200, $model->hyperparameters['log_interval']);
        $this->assertSame('logistic_regression', $model->hyperparameters['model_type']);
        $this->assertSame($model->hyperparameters, $run->hyperparameters);
    }

    public function test_handle_trains_svc_without_type_errors(): void
    {
        Storage::fake('local');
        Event::fake();
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/svc-job.csv',
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

        $job = new TrainModelJob($run->id, [
            'model_type' => 'svc',
            'cost' => 1.0,
            'tolerance' => 0.001,
            'cache_size' => 100.0,
            'shrinking' => true,
            'probability_estimates' => false,
            'kernel' => 'rbf',
            'kernel_options' => ['gamma' => 0.5],
        ]);

        $job->handle(
            app(ModelTrainingService::class),
            app(ModelStatusService::class),
        );

        $run->refresh();
        $model->refresh();

        $this->assertEquals(TrainingStatus::Completed, $run->status);
        $this->assertEquals(ModelStatus::Active, $model->status);
        $this->assertSame('svc', $model->hyperparameters['model_type']);
        $this->assertSame('svc', $run->hyperparameters['model_type']);
        $this->assertContainsEquals( $model->hyperparameters['kernel'], ['linear', 'poly', 'rbf', 'sigmoid', 'precomputed']);
        $this->assertArrayHasKey('cost', $model->hyperparameters);
        $this->assertArrayHasKey('tolerance', $model->hyperparameters);
    }

    public function test_handle_marks_run_failed_when_training_service_throws(): void
    {
        Storage::fake('local');
        Event::fake();
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/missing-job.csv',
        ]);

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => ModelStatus::Draft,
            'metadata' => [],
        ]);

        $run = TrainingRun::query()->create([
            'model_id' => $model->id,
            'status' => TrainingStatus::Queued,
            'queued_at' => now(),
        ]);

        $job = new TrainModelJob($run->id);

        try {
            $job->handle(
                app(ModelTrainingService::class),
                app(ModelStatusService::class),
            );
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Dataset file', $exception->getMessage());
        }

        $run->refresh();
        $model->refresh();

        $this->assertEquals(TrainingStatus::Failed, $run->status);
        $this->assertEquals(ModelStatus::Failed, $model->status);
        $this->assertNotNull($run->error_message);
        $this->assertNotNull($run->finished_at);
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
