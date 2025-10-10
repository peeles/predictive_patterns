<?php

namespace Tests\Unit\Jobs;

use App\Enums\PredictionOutputFormat;
use App\Enums\PredictionStatus;
use App\Enums\TrainingStatus;
use App\Events\PredictionStatusUpdated;
use App\Jobs\GenerateHeatmapJob;
use App\Models\Dataset;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\ModelTrainingService;
use App\Support\Phpml\ImputerFactory;
use App\Support\ProbabilityScoreExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use JsonException;
use Phpml\Exception\FileException;
use Phpml\Exception\InvalidOperationException;
use Phpml\Exception\LibsvmCommandException;
use Phpml\Exception\NormalizerException;
use Phpml\Exception\SerializeException;
use Phpml\ModelManager;
use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Normalizer;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class GenerateHeatmapJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the job correctly generates a heatmap from a trained model artifact.
     *
     * @throws LibsvmCommandException
     * @throws NormalizerException
     * @throws InvalidOperationException
     * @throws JsonException
     * @throws Throwable
     * @throws \Phpml\Exception\InvalidArgumentException
     * @throws SerializeException
     * @throws FileException
     */
    public function test_handle_generates_heatmap_from_trained_artifact(): void
    {
        Storage::fake('local');
        Event::fake([PredictionStatusUpdated::class]);

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/unit-test.csv',
            'mime_type' => 'text/csv',
        ]);

        Storage::disk('local')->put($dataset->file_path, $this->datasetCsv());

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'metadata' => [],
            'metrics' => null,
        ]);

        $run = TrainingRun::query()->create([
            'model_id' => $model->id,
            'status' => TrainingStatus::Queued,
            'queued_at' => now(),
        ]);

        $trainingService = app(ModelTrainingService::class);

        $result = $trainingService->train($run, $model, [
            'learning_rate' => 0.25,
            'iterations' => 800,
            'validation_split' => 0.2,
        ]);

        $model->forceFill([
            'metadata' => ['artifact_path' => $result['artifact_path']],
            'metrics' => $result['metrics'],
        ])->save();

        $parameters = [
            'center' => ['lat' => 40.0, 'lng' => -73.9],
            'radius_km' => 10,
            'observed_at' => '2024-01-10T00:00:00Z',
            'horizon_hours' => 240,
        ];

        $prediction = Prediction::query()->create([
            'model_id' => $model->id,
            'dataset_id' => $dataset->id,
            'status' => PredictionStatus::Queued,
            'parameters' => $parameters,
            'queued_at' => now(),
        ]);

        $job = new GenerateHeatmapJob($prediction->id, $parameters, true);
        $job->handle();

        Event::assertDispatchedTimes(PredictionStatusUpdated::class, 4);
        Event::assertDispatched(PredictionStatusUpdated::class, function (PredictionStatusUpdated $event) use ($prediction): bool {
            return $event->predictionId === $prediction->id
                && $event->status === PredictionStatus::Running->value
                && $event->progress === 0.45;
        });
        Event::assertDispatched(PredictionStatusUpdated::class, function (PredictionStatusUpdated $event) use ($prediction): bool {
            return $event->predictionId === $prediction->id
                && $event->status === PredictionStatus::Completed->value
                && $event->progress === 1.0;
        });

        $prediction->refresh()->load(['outputs', 'shapValues']);

        $this->assertEquals(PredictionStatus::Completed, $prediction->status);
        $this->assertCount(2, $prediction->outputs);

        $jsonOutput = $prediction->outputs
            ->firstWhere('format', PredictionOutputFormat::Json);
        $this->assertNotNull($jsonOutput);

        $payload = $jsonOutput->payload;
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('heatmap', $payload);
        $this->assertArrayHasKey('top_features', $payload);

        $artifact = json_decode(
            Storage::disk('local')->get($result['artifact_path']),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $rows = $this->loadDatasetRows($dataset->file_path);
        $expectedScores = $this->scoreDataset($rows, $artifact, $parameters);

        $expectedMean = array_sum($expectedScores) / count($expectedScores);
        $expectedMax = max($expectedScores);

        $this->assertEqualsWithDelta($expectedMean, $payload['summary']['mean_score'], 0.0001);
        $this->assertEqualsWithDelta($expectedMax, $payload['summary']['max_score'], 0.0001);

        $this->assertNotEmpty($payload['heatmap']['points']);
        $this->assertNotEmpty($payload['top_features']);

        $this->assertCount(count($payload['top_features']), $prediction->shapValues);

        foreach ($prediction->shapValues as $index => $shapValue) {
            $expected = $payload['top_features'][$index];

            $this->assertContainsEquals($expected['name'], ['Risk Score', 'Category Assault', 'Category Burglary', 'Day Of Week', 'Hour Of Day', 'Latitude', 'Longitude']);
            $this->assertEqualsWithDelta((float) $expected['contribution'], (float) $shapValue->value, 0.9999);
            $this->assertNull($shapValue->details);
        }

        $tilesOutput = $prediction->outputs
            ->firstWhere('format', PredictionOutputFormat::Tiles);
        $this->assertNotNull($tilesOutput);
        $this->assertTrue(
            Storage::disk('local')->exists($tilesOutput->tileset_path . '/heatmap.json')
        );
    }

    public function test_handle_uses_latest_artifact_from_disk_when_metadata_missing(): void
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
            'metadata' => null,
            'metrics' => null,
        ]);

        $run = TrainingRun::query()->create([
            'model_id' => $model->id,
            'status' => TrainingStatus::Queued,
            'queued_at' => now(),
        ]);

        $trainingService = app(ModelTrainingService::class);

        $result = $trainingService->train($run, $model, [
            'learning_rate' => 0.2,
            'iterations' => 600,
            'validation_split' => 0.25,
        ]);

        // Simulate missing metadata for legacy models that were trained previously.
        $model->forceFill(['metadata' => null])->save();

        $parameters = [
            'center' => ['lat' => 40.0, 'lng' => -73.9],
            'radius_km' => 5,
            'observed_at' => '2024-01-01T00:00:00Z',
            'horizon_hours' => 24,
        ];

        $prediction = Prediction::query()->create([
            'model_id' => $model->id,
            'dataset_id' => $dataset->id,
            'status' => PredictionStatus::Queued,
            'parameters' => $parameters,
            'queued_at' => now(),
        ]);

        $job = new GenerateHeatmapJob($prediction->id, $parameters);
        $job->handle();

        $prediction->refresh()->load('outputs');

        $this->assertEquals(PredictionStatus::Completed, $prediction->status);
        $this->assertCount(1, $prediction->outputs);
        $this->assertTrue(Storage::disk('local')->exists($result['artifact_path']));
    }

    public function test_handle_honours_schema_mapping_for_predictions(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/mapped-unit-test.csv',
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

        Storage::disk('local')->put($dataset->file_path, $this->mappedDatasetCsv());

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'metadata' => [],
            'metrics' => null,
        ]);

        $run = TrainingRun::query()->create([
            'model_id' => $model->id,
            'status' => TrainingStatus::Queued,
            'queued_at' => now(),
        ]);

        $trainingService = app(ModelTrainingService::class);

        $result = $trainingService->train($run, $model, [
            'learning_rate' => 0.2,
            'iterations' => 600,
            'validation_split' => 0.25,
        ]);

        $model->forceFill([
            'metadata' => ['artifact_path' => $result['artifact_path']],
            'metrics' => $result['metrics'],
        ])->save();

        $parameters = [
            'center' => ['lat' => 40.6, 'lng' => -73.95],
            'radius_km' => 20,
        ];

        $prediction = Prediction::query()->create([
            'model_id' => $model->id,
            'dataset_id' => $dataset->id,
            'status' => PredictionStatus::Queued,
            'parameters' => $parameters,
            'queued_at' => now(),
        ]);

        $job = new GenerateHeatmapJob($prediction->id, $parameters);
        $job->handle();

        $prediction->refresh()->load('outputs');

        $this->assertEquals(PredictionStatus::Completed, $prediction->status);
        $this->assertNotEmpty($prediction->outputs);

        $artifact = json_decode(
            Storage::disk('local')->get($result['artifact_path']),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $datasetContext = $this->invokeLoadDatasetRows($job, $prediction);
        $entries = $this->invokePrepareEntries(
            $job,
            $datasetContext['rows'],
            $artifact['categories'],
            $datasetContext['column_map']
        );

        $this->assertNotEmpty($entries);

        $first = $entries[0];

        $this->assertInstanceOf(CarbonImmutable::class, $first['timestamp']);
        $this->assertSame('robbery', $first['category']);

        $summary = $prediction->outputs->first()->payload['summary'] ?? [];
        $this->assertGreaterThanOrEqual(count($entries), $summary['count'] ?? 0);
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

    private function mappedDatasetCsv(): string
    {
        return implode("\n", [
            'event_time,lat_deg,lon_deg,incident_type,risk_index,label_flag',
            '2024-02-01T00:00:00Z,40.6,-73.95,robbery,0.42,1',
            '2024-02-02T00:00:00Z,40.6,-73.96,robbery,0.45,1',
            '2024-02-03T12:00:00Z,40.61,-73.95,assault,0.20,0',
        ]);
    }

    /**
     * @return array{
     *     rows: array<int, array<string, string|null>>,
     *     column_map: array<string, string>
     * }
     */
    private function invokeLoadDatasetRows(GenerateHeatmapJob $job, Prediction $prediction): array
    {
        $method = new ReflectionMethod(GenerateHeatmapJob::class, 'loadDatasetRows');
        $method->setAccessible(true);

        /** @var array{rows: array<int, array<string, string|null>>, column_map: array<string, string>} $result */
        $result = $method->invoke($job, $prediction);

        return $result;
    }

    /**
     * @param list<string> $categories
     *
     * @return list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}>
     */
    private function invokePrepareEntries(
        GenerateHeatmapJob $job,
        array $rows,
        array $categories,
        array $columnMap
    ): array {
        $method = new ReflectionMethod(GenerateHeatmapJob::class, 'prepareEntries');
        $method->setAccessible(true);

        /** @var LazyCollection<int, array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}>|list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}> $entries */
        $entries = $method->invoke($job, $rows, $categories, $columnMap);

        if ($entries instanceof LazyCollection) {
            $entries = $entries->values()->all();
        }

        return $entries;
    }

    /**
     * Load dataset rows from a CSV file.
     *
     * @param string $path
     *
     * @return array<int, array<string, string|null>>
     */
    private function loadDatasetRows(string $path): array
    {
        $absolute = Storage::disk('local')->path($path);
        $handle = fopen($absolute, 'rb');

        if ($handle === false) {
            $this->fail(sprintf('Unable to open dataset "%s"', $absolute));
        }

        try {
            $rows = [];
            $header = null;

            while (($data = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $data);
                    continue;
                }

                if ($data === [null] || $data === false) {
                    continue;
                }

                $row = [];

                foreach ($header as $index => $column) {
                    $row[$column] = $data[$index] ?? null;
                }

                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Score the dataset using the provided artifact and parameters.
     *
     * @param array<int, array<string, string|null>> $rows
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $parameters
     *
     * @return list<float>
     * @throws SerializeException
     * @throws FileException
     * @throws NormalizerException
     */
    private function scoreDataset(array $rows, array $artifact, array $parameters): array
    {
        $entries = $this->prepareEntries($rows, $artifact['categories']);
        $filtered = $this->filterEntries($entries, $parameters);

        if ($filtered === []) {
            $filtered = $entries;
        }

        $manager = new ModelManager();
        $classifier = $manager->restoreFromFile(Storage::disk('local')->path($artifact['model_file']));
        $context = [
            'means' => array_map('floatval', $artifact['feature_means']),
            'std_devs' => array_map(static fn ($value) => max(1e-6, (float) $value), $artifact['feature_std_devs']),
            'normalizer' => $this->normalizerFromArtifact($artifact),
            'imputer' => $this->imputerFromArtifact($artifact['imputer'] ?? null),
        ];

        return $this->scoreEntries($filtered, $classifier, $context);
    }

    /**
     * Prepare dataset entries by parsing and extracting features.
     *
     * @param array<int, array<string, string|null>> $rows
     * @param list<string> $artifactCategories
     *
     * @return list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}>
     */
    private function prepareEntries(array $rows, array $artifactCategories): array
    {
        $entries = [];
        $categories = array_values(array_map(static fn ($value) => (string) $value, $artifactCategories));

        foreach ($rows as $row) {
            $timestampString = (string) ($row['timestamp'] ?? '');
            if ($timestampString === '') {
                continue;
            }

            try {
                $timestamp = CarbonImmutable::parse($timestampString);
            } catch (Throwable $e) {
                continue;
            }

            $latitude = (float) ($row['latitude'] ?? 0.0);
            $longitude = (float) ($row['longitude'] ?? 0.0);
            $riskScore = (float) ($row['risk_score'] ?? 0.0);
            $category = (string) ($row['category'] ?? '');

            $hour = $timestamp->hour / 23.0;
            $dayOfWeek = ($timestamp->dayOfWeekIso - 1) / 6.0;

            $features = [$hour, $dayOfWeek, $latitude, $longitude, $riskScore];

            foreach ($categories as $expectedCategory) {
                $features[] = $expectedCategory === $category ? 1.0 : 0.0;
            }

            $entries[] = [
                'timestamp' => $timestamp,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'category' => $category,
                'features' => $features,
            ];
        }

        return $entries;
    }

    /**
     * Filter dataset entries based on the provided parameters.
     *
     * @param list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}> $entries
     * @param array<string, mixed> $parameters
     *
     * @return list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}>
     */
    private function filterEntries(array $entries, array $parameters): array
    {
        $center = $this->resolveCenter($parameters['center'] ?? null);
        $radiusKm = $this->resolveFloat($parameters['radius_km'] ?? null);
        $observedAt = $this->resolveTimestamp($parameters['observed_at'] ?? null);
        $horizonHours = $this->resolveFloat($parameters['horizon_hours'] ?? null);

        if ($center === null && $radiusKm !== null) {
            $radiusKm = null;
        }

        $start = null;
        $end = null;

        if ($observedAt !== null) {
            $windowHours = $horizonHours !== null ? max(0.0, $horizonHours) : 24.0;
            $windowMinutes = (int) round($windowHours * 60.0);

            if ($windowMinutes > 0) {
                $start = $observedAt->subMinutes($windowMinutes);
                $end = $observedAt->addMinutes($windowMinutes);
            } else {
                $end = $observedAt;
                $start = $observedAt->subHours(24);
            }
        }

        $filtered = [];

        foreach ($entries as $entry) {
            if ($center !== null && $radiusKm !== null) {
                $distance = $this->haversine($center['lat'], $center['lng'], $entry['latitude'], $entry['longitude']);

                if ($distance > $radiusKm) {
                    continue;
                }
            }

            if ($start !== null && $end !== null) {
                if ($entry['timestamp']->lt($start) || $entry['timestamp']->gt($end)) {
                    continue;
                }
            } elseif ($end !== null && $entry['timestamp']->gt($end)) {
                continue;
            }

            $filtered[] = $entry;
        }

        return $filtered;
    }

    /**
     * @param list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}> $entries
     * @param object $classifier
     * @param array $context
     *
     * @return list<float>
     */
    private function scoreEntries(array $entries, object $classifier, array $context): array
    {
        $means = $context['means'];
        $stdDevs = $context['std_devs'];
        $normalizer = $context['normalizer'];
        $imputer = $context['imputer'];

        $samples = [];

        foreach ($entries as $entry) {
            $samples[] = array_map('floatval', $entry['features']);
        }

        $imputer->transform($samples);

        $standardized = [];

        foreach ($samples as $sample) {
            $standardized[] = $this->normalizeRow($sample, $means, $stdDevs);
        }

        $normalizer->transform($standardized);

        if (method_exists($classifier, 'predictProbabilities')) {
            return ProbabilityScoreExtractor::extractList((array) $classifier->predictProbabilities($standardized));
        }

        $predictions = $classifier->predict($standardized);

        return ProbabilityScoreExtractor::extractList(
            array_map(static fn (int $value): float => $value >= 1 ? 1.0 : 0.0, $predictions)
        );
    }

    /**
     * Create a normalizer instance from the artifact data.
     *
     * @param array<string, mixed> $artifact
     *
     * @return Normalizer
     * @throws NormalizerException
     */
    private function normalizerFromArtifact(array $artifact): Normalizer
    {
        $type = Normalizer::NORM_L2;

        if (isset($artifact['normalization']) && is_array($artifact['normalization'])) {
            $type = $this->normalizeNormalizerValue($artifact['normalization']['type'] ?? null);
        }

        try {
            return new Normalizer($type);
        } catch (InvalidArgumentException) {
            return new Normalizer(Normalizer::NORM_L2);
        }
    }

    /**
     * Normalise the normaliser type value from the artifact.
     *
     * @param mixed $value
     *
     * @return int
     */
    private function normalizeNormalizerValue(mixed $value): int
    {
        $default = Normalizer::NORM_L2;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            $map = array_filter([
                'l1' => Normalizer::NORM_L1,
                'l2' => Normalizer::NORM_L2,
                'linf' => defined(Normalizer::class.'::NORM_LINF') ? (int) constant(Normalizer::class.'::NORM_LINF') : null,
                'inf' => defined(Normalizer::class.'::NORM_LINF') ? (int) constant(Normalizer::class.'::NORM_LINF') : null,
                'max' => defined(Normalizer::class.'::NORM_MAX') ? (int) constant(Normalizer::class.'::NORM_MAX') : null,
                'maxnorm' => defined(Normalizer::class.'::NORM_MAX') ? (int) constant(Normalizer::class.'::NORM_MAX') : null,
                'std' => Normalizer::NORM_STD,
                'zscore' => Normalizer::NORM_STD,
            ], static fn ($candidate) => $candidate !== null);

            if (array_key_exists($normalized, $map)) {
                return (int) $map[$normalized];
            }
        }

        return $default;
    }

    /**
     * Create an imputer instance from the artifact data.
     *
     * @param mixed $imputer
     *
     * @return Imputer
     */
    private function imputerFromArtifact(mixed $imputer): Imputer
    {
        $strategy = $imputer;
        $statistics = [];
        $missingValue = null;
        $fillValue = 0.0;

        if (is_array($imputer)) {
            $candidate = $imputer['strategy'] ?? null;

            if (is_string($candidate)) {
                $strategy = $candidate;
            }

            if (isset($imputer['statistics']) && is_array($imputer['statistics'])) {
                $statistics = array_map('floatval', $imputer['statistics']);
            }

            if (array_key_exists('missing_value', $imputer)) {
                $missingValue = $imputer['missing_value'];
            }

            if (array_key_exists('fill_value', $imputer)) {
                $fillValue = $imputer['fill_value'];
            }
        }

        return ImputerFactory::create($strategy, $missingValue, $statistics, $fillValue);
    }

    /**
     * @param list<float> $features
     * @param list<float> $means
     * @param list<float> $stdDevs
     *
     * @return list<float>
     */
    private function normalizeRow(array $features, array $means, array $stdDevs): array
    {
        $normalized = [];

        foreach ($features as $index => $value) {
            $mean = $means[$index] ?? 0.0;
            $std = $stdDevs[$index] ?? 1.0;
            $normalized[] = ($value - $mean) / ($std > 1e-12 ? $std : 1.0);
        }

        return $normalized;
    }

    private function resolveCenter(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $lat = $this->resolveFloat($value['lat'] ?? $value['latitude'] ?? $value[1] ?? null);
        $lng = $this->resolveFloat($value['lng'] ?? $value['lon'] ?? $value['longitude'] ?? $value[0] ?? null);

        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    private function resolveFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && $value !== '') {
            $parsed = filter_var($value, FILTER_VALIDATE_FLOAT);

            if ($parsed !== false) {
                return (float) $parsed;
            }
        }

        return null;
    }

    private function resolveTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);

        $a = sin($dLat / 2) ** 2 + sin($dLng / 2) ** 2 * cos($lat1Rad) * cos($lat2Rad);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return 6371.0 * $c;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function dotProduct(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $index => $value) {
            $sum += $value * ($b[$index] ?? 0.0);
        }

        return $sum;
    }

    private function sigmoid(float $value): float
    {
        if ($value < -60) {
            return 0.0;
        }

        if ($value > 60) {
            return 1.0;
        }

        return 1.0 / (1.0 + exp(-$value));
    }

    public function test_handle_broadcasts_failure_status_updates(): void
    {
        Storage::fake('local');
        Event::fake([PredictionStatusUpdated::class]);

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/missing.csv',
            'mime_type' => 'text/csv',
        ]);

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'metadata' => ['artifact_path' => 'models/' . $dataset->id . '/missing.json'],
        ]);

        $prediction = Prediction::query()->create([
            'model_id' => $model->id,
            'dataset_id' => $dataset->id,
            'status' => PredictionStatus::Queued,
            'parameters' => ['center' => ['lat' => 0.0, 'lng' => 0.0]],
            'queued_at' => now(),
        ]);

        $job = new GenerateHeatmapJob($prediction->id, $prediction->parameters ?? [], false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trained artifact for model is unavailable.');

        try {
            $job->handle();
        } finally {
            Event::assertDispatchedTimes(PredictionStatusUpdated::class, 2);
            Event::assertDispatched(PredictionStatusUpdated::class, function (PredictionStatusUpdated $event) use ($prediction): bool {
                return $event->predictionId === $prediction->id
                    && $event->status === PredictionStatus::Failed->value
                    && str_contains($event->message ?? '', 'Trained artifact');
            });
        }
    }
}
