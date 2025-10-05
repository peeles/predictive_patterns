<?php

namespace App\Services\Datasets;

use App\Models\Dataset;
use App\Models\Feature;
use App\Support\TimestampParser;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class FeatureGenerator
{
    public function __construct(private readonly CsvParser $csvParser)
    {
    }

    /**
     * Populate features for the dataset based on the provided schema mapping.
     *
     * @param array<string, string> $schema
     */
    public function populateFromMapping(Dataset $dataset, array $schema): void
    {
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
            foreach ($this->csvParser->readDatasetRows($path, $dataset->mime_type) as $row) {
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
     * Build a feature array from a dataset row based on the provided schema mapping.
     *
     * @param array<string, string> $schema
     * @param array<string, mixed> $row
     */
    public function buildFeatureFromRow(Dataset $dataset, array $schema, array $row, int $index): ?array
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
     * Prepare a feature array for database insertion by adding necessary fields and encoding JSON attributes.
     *
     * @param array<string, mixed> $feature
     *
     * @throws JsonException
     */
    public function prepareFeatureForInsertion(Dataset $dataset, array $feature, string $timestamp): array
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

    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        return TimestampParser::parse($value);
    }

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
}
