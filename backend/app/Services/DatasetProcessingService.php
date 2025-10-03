<?php

namespace App\Services;

use App\Enums\DatasetStatus;
use App\Events\DatasetStatusUpdated;
use App\Jobs\CompleteDatasetIngestion;
use App\Models\Dataset;
use App\Models\Feature;
use App\Support\Broadcasting\BroadcastDispatcher;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use App\Support\TimestampParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class DatasetProcessingService
{
    private ?bool $featuresTableExists = null;

    public function __construct(private readonly DatasetPreviewService $previewService)
    {
    }

    /**
     * Finalise the dataset after ingestion, generating a preview and populating features if a schema mapping is provided.
     *
     * @param Dataset $dataset
     * @param array<string, string> $schemaMapping
     * @param array<string, mixed> $additionalMetadata
     *
     * @return Dataset
     */
    public function finalise(Dataset $dataset, array $schemaMapping = [], array $additionalMetadata = []): Dataset
    {
        if ($additionalMetadata !== []) {
            $dataset->metadata = $this->mergeMetadata($dataset->metadata, $additionalMetadata);
        }

        $preview = $this->generatePreview($dataset);

        if ($preview !== null) {
            $metadata = array_filter([
                'row_count' => $preview['row_count'] ?? 0,
                'preview_rows' => $preview['preview_rows'] ?? [],
                'headers' => $preview['headers'] ?? [],
            ], static function ($value) {
                if (is_array($value)) {
                    return $value !== [];
                }

                return $value !== null;
            });

            if ($schemaMapping !== []) {
                $metadata['schema_mapping'] = $schemaMapping;
                $metadata['derived_features'] = $this->buildDerivedFeaturesSummary(
                    $schemaMapping,
                    $metadata['headers'] ?? [],
                    $metadata['preview_rows'] ?? []
                );
            }

            $dataset->metadata = $this->mergeMetadata($dataset->metadata, $metadata);
        } elseif ($schemaMapping !== []) {
            $dataset->metadata = $this->mergeMetadata($dataset->metadata, [
                'schema_mapping' => $schemaMapping,
                'derived_features' => $this->buildDerivedFeaturesSummary($schemaMapping, [], []),
            ]);
        }

        $dataset->status = DatasetStatus::Ready;
        $dataset->ingested_at = now();
        $dataset->save();

        if ($schemaMapping !== [] && $dataset->file_path !== null) {
            $dataset->refresh();
            $this->populateFeaturesFromMapping($dataset, $schemaMapping);
        }

        if ($this->featuresTableExists()) {
            $dataset->loadCount('features');
        }

        $event = DatasetStatusUpdated::fromDataset($dataset, 1.0);
        BroadcastDispatcher::dispatch($event, [
            'dataset_id' => $event->datasetId,
            'status' => $event->status,
        ]);

        return $dataset;
    }

    /**
     * Dispatch an asynchronous job to finalise the dataset after the HTTP request completes.
     *
     * @param Dataset $dataset
     * @param array<string, string> $schemaMapping
     * @param array<string, mixed> $additionalMetadata
     *
     * @return Dataset
     */
    public function queueFinalise(Dataset $dataset, array $schemaMapping = [], array $additionalMetadata = []): Dataset
    {
        if ($additionalMetadata !== []) {
            $dataset->metadata = $this->mergeMetadata($dataset->metadata, $additionalMetadata);
            $dataset->save();
        }

        CompleteDatasetIngestion::dispatch(
            $dataset->getKey(),
            $schemaMapping,
            $additionalMetadata
        );

        $event = DatasetStatusUpdated::fromDataset($dataset, 0.0);
        BroadcastDispatcher::dispatch($event, [
            'dataset_id' => $event->datasetId,
            'status' => $event->status,
        ]);

        return $dataset;
    }

    /**
     * Mark the dataset as failed and broadcast the failure event.
     */
    public function markAsFailed(Dataset $dataset, ?Throwable $exception = null): void
    {
        $dataset->status = DatasetStatus::Failed;

        $metadata = $this->mergeMetadata($dataset->metadata, [
            'ingest_error' => $exception?->getMessage() ?: 'Dataset processing failed.',
        ]);

        $dataset->metadata = $metadata;
        $dataset->ingested_at = null;
        $dataset->save();

        $message = $exception?->getMessage() ?: 'Dataset processing failed.';

        $event = DatasetStatusUpdated::fromDataset($dataset, 0.0, $message);
        BroadcastDispatcher::dispatch($event, [
            'dataset_id' => $event->datasetId,
            'status' => $event->status,
        ]);
    }

    /**
     * Merge existing metadata with additional metadata, overriding existing keys with new values.
     *
     * @param mixed $existing
     * @param array $additional
     *
     * @return array
     */
    public function mergeMetadata(mixed $existing, array $additional): array
    {
        $metadata = is_array($existing) ? $existing : [];

        foreach ($additional as $key => $value) {
            if (is_array($value) && $value === []) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            $metadata[$key] = $value;
        }

        return $metadata;
    }

    /**
     * Build a summary of derived features based on the provided schema mapping and preview rows.
     *
     * @param array<string, string> $schema
     * @param list<string> $headers
     * @param array<int, array<string, mixed>> $previewRows
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildDerivedFeaturesSummary(array $schema, array $headers, array $previewRows): array
    {
        if ($schema === []) {
            return [];
        }

        $summary = [];

        foreach ($schema as $key => $column) {
            if (! is_string($key) || ! is_string($column) || trim($column) === '') {
                continue;
            }

            $sample = null;

            foreach ($previewRows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                if (! array_key_exists($column, $row)) {
                    continue;
                }

                $value = $row[$column];

                if ($value === null) {
                    continue;
                }

                if (is_string($value) && trim($value) === '') {
                    continue;
                }

                $sample = $value;
                break;
            }

            $summary[$key] = ['column' => $column];

            if ($sample !== null) {
                $summary[$key]['sample'] = $sample;
            }
        }

        return $summary;
    }

    /**
     * Populate features for the dataset based on the provided schema mapping.
     *
     * @param Dataset $dataset
     * @param array<string, string> $schema
     */
    private function populateFeaturesFromMapping(Dataset $dataset, array $schema): void
    {
        if (! $this->featuresTableExists()) {
            return;
        }

        foreach (['timestamp', 'latitude', 'longitude', 'category'] as $requiredField) {
            if (! array_key_exists($requiredField, $schema)) {
                return;
            }
        }

        $path = Storage::disk('local')->path($dataset->file_path);

        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            return;
        }

        Feature::query()->where('dataset_id', $dataset->getKey())->delete();

        $index = 0;
        $batch = [];
        $batchSize = 500;
        $timestamp = now()->toDateTimeString();

        try {
            foreach ($this->readDatasetRows($path, $dataset->mime_type) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $featureData = $this->buildFeatureFromRow($dataset, $schema, $row, $index);

                if ($featureData === null) {
                    continue;
                }

                $batch[] = $this->prepareFeatureForInsertion($dataset, $featureData, $timestamp);

                if (count($batch) >= $batchSize) {
                    $this->insertFeatureBatch($batch);
                    $batch = [];
                    $timestamp = now()->toDateTimeString();
                }

                $index++;
            }

            if ($batch !== []) {
                $this->insertFeatureBatch($batch);
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to derive dataset features', [
                'dataset_id' => $dataset->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Read dataset rows from the given file path based on its MIME type or extension.
     *
     * @param string $path
     * @param string|null $mimeType
     *
     * @return iterable<array<string, mixed>>
     */
    private function readDatasetRows(string $path, ?string $mimeType): iterable
    {
        $mimeType = $mimeType !== null ? strtolower($mimeType) : null;

        if ($mimeType !== null && str_contains($mimeType, 'json')) {
            return [];
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['json', 'geojson'], true)) {
            return [];
        }

        return $this->readCsvRows($path);
    }

    /**
     * Prepare a feature array for database insertion by adding necessary fields and encoding JSON attributes.
     *
     * @param Dataset $dataset
     * @param string $timestamp
     * @param array<string, mixed> $feature
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function prepareFeatureForInsertion(Dataset $dataset, array $feature, string $timestamp): array
    {
        $feature['id'] = (string) Str::uuid();
        $feature['dataset_id'] = $dataset->getKey();
        $feature['created_at'] = $timestamp;
        $feature['updated_at'] = $timestamp;

        if (($feature['observed_at'] ?? null) instanceof CarbonImmutable) {
            $feature['observed_at'] = $feature['observed_at']->toDateTimeString();
        } elseif (isset($feature['observed_at']) && is_string($feature['observed_at'])) {
            $feature['observed_at'] = trim($feature['observed_at']) !== ''
                ? $feature['observed_at']
                : null;
        }

        $feature['geometry'] = $this->encodeJsonAttribute($feature['geometry'] ?? null);
        $feature['properties'] = $this->encodeJsonAttribute($feature['properties'] ?? null);

        if (! array_key_exists('srid', $feature)) {
            $feature['srid'] = 4326;
        }

        return $feature;
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function insertFeatureBatch(array $batch): void
    {
        if ($batch === []) {
            return;
        }

        Feature::query()->insert($batch);
    }

    /**
     * Encode an array as a JSON string for storage in the database.
     *
     * @param array<string, mixed>|null $value
     *
     * @return string|null
     * @throws JsonException
     */
    private function encodeJsonAttribute(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Read rows from a CSV file at the given path.
     *
     * @param string $path
     *
     * @return iterable<array<string, mixed>>
     */
    private function readCsvRows(string $path): iterable
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            $headers = null;

            while (($row = fgetcsv($handle)) !== false) {
                if ($headers === null) {
                    $headers = $this->normaliseCsvHeaders($row);

                    if ($headers === []) {
                        break;
                    }

                    continue;
                }

                if ($this->isEmptyCsvRow($row)) {
                    continue;
                }

                $assoc = $this->combineCsvRow($headers, $row);

                if ($assoc === null || $assoc === []) {
                    continue;
                }

                yield $assoc;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Build a feature array from a dataset row based on the provided schema mapping.
     *
     * @param array<string, string> $schema
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function buildFeatureFromRow(Dataset $dataset, array $schema, array $row, int $index): ?array
    {
        $latitudeKey = $schema['latitude'];
        $longitudeKey = $schema['longitude'];
        $timestampKey = $schema['timestamp'];
        $categoryKey = $schema['category'];
        $riskKey = $schema['risk'] ?? null;

        if (! array_key_exists($latitudeKey, $row) || ! array_key_exists($longitudeKey, $row)) {
            return null;
        }

        $latitude = $this->toFloat($row[$latitudeKey] ?? null);
        $longitude = $this->toFloat($row[$longitudeKey] ?? null);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        $observedAt = null;

        if (array_key_exists($timestampKey, $row)) {
            $observedAt = $this->parseTimestamp($row[$timestampKey]);
        }

        $category = $row[$categoryKey] ?? null;
        $name = is_scalar($category) ? trim((string) $category) : '';

        if ($name === '') {
            $name = sprintf('Feature %d', $index + 1);
        }

        $properties = [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        if ($observedAt instanceof CarbonImmutable) {
            $properties['timestamp'] = $observedAt->toIso8601String();
        }

        if (is_scalar($category) && trim((string) $category) !== '') {
            $properties['category'] = (string) $category;
        }

        if ($riskKey !== null && array_key_exists($riskKey, $row)) {
            $riskScore = $this->toFloat($row[$riskKey]);

            if ($riskScore !== null) {
                $properties['risk_score'] = $riskScore;
            } elseif (is_scalar($row[$riskKey]) && trim((string) $row[$riskKey]) !== '') {
                $properties['risk_score'] = $row[$riskKey];
            }
        }

        $properties = array_filter($properties, static function ($value) {
            if (is_string($value)) {
                return trim($value) !== '';
            }

            return $value !== null;
        });

        return [
            'dataset_id' => $dataset->getKey(),
            'name' => $name,
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$longitude, $latitude],
            ],
            'properties' => $properties,
            'observed_at' => $observedAt,
            'srid' => 4326,
        ];
    }

    /**
     * Parse a timestamp from various input formats into a DateTimeImmutable object.
     *
     * @param mixed $value
     *
     * @return DateTimeImmutable|null
     */
    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        return TimestampParser::parse($value);
    }

    /**
     * Convert a value to a float if possible.
     *
     * @param mixed $value
     *
     * @return float|null
     */
    private function toFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $numeric = trim($value);

            if ($numeric === '') {
                return null;
            }

            if (! is_numeric($numeric)) {
                return null;
            }

            return (float) $numeric;
        }

        return null;
    }

    /**
     * Normalise CSV headers by removing BOM characters and trimming whitespace.
     *
     * @param array<int, string|null> $headers
     *
     * @return list<string>
     */
    private function normaliseCsvHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $header) {
            if ($header === null) {
                $normalised[] = '';
                continue;
            }

            $value = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            $value = trim((string) $value);

            $normalised[] = $value;
        }

        return $normalised;
    }

    /**
     * Check if a CSV row is empty (all values are null or empty strings).
     *
     * @param array<int, string|null> $row
     *
     * @return bool
     */
    private function isEmptyCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value === null) {
                continue;
            }

            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Combine CSV row values with headers to create an associative array.
     *
     * @param list<string> $headers
     * @param array<int, string|null> $row
     *
     * @return array<string, string|null>|null
     */
    private function combineCsvRow(array $headers, array $row): ?array
    {
        if ($headers === []) {
            return null;
        }

        $assoc = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $value = $row[$index] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            $assoc[$header] = $value;
        }

        foreach ($assoc as $value) {
            if ($value !== null && $value !== '') {
                return $assoc;
            }
        }

        return null;
    }

    /**
     * Check if the 'features' table exists in the database.
     *
     * @return bool
     */
    private function featuresTableExists(): bool
    {
        if ($this->featuresTableExists !== null) {
            return $this->featuresTableExists;
        }

        return $this->featuresTableExists = Schema::hasTable('features');
    }

    /**
     * Generate a preview for the dataset using the DatasetPreviewService.
     *
     * @param Dataset $dataset
     *
     * @return array|null
     */
    private function generatePreview(Dataset $dataset): ?array
    {
        $path = $dataset->file_path;

        if ($path === null) {
            return null;
        }

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        try {
            return $this->previewService->summarise(
                Storage::disk('local')->path($path),
                $dataset->mime_type
            );
        } catch (Throwable $exception) {
            Log::warning('Failed to generate dataset preview', [
                'dataset_id' => $dataset->id,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }
}
