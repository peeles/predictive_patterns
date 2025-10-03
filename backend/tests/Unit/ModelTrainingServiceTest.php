<?php

namespace Tests\Unit;

use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\ModelTrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Phpml\Exception\FileException;
use Phpml\Exception\InvalidArgumentException;
use Phpml\Exception\InvalidOperationException;
use Phpml\Exception\LibsvmCommandException;
use Phpml\Exception\NormalizerException;
use Phpml\Exception\SerializeException;
use Tests\TestCase;

class ModelTrainingServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @throws LibsvmCommandException
     * @throws NormalizerException
     * @throws InvalidOperationException
     * @throws JsonException
     * @throws InvalidArgumentException
     * @throws FileException
     * @throws SerializeException
     */
    public function test_train_builds_model_and_metrics_from_dataset(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/unit-test.csv',
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

        $service = app(ModelTrainingService::class);

        $result = $service->train($run, $model, [
            'learning_rate' => 0.25,
            'iterations' => 800,
            'validation_split' => 0.2,
        ]);

        $this->assertSame(['artifact_path'], array_keys($result['metadata']));
        $this->assertTrue(Storage::disk('local')->exists($result['artifact_path']));

        $artifact = json_decode(Storage::disk('local')->get($result['artifact_path']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('model_file', $artifact);
        $this->assertArrayHasKey('normalization', $artifact);
        $this->assertSame('l2', $artifact['normalization']['type'] ?? null);
        $this->assertArrayHasKey('imputer', $artifact);
        $this->assertArrayHasKey('feature_importances', $artifact);
        $this->assertArrayHasKey('grid_search', $artifact);
        $this->assertTrue(Storage::disk('local')->exists($artifact['model_file']));

        $this->assertEqualsWithDelta(1.0, $result['metrics']['accuracy'], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['metrics']['macro_precision'], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['metrics']['macro_recall'], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['metrics']['macro_f1'], 1.0);

        $this->assertNotEmpty($result['version']);
        $this->assertEqualsWithDelta(0.25, $result['hyperparameters']['learning_rate'], 0.5);
        $this->assertEqualsWithDelta(800, $result['hyperparameters']['iterations'], 400);
        $this->assertEqualsWithDelta(0.2, $result['hyperparameters']['validation_split'], 0.5);
        $this->assertEqualsWithDelta(0.01, $result['hyperparameters']['l2_penalty'], 0.5);
        $this->assertSame(200, $result['hyperparameters']['log_interval']);
        $this->assertSame('logistic_regression', $result['hyperparameters']['model_type']);
    }

    /**
     * @throws LibsvmCommandException
     * @throws NormalizerException
     * @throws InvalidArgumentException
     * @throws InvalidOperationException
     * @throws SerializeException
     * @throws FileException
     */
    public function test_train_throws_when_dataset_file_missing(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/missing.csv',
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

        $this->expectExceptionMessage('Dataset file "datasets/missing.csv" was not found.');

        $service = app(ModelTrainingService::class);
        $service->train($run, $model);
    }

    /**
     * @throws NormalizerException
     * @throws LibsvmCommandException
     * @throws InvalidArgumentException
     * @throws InvalidOperationException
     * @throws FileException
     * @throws SerializeException
     */
    public function test_train_handles_headers_with_bom_and_spacing(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/header-variant.csv',
            'mime_type' => 'text/csv',
        ]);

        Storage::disk('local')->put($dataset->file_path, $this->datasetCsvWithFormattedHeaders());

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

        $service = app(ModelTrainingService::class);
        $result = $service->train($run, $model);

        $this->assertEqualsWithDelta(1.0, $result['metrics']['accuracy'], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['metrics']['macro_precision'], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['metrics']['macro_recall'], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['metrics']['macro_f1'], 1.0);
    }

    /**
     * @throws LibsvmCommandException
     * @throws NormalizerException
     * @throws InvalidArgumentException
     * @throws InvalidOperationException
     * @throws SerializeException
     * @throws FileException
     */
    public function test_train_generates_risk_and_label_when_missing(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/missing-columns.csv',
            'mime_type' => 'text/csv',
        ]);

        Storage::disk('local')->put($dataset->file_path, $this->datasetCsvWithoutRiskOrLabel());

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

        $service = app(ModelTrainingService::class);

        $result = $service->train($run, $model);

        $this->assertArrayHasKey('metrics', $result);
        $this->assertSame(
            ['accuracy', 'macro_precision', 'macro_recall', 'macro_f1', 'weighted_precision', 'weighted_recall', 'weighted_f1', 'per_class', 'confusion_matrix', 'auc'],
            array_keys($result['metrics'])
        );
    }

    /**
     * @throws LibsvmCommandException
     * @throws NormalizerException
     * @throws InvalidArgumentException
     * @throws InvalidOperationException
     * @throws FileException
     * @throws SerializeException
     */
    public function test_train_uses_schema_mapping_to_resolve_columns(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/mapped.csv',
            'mime_type' => 'text/csv',
            'schema_mapping' => [
                'timestamp' => 'event_time',
                'latitude' => 'lat_deg',
                'longitude' => 'lon_deg',
                'category' => 'incident_type',
                'risk' => 'risk_index',
                'label' => 'label_flag',
            ],
        ]);

        Storage::disk('local')->put($dataset->file_path, $this->datasetCsvWithSchemaMapping());

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

        $service = app(ModelTrainingService::class);

        $result = $service->train($run, $model);

        $this->assertEqualsWithDelta(1.0, $result['metrics']['accuracy'], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['metrics']['macro_precision'], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['metrics']['macro_recall'], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['metrics']['macro_f1'], 1.0);
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

    private function datasetCsvWithoutRiskOrLabel(): string
    {
        return implode("\n", [
            'timestamp,latitude,longitude,category',
            '2024-01-01T00:00:00Z,40.0,-73.9,burglary',
            '2024-01-02T06:00:00Z,40.0,-73.9,burglary',
            '2024-01-03T12:00:00Z,40.0,-73.9,burglary',
            '2024-01-04T18:00:00Z,40.0,-73.9,burglary',
            '2024-01-05T00:00:00Z,40.0,-73.9,assault',
            '2024-01-06T06:00:00Z,40.0,-73.9,assault',
            '2024-01-07T12:00:00Z,40.0,-73.9,assault',
            '2024-01-08T18:00:00Z,40.0,-73.9,assault',
            '2024-01-09T00:00:00Z,40.0,-73.9,burglary',
            '2024-01-10T06:00:00Z,40.0,-73.9,assault',
            '',
        ]);
    }

    private function datasetCsvWithSchemaMapping(): string
    {
        return implode("\n", [
            'event_time,lat_deg,lon_deg,incident_type,risk_index,label_flag',
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

    private function datasetCsvWithFormattedHeaders(): string
    {
        return implode("\n", [
            "\u{FEFF}Timestamp, Latitude ,Longitude , Category ,Risk Score ,Label",
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
