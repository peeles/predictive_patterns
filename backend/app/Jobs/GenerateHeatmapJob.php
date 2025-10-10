<?php

namespace App\Jobs;

use App\Enums\PredictionOutputFormat;
use App\Enums\PredictionStatus;
use App\Events\PredictionStatusUpdated;
use App\Jobs\Middleware\LogJobExecution;
use App\Models\Dataset;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use App\Support\Phpml\ImputerFactory;
use App\Support\ProbabilityScoreExtractor;
use App\Support\Broadcasting\BroadcastDispatcher;
use App\Support\TimestampParser;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Phpml\ModelManager;
use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Normalizer;
use RuntimeException;
use Throwable;

class GenerateHeatmapJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private readonly string $predictionId,
        private readonly array $parameters,
        private readonly bool $generateTiles = false,
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LogJobExecution(),
            new RateLimited('default'),
        ];
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $prediction = Prediction::query()->with(['model', 'dataset'])->findOrFail($this->predictionId);

        $prediction->fill([
            'status' => PredictionStatus::Running,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        $this->broadcastStatus(
            $prediction,
            0.1,
            'Restoring trained model and preparing forecast inputs…'
        );

        try {
            $artifact = $this->loadLatestArtifact($prediction);
            $classifier = $this->restoreClassifier($artifact);
            $preprocessing = $this->buildPreprocessingContext($artifact);
            $datasetContext = $this->loadDatasetRows($prediction);
            $entries = $this->prepareEntries(
                $datasetContext['rows'],
                $artifact['categories'],
                $datasetContext['column_map']
            );
            $filtered = $this->filterEntries($entries, $this->parameters);
            $scored = $this->scoreEntries($filtered, $artifact, $classifier, $preprocessing);

            if ($scored === []) {
                $fallbackEntries = $this->prepareEntries(
                    $this->loadDatasetRows($prediction, $datasetContext['column_map'])['rows'],
                    $artifact['categories'],
                    $datasetContext['column_map']
                );

                $scored = $this->scoreEntries($fallbackEntries, $artifact, $classifier, $preprocessing);

                if ($scored === []) {
                    throw new RuntimeException('No usable dataset rows found for prediction.');
                }
            }

            $this->broadcastStatus(
                $prediction,
                0.45,
                'Scoring dataset entries with the trained model…'
            );

            $summary = $this->buildSummary($scored, $artifact);
            $heatmap = $this->aggregateHeatmap($scored);
            $topFeatures = $this->rankFeatureInfluences($artifact);

            $this->persistShapValues($prediction, $topFeatures);

            $this->broadcastStatus(
                $prediction,
                0.75,
                'Persisting forecast artefacts…'
            );

            $payload = [
                'prediction_id' => $prediction->id,
                'generated_at' => now()->toIso8601String(),
                'parameters' => $this->parameters,
                'summary' => $summary,
                'heatmap' => $heatmap,
                'top_features' => $topFeatures,
            ];

            $prediction->outputs()->create([
                'id' => (string) Str::uuid(),
                'format' => PredictionOutputFormat::Json,
                'payload' => $payload,
            ]);

            if ($this->generateTiles) {
                $tilesetPath = sprintf('tiles/%s/%s', now()->format('Ymd'), $prediction->id);
                Storage::disk('local')->put(
                    $tilesetPath . '/heatmap.json',
                    json_encode($heatmap, JSON_PRETTY_PRINT)
                );

                $prediction->outputs()->create([
                    'id' => (string) Str::uuid(),
                    'format' => PredictionOutputFormat::Tiles,
                    'tileset_path' => $tilesetPath,
                ]);
            }

            $prediction->fill([
                'status' => PredictionStatus::Completed,
                'finished_at' => now(),
            ])->save();

            $this->broadcastStatus(
                $prediction,
                1.0,
                'Prediction complete.'
            );
        } catch (Throwable $exception) {
            Log::error('Failed to generate prediction output', [
                'prediction_id' => $this->predictionId,
                'exception' => $exception->getMessage(),
            ]);

            $prediction->fill([
                'status' => PredictionStatus::Failed,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            $this->broadcastStatus($prediction, null, $exception->getMessage());

            throw $exception;
        }
    }

    private function broadcastStatus(Prediction $prediction, ?float $progress = null, ?string $message = null): void
    {
        $prediction->refresh();

        $event = PredictionStatusUpdated::fromPrediction($prediction, $progress, $message);

        BroadcastDispatcher::dispatch($event, [
            'prediction_id' => $event->predictionId,
            'status' => $event->status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLatestArtifact(Prediction $prediction): array
    {
        $model = $prediction->model;

        if ($model === null) {
            throw new RuntimeException('Prediction is missing an associated model.');
        }

        $metadata = $model->metadata ?? [];
        $artifactPath = $this->resolveArtifactPathFromMetadata($metadata);

        $disk = Storage::disk('local');

        if ($artifactPath === null || ! $disk->exists($artifactPath)) {
            $artifactPath = $this->findMostRecentArtifactOnDisk($model);

            if ($artifactPath !== null && $disk->exists($artifactPath)) {
                $this->storeArtifactPathOnModel($model, $artifactPath);
            }
        }

        if ($artifactPath === null || ! $disk->exists($artifactPath)) {
            throw new RuntimeException('Trained artifact for model is unavailable.');
        }

        try {
            $decoded = json_decode($disk->get($artifactPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to decode model artifact.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid model artifact content.');
        }

        foreach (['feature_means', 'feature_std_devs', 'feature_names', 'categories'] as $key) {
            if (! isset($decoded[$key]) || ! is_array($decoded[$key])) {
                throw new RuntimeException(sprintf('Model artifact is missing "%s".', $key));
            }
        }

        if (! isset($decoded['model_file']) || ! is_string($decoded['model_file']) || $decoded['model_file'] === '') {
            throw new RuntimeException('Model artifact does not contain a model file path.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $artifact
     */
    private function restoreClassifier(array $artifact): object
    {
        $modelFile = $artifact['model_file'];
        $disk = Storage::disk('local');

        if (! $disk->exists($modelFile)) {
            throw new RuntimeException(sprintf('Trained model file "%s" could not be found.', $modelFile));
        }

        $manager = new ModelManager();

        return $manager->restoreFromFile($disk->path($modelFile));
    }

    /**
     * @param array<string, mixed> $artifact
     *
     * @return array{means: list<float>, std_devs: list<float>, normalizer: Normalizer, imputer: Imputer}
     */
    private function buildPreprocessingContext(array $artifact): array
    {
        $means = array_map('floatval', $artifact['feature_means']);
        $stdDevs = array_map(
            static fn ($value) => max(1e-6, (float) $value),
            $artifact['feature_std_devs']
        );

        $normalizer = $this->normalizerFromArtifact($artifact);
        $imputer = $this->imputerFromArtifact($artifact['imputer'] ?? null);

        return [
            'means' => $means,
            'std_devs' => $stdDevs,
            'normalizer' => $normalizer,
            'imputer' => $imputer,
        ];
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function resolveArtifactPathFromMetadata(?array $metadata): ?string
    {
        if (! is_array($metadata)) {
            return null;
        }

        if (is_string($metadata['artifact_path'] ?? null) && $metadata['artifact_path'] !== '') {
            return $metadata['artifact_path'];
        }

        if (isset($metadata['artifacts']) && is_array($metadata['artifacts'])) {
            $artifacts = $metadata['artifacts'];

            if ($artifacts !== []) {
                $last = end($artifacts);

                if (is_array($last) && isset($last['path']) && is_string($last['path']) && $last['path'] !== '') {
                    return $last['path'];
                }
            }
        }

        return null;
    }

    private function findMostRecentArtifactOnDisk(PredictiveModel $model): ?string
    {
        $disk = Storage::disk('local');
        $directory = sprintf('models/%s', $model->getKey());

        $files = array_filter(
            $disk->files($directory),
            static fn (string $path): bool => str_ends_with(strtolower($path), '.json')
        );

        if ($files === []) {
            return null;
        }

        rsort($files);

        return $files[0] ?? null;
    }

    /**
     * Persist the resolved artifact path back onto the model metadata for future requests.
     * @param PredictiveModel $model
     * @param string $artifactPath
     */
    private function storeArtifactPathOnModel(PredictiveModel $model, string $artifactPath): void
    {
        try {
            $metadata = $model->metadata;

            if (! is_array($metadata)) {
                $metadata = [];
            }

            if (($metadata['artifact_path'] ?? null) === $artifactPath) {
                return;
            }

            $metadata['artifact_path'] = $artifactPath;

            $model->forceFill(['metadata' => $metadata])->save();
        } catch (Throwable $exception) {
            Log::warning('Failed to persist artifact path onto model metadata', [
                'model_id' => $model->getKey(),
                'artifact_path' => $artifactPath,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{
     *     rows: array<int, array<string, string|null>>,
     *     column_map: array<string, string>
     * }
     */
    private function loadDatasetRows(Prediction $prediction, ?array $columnMap = null): array
    {
        $dataset = $prediction->dataset ?? $prediction->model?->dataset;

        if ($dataset === null) {
            throw new RuntimeException('Prediction is missing an associated dataset.');
        }

        if ($dataset->file_path === null) {
            throw new RuntimeException('Dataset is missing a source file path.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($dataset->file_path)) {
            throw new RuntimeException(sprintf('Dataset file "%s" was not found.', $dataset->file_path));
        }

        $path = $disk->path($dataset->file_path);
        $columnMap ??= $this->resolveColumnMap($dataset);

        $rows = LazyCollection::make(function () use ($path) {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException(sprintf('Unable to open dataset file "%s".', $path));
            }

            try {
                $header = null;

                while (($data = fgetcsv($handle)) !== false) {
                    if ($header === null) {
                        $header = [];

                        foreach ($data as $index => $value) {
                            $value = is_string($value) ? $value : (string) $value;
                            $normalized = $this->normalizeColumnName($value);

                            if ($normalized === '') {
                                $normalized = $this->normalizeColumnName('column_' . $index);
                            }

                            $header[$index] = $normalized !== '' ? $normalized : 'column_' . $index;
                        }

                        continue;
                    }

                    if ($data === [null] || $data === false) {
                        continue;
                    }

                    $row = [];

                    foreach ($header as $index => $column) {
                        $row[$column] = $data[$index] ?? null;
                    }

                    yield $row;
                }
            } finally {
                fclose($handle);
            }
        });

        return [
            'rows' => $rows->values()->all(),
            'column_map' => $columnMap,
        ];
    }

    /**
     * @param iterable<int, array<string, mixed>> $rows
     * @param list<string> $artifactCategories
     *
     * @return LazyCollection<int, array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}>
     */
    private function prepareEntries(iterable $rows, array $artifactCategories, array $columnMap): LazyCollection
    {
        $lazyRows = $rows instanceof LazyCollection
            ? $rows
            : LazyCollection::make(static function () use ($rows) {
                foreach ($rows as $row) {
                    yield $row;
                }
            });

        $categories = array_values(array_map(static fn ($value) => (string) $value, $artifactCategories));

        $timestampColumn = $columnMap['timestamp'] ?? 'timestamp';
        $latitudeColumn = $columnMap['latitude'] ?? 'latitude';
        $longitudeColumn = $columnMap['longitude'] ?? 'longitude';
        $categoryColumn = $columnMap['category'] ?? 'category';
        $riskColumn = $columnMap['risk_score'] ?? 'risk_score';

        return $lazyRows
            ->map(function (array $row) use (
                $timestampColumn,
                $latitudeColumn,
                $longitudeColumn,
                $categoryColumn,
                $riskColumn,
                $categories
            ) {
                $timestampString = (string) ($row[$timestampColumn] ?? '');

                if ($timestampString === '') {
                    return null;
                }

                $timestamp = TimestampParser::parse($timestampString);

                if (! $timestamp instanceof CarbonImmutable) {
                    return null;
                }

                $latitude = (float) ($row[$latitudeColumn] ?? $row['lat'] ?? $row['latitude'] ?? 0.0);
                $longitude = (float) ($row[$longitudeColumn] ?? $row['lng'] ?? $row['longitude'] ?? 0.0);
                $riskScoreValue = $row[$riskColumn] ?? $row['risk_score'] ?? $row['risk'] ?? null;
                $riskScore = is_numeric($riskScoreValue) ? (float) $riskScoreValue : 0.0;
                $categoryValue = $row[$categoryColumn] ?? $row['category'] ?? '';
                $category = is_string($categoryValue) ? trim($categoryValue) : (string) $categoryValue;

                $hour = $timestamp->hour / 23.0;
                $dayOfWeek = ($timestamp->dayOfWeekIso - 1) / 6.0;

                $features = [$hour, $dayOfWeek, $latitude, $longitude, $riskScore];

                foreach ($categories as $expectedCategory) {
                    $features[] = $expectedCategory === $category ? 1.0 : 0.0;
                }

                return [
                    'timestamp' => $timestamp,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'category' => $category,
                    'features' => $features,
                ];
            })
            ->filter(static fn ($entry): bool => $entry !== null)
            ->values();
    }

    /**
     * @return array<string, string>
     */
    private function resolveColumnMap(Dataset $dataset): array
    {
        $mapping = is_array($dataset->schema_mapping) ? $dataset->schema_mapping : [];

        return [
            'timestamp' => $this->resolveMappedColumn($mapping, 'timestamp', 'timestamp'),
            'latitude' => $this->resolveMappedColumn($mapping, 'latitude', 'latitude'),
            'longitude' => $this->resolveMappedColumn($mapping, 'longitude', 'longitude'),
            'category' => $this->resolveMappedColumn($mapping, 'category', 'category'),
            'risk_score' => $this->resolveMappedColumn($mapping, 'risk', 'risk_score'),
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function resolveMappedColumn(array $mapping, string $key, string $default): string
    {
        $value = $mapping[$key] ?? $default;

        if (! is_string($value) || trim($value) === '') {
            $value = $default;
        }

        $normalized = $this->normalizeColumnName($value);

        if ($normalized === '') {
            $normalized = $this->normalizeColumnName($default);
        }

        if ($normalized === '') {
            $normalized = $default;
        }

        return $normalized;
    }

    private function normalizeColumnName(string $column): string
    {
        $column = preg_replace('/^\xEF\xBB\xBF/u', '', $column) ?? $column;
        $column = trim($column);

        if ($column === '') {
            return '';
        }

        $column = mb_strtolower($column, 'UTF-8');
        $column = str_replace(['-', '/'], ' ', $column);
        $column = preg_replace('/[^a-z0-9]+/u', '_', $column) ?? $column;
        $column = preg_replace('/_+/', '_', $column) ?? $column;

        return trim($column, '_');
    }

    /**
     * @param LazyCollection<int, array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}> $entries
     * @param array<string, mixed> $parameters
     *
     * @return LazyCollection<int, array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}>
     */
    private function filterEntries(LazyCollection $entries, array $parameters): LazyCollection
    {
        $center = $this->resolveCenter($parameters['center'] ?? null);
        $radiusKm = $this->resolveFloat($parameters['radius_km'] ?? $parameters['radiusKm'] ?? null);
        $observedAt = $this->resolveTimestamp($parameters['observed_at'] ?? $parameters['timestamp'] ?? $parameters['ts_end'] ?? null);
        $horizonHours = $this->resolveFloat($parameters['horizon_hours'] ?? $parameters['horizon'] ?? $parameters['horizonHours'] ?? null);

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

        return $entries
            ->filter(function (array $entry) use ($center, $radiusKm, $start, $end): bool {
                if ($center !== null && $radiusKm !== null) {
                    $distance = $this->haversine($center['lat'], $center['lng'], $entry['latitude'], $entry['longitude']);

                    if ($distance > $radiusKm) {
                        return false;
                    }
                }

                if ($start !== null && $end !== null) {
                    if ($entry['timestamp']->lt($start) || $entry['timestamp']->gt($end)) {
                        return false;
                    }
                } elseif ($end !== null && $entry['timestamp']->gt($end)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * @param LazyCollection<int, array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}> $entries
     * @param array<string, mixed> $artifact
     *
     * @return list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, score: float}>
     */
    private function scoreEntries(
        LazyCollection $entries,
        array $artifact,
        object $classifier,
        array $context
    ): array {
        $means = $context['means'];
        $stdDevs = $context['std_devs'];
        $normalizer = $context['normalizer'];
        $imputer = $context['imputer'];

        return $entries
            ->chunk(1000)
            ->flatMap(function ($chunk) use ($imputer, $means, $stdDevs, $normalizer, $classifier) {
                $chunkEntries = $chunk instanceof LazyCollection ? $chunk->all() : (array) $chunk;

                if ($chunkEntries === []) {
                    return [];
                }

                $samples = [];
                $metadata = [];

                foreach ($chunkEntries as $entry) {
                    $samples[] = array_map('floatval', $entry['features']);
                    $metadata[] = [
                        'timestamp' => $entry['timestamp'],
                        'latitude' => $entry['latitude'],
                        'longitude' => $entry['longitude'],
                        'category' => $entry['category'],
                    ];
                }

                $imputer->transform($samples);

                $standardized = [];

                foreach ($samples as $sample) {
                    $standardized[] = $this->normalizeRow($sample, $means, $stdDevs);
                }

                $normalizer->transform($standardized);

                if (method_exists($classifier, 'predictProbabilities')) {
                    $probabilities = ProbabilityScoreExtractor::extractList((array) $classifier->predictProbabilities($standardized));
                } else {
                    $predictions = $classifier->predict($standardized);
                    $probabilities = ProbabilityScoreExtractor::extractList(
                        array_map(static fn (int $value): float => $value >= 1 ? 1.0 : 0.0, $predictions)
                    );
                }

                $scored = [];

                foreach ($metadata as $index => $meta) {
                    $score = $probabilities[$index] ?? 0.0;

                    $scored[] = [
                        'timestamp' => $meta['timestamp'],
                        'latitude' => $meta['latitude'],
                        'longitude' => $meta['longitude'],
                        'category' => $meta['category'],
                        'score' => max(0.0, min(1.0, $score)),
                    ];
                }

                return $scored;
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $artifact
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
     * Converts various representations of normalizer type into the corresponding constant value.
     *
     * @param mixed $value
     *
     * @return int
     */
    private function normalizeNormalizerValue(mixed $value): int
    {
        $default = $this->normalizerConstant('NORM_L2') ?? Normalizer::NORM_L2;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            $map = array_filter([
                'l1' => $this->normalizerConstant('NORM_L1'),
                'l2' => $this->normalizerConstant('NORM_L2'),
                'linf' => $this->normalizerConstant('NORM_LINF') ?? $this->normalizerConstant('NORM_MAX'),
                'inf' => $this->normalizerConstant('NORM_LINF') ?? $this->normalizerConstant('NORM_MAX'),
                'max' => $this->normalizerConstant('NORM_MAX') ?? $this->normalizerConstant('NORM_LINF'),
                'maxnorm' => $this->normalizerConstant('NORM_MAX') ?? $this->normalizerConstant('NORM_LINF'),
                'std' => $this->normalizerConstant('NORM_STD'),
                'zscore' => $this->normalizerConstant('NORM_STD'),
            ], static fn ($candidate) => $candidate !== null);

            if (array_key_exists($normalized, $map)) {
                return (int) $map[$normalized];
            }
        }

        return $default;
    }

    /**
     * Fetches the value of a Normalizer constant by name.
     *
     * @param string $name
     *
     * @return int|null
     */
    private function normalizerConstant(string $name): ?int
    {
        $identifier = Normalizer::class.'::'.$name;

        return defined($identifier) ? (int) constant($identifier) : null;
    }

    /**
     * Builds an imputer instance from the artifact data.
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
     * @param list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, score: float}> $entries
     * @param array<string, mixed> $artifact
     *
     * @return array{mean_score: float, max_score: float, min_score: float, count: int, confidence: string, horizon_hours: float|null, radius_km: float|null}
     */
    private function buildSummary(array $entries, array $artifact): array
    {
        $scores = array_map(static fn ($entry) => $entry['score'], $entries);
        $count = count($scores);
        $mean = $count > 0 ? array_sum($scores) / $count : 0.0;
        $max = $scores === [] ? 0.0 : max($scores);
        $min = $scores === [] ? 0.0 : min($scores);
        $stdDev = $this->standardDeviation($scores, $mean);

        $confidence = 'Low';

        if ($count >= 60 && $stdDev <= 0.15 && $max >= 0.7) {
            $confidence = 'High';
        } elseif ($count >= 25 && $max >= 0.5) {
            $confidence = 'Medium';
        }

        $horizon = $this->resolveFloat($this->parameters['horizon_hours'] ?? $this->parameters['horizon'] ?? null);
        $radius = $this->resolveFloat($this->parameters['radius_km'] ?? $this->parameters['radiusKm'] ?? null);

        return [
            'mean_score' => round($mean, 4),
            'max_score' => round($max, 4),
            'min_score' => round($min, 4),
            'count' => $count,
            'confidence' => $confidence,
            'horizon_hours' => $horizon,
            'radius_km' => $radius,
        ];
    }

    /**
     * @param list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, score: float}> $entries
     *
     * @return array{points: list<array{id: string, lat: float, lng: float, intensity: float, count: int}>, hotspots: list<array{id: string, lat: float, lng: float, intensity: float, count: int}>}
     */
    private function aggregateHeatmap(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $latKey = round($entry['latitude'], 3);
            $lngKey = round($entry['longitude'], 3);
            $key = sprintf('%s:%s', $latKey, $lngKey);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'lat' => $latKey,
                    'lng' => $lngKey,
                    'sum' => 0.0,
                    'count' => 0,
                ];
            }

            $groups[$key]['sum'] += $entry['score'];
            $groups[$key]['count']++;
        }

        $points = [];

        foreach ($groups as $key => $group) {
            $average = $group['count'] > 0 ? $group['sum'] / $group['count'] : 0.0;

            $points[] = [
                'id' => $key,
                'lat' => $group['lat'],
                'lng' => $group['lng'],
                'intensity' => round($average, 4),
                'count' => $group['count'],
            ];
        }

        usort($points, static fn ($a, $b) => $b['intensity'] <=> $a['intensity']);

        $hotspots = array_slice($points, 0, 5);

        return [
            'points' => $points,
            'hotspots' => $hotspots,
        ];
    }

    /**
     * @return list<array{name: string, contribution: float, details?: array|null}>
     */
    private function rankFeatureInfluences(array $artifact): array
    {
        $importances = [];

        if (isset($artifact['feature_importances']) && is_array($artifact['feature_importances'])) {
            foreach ($artifact['feature_importances'] as $importance) {
                if (! is_array($importance)) {
                    continue;
                }

                $name = (string) ($importance['name'] ?? '');

                if ($name === '') {
                    continue;
                }

                $contribution = (float) ($importance['contribution'] ?? 0.0);
                $details = $importance['details'] ?? null;

                if ($details !== null && ! is_array($details)) {
                    $details = ['value' => $details];
                }

                $importances[] = [
                    'name' => $this->prettifyFeatureName($name),
                    'contribution' => round($contribution, 4),
                    'details' => $details,
                ];
            }
        }

        if ($importances === []) {
            $names = array_map(static fn ($name) => (string) $name, $artifact['feature_names']);

            foreach ($names as $name) {
                $importances[] = [
                    'name' => $this->prettifyFeatureName($name),
                    'contribution' => 0.0,
                ];
            }
        }

        usort($importances, static fn ($a, $b) => abs($b['contribution']) <=> abs($a['contribution']));

        return array_slice($importances, 0, 5);
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

    /**
     * @param list<array{name: string, contribution: float, details?: array|null}> $topFeatures
     */
    private function persistShapValues(Prediction $prediction, array $topFeatures): void
    {
        $prediction->shapValues()->delete();

        if ($topFeatures === []) {
            return;
        }

        $records = [];

        foreach ($topFeatures as $feature) {
            $name = (string) ($feature['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $contribution = (float) ($feature['contribution'] ?? 0.0);
            $details = $feature['details'] ?? null;

            if ($details !== null && ! is_array($details)) {
                $details = ['value' => $details];
            }

            $records[] = [
                'feature_name' => $name,
                'value' => round($contribution, 6),
                'details' => $details,
            ];
        }

        if ($records === []) {
            return;
        }

        $prediction->shapValues()->createMany($records);
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

        return $earthRadius * $c;
    }

    /**
     * @param list<float> $values
     */
    private function standardDeviation(array $values, float $mean): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $variance = 0.0;

        foreach ($values as $value) {
            $delta = $value - $mean;
            $variance += $delta * $delta;
        }

        return sqrt($variance / $count);
    }

    private function prettifyFeatureName(string $name): string
    {
        $pretty = str_replace('_', ' ', $name);

        return ucwords($pretty);
    }
}
