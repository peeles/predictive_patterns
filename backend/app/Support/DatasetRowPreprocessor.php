<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use JsonException;
use RuntimeException;
use SplTempFileObject;

class DatasetRowPreprocessor
{
    private const TEMPFILE_MEMORY_LIMIT = 262_144; // 256 KB before spilling to disk
    private const MAX_TRACKED_CATEGORIES = 64;
    private const CATEGORY_OVERFLOW_KEY = '__other__';
    /**
     * Prepare dataset entries for model training while keeping processed rows on disk.
     *
     * @param string $path
     * @param array<string, string> $columnMap
     *
     * @return array{
     *     buffer: DatasetRowBuffer,
     *     feature_names: list<string>,
     *     categories: list<string>,
     *     category_overflowed: bool
     * }
     */
    public static function prepareTrainingData(string $path, array $columnMap): array
    {
        $analysis = self::analyseCsv($path, $columnMap);
        $categories = self::deriveCategoryList($analysis['category_counts']);
        $featureNames = self::buildFeatureNames($categories);
        $buffer = self::buildBuffer($path, $columnMap, $analysis, $categories, true);

        return [
            'buffer' => $buffer,
            'feature_names' => $featureNames,
            'categories' => $categories,
            'category_overflowed' => (bool) ($analysis['overflowed_categories'] ?? false),
        ];
    }

    /**
     * Prepare dataset rows for evaluation while keeping memory usage bounded.
     *
     * @param string $path
     * @param array<string, string> $columnMap
     * @param list<string> $categories
     */
    public static function prepareEvaluationData(string $path, array $columnMap, array $categories): DatasetRowBuffer
    {
        $analysis = self::analyseCsv($path, $columnMap);

        return self::buildBuffer($path, $columnMap, $analysis, $categories, false);
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<string>
     */
    private static function deriveCategoryList(array $counts): array
    {
        $overflow = false;

        if (array_key_exists(self::CATEGORY_OVERFLOW_KEY, $counts)) {
            $overflow = true;
            unset($counts[self::CATEGORY_OVERFLOW_KEY]);
        }

        unset($counts['__default__']);

        $categories = array_keys($counts);
        sort($categories);

        if ($overflow) {
            $categories[] = self::CATEGORY_OVERFLOW_KEY;
        }

        return $categories;
    }

    /**
     * @return list<string>
     */
    private static function buildFeatureNames(array $categories): array
    {
        $featureNames = [
            'hour_of_day',
            'day_of_week',
            'latitude',
            'longitude',
            'risk_score',
        ];

        foreach ($categories as $category) {
            $featureNames[] = sprintf('category_%s', self::formatCategoryFeatureName($category));
        }

        return $featureNames;
    }

    private static function formatCategoryFeatureName(string $category): string
    {
        if ($category === self::CATEGORY_OVERFLOW_KEY) {
            return 'other';
        }

        $normalized = preg_replace('/[^a-z0-9]+/iu', '_', mb_strtolower($category, 'UTF-8')) ?? $category;
        $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;
        $normalized = trim((string) $normalized, '_');

        return $normalized !== '' ? $normalized : 'unknown';
    }

    /**
     * @param array<string, string> $columnMap
     *
     * @return array{
     *     category_counts: array<string, int>,
     *     min_count: int,
     *     max_count: int,
     *     min_time: int|null,
     *     max_time: int|null,
     *     time_span: int|null,
     *     has_numeric_risk: bool
     * }
     */
    private static function analyseCsv(string $path, array $columnMap): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open dataset file "%s".', $path));
        }

        try {
            $header = null;
            $columnIndexes = [];
            $categoryCounts = [];
            $minTime = null;
            $maxTime = null;
            $hasNumericRisk = false;
            $trackedCategories = 0;
            $overflowedCategories = false;
            $processedRows = 0;

            while (($data = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = self::normalizeHeaderRow($data);
                    $columnIndexes = self::mapColumnIndexes($header, $columnMap);
                    self::assertRequiredColumns($columnIndexes);
                    continue;
                }

                if ($data === [null] || $data === false) {
                    continue;
                }

                $timestampValue = self::extractValue($data, $columnIndexes['timestamp'] ?? null);
                $timestamp = TimestampParser::parse($timestampValue);

                if (! $timestamp instanceof CarbonImmutable) {
                    continue;
                }

                $categoryValue = self::extractValue($data, $columnIndexes['category'] ?? null);
                $category = self::normalizeString($categoryValue);

                if ($category !== '') {
                    if (array_key_exists($category, $categoryCounts)) {
                        $categoryCounts[$category]++;
                    } elseif ($trackedCategories < self::MAX_TRACKED_CATEGORIES) {
                        $categoryCounts[$category] = 1;
                        $trackedCategories++;
                    } else {
                        $overflowedCategories = true;
                        $categoryCounts[self::CATEGORY_OVERFLOW_KEY] = ($categoryCounts[self::CATEGORY_OVERFLOW_KEY] ?? 0) + 1;
                    }
                }

                $timestampSeconds = $timestamp->getTimestamp();
                $minTime = $minTime === null ? $timestampSeconds : min($minTime, $timestampSeconds);
                $maxTime = $maxTime === null ? $timestampSeconds : max($maxTime, $timestampSeconds);

                if (! $hasNumericRisk) {
                    $riskValue = self::extractNumeric(self::extractValue($data, $columnIndexes['risk_score'] ?? null));
                    $hasNumericRisk = $riskValue !== null;
                }

                $processedRows++;

                if (($processedRows % 5_000) === 0) {
                    gc_collect_cycles();
                }
            }
        } finally {
            fclose($handle);
        }

        if ($categoryCounts === []) {
            $categoryCounts['__default__'] = 0;
        }

        if ($overflowedCategories && ! array_key_exists(self::CATEGORY_OVERFLOW_KEY, $categoryCounts)) {
            $categoryCounts[self::CATEGORY_OVERFLOW_KEY] = 0;
        }

        $maxCount = max($categoryCounts);
        $minCount = min($categoryCounts);

        $timeSpan = ($minTime !== null && $maxTime !== null)
            ? max($maxTime - $minTime, 0)
            : null;

        return [
            'category_counts' => $categoryCounts,
            'min_count' => $minCount,
            'max_count' => $maxCount,
            'min_time' => $minTime,
            'max_time' => $maxTime,
            'time_span' => $timeSpan,
            'has_numeric_risk' => $hasNumericRisk,
            'overflowed_categories' => $overflowedCategories,
        ];
    }

    /**
     * Build a buffered representation of the dataset rows so downstream consumers can stream from disk.
     *
     * @param array<string, string> $columnMap
     * @param array<string, mixed> $analysis
     * @param list<string> $categories
     */
    private static function buildBuffer(
        string $path,
        array $columnMap,
        array $analysis,
        array $categories,
        bool $includeTimestamps
    ): DatasetRowBuffer {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open dataset file "%s".', $path));
        }

        $file = self::createTempFile();

        $rowCount = 0;
        $maxRisk = 0.0;
        $histogram = array_fill(0, 101, 0);
        $rawPositiveCount = 0;
        $needsGenerated = false;

        $categoryIndex = array_flip($categories);
        $categoryCount = count($categories);

        try {
            $header = null;
            $columnIndexes = [];

            while (($data = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = self::normalizeHeaderRow($data);
                    $columnIndexes = self::mapColumnIndexes($header, $columnMap);
                    self::assertRequiredColumns($columnIndexes);
                    continue;
                }

                if ($data === [null] || $data === false) {
                    continue;
                }

                $timestampValue = self::extractValue($data, $columnIndexes['timestamp'] ?? null);
                $timestamp = TimestampParser::parse($timestampValue);

                if (! $timestamp instanceof CarbonImmutable) {
                    continue;
                }

                $hour = $timestamp->hour / 23.0;
                $dayOfWeek = ($timestamp->dayOfWeekIso - 1) / 6.0;

                $latitudeValue = self::extractValue($data, $columnIndexes['latitude'] ?? null);
                $longitudeValue = self::extractValue($data, $columnIndexes['longitude'] ?? null);

                $latitude = self::toFloat($latitudeValue);
                $longitude = self::toFloat($longitudeValue);

                $categoryValue = self::extractValue($data, $columnIndexes['category'] ?? null);
                $category = self::normalizeString($categoryValue);
                $encodedCategory = $category;

                if ($encodedCategory !== '' && ! array_key_exists($encodedCategory, $categoryIndex) && array_key_exists(self::CATEGORY_OVERFLOW_KEY, $categoryIndex)) {
                    $encodedCategory = self::CATEGORY_OVERFLOW_KEY;
                }

                $existingRisk = self::extractNumeric(self::extractValue($data, $columnIndexes['risk_score'] ?? null));

                if ($analysis['has_numeric_risk'] && $existingRisk !== null) {
                    $risk = max(0.0, min(1.0, $existingRisk));
                } else {
                    $risk = self::computeRiskScore($encodedCategory !== '' ? $encodedCategory : $category, $timestamp, $analysis);
                }

                $maxRisk = max($maxRisk, $risk);
                $risk = max(0.0, min(1.0, $risk));

                $bin = (int) floor($risk * 100);
                $bin = max(0, min(100, $bin));
                $histogram[$bin]++;

                $rawLabelValue = self::extractValue($data, $columnIndexes['label'] ?? null);
                $rawLabel = self::extractNumeric($rawLabelValue);
                $normalizedLabel = $rawLabel !== null ? (int) round($rawLabel) : null;

                if ($normalizedLabel !== null && $normalizedLabel > 0) {
                    $rawPositiveCount++;
                }

                if ($normalizedLabel === null) {
                    $needsGenerated = true;
                }

                $features = [$hour, $dayOfWeek, $latitude, $longitude, $risk];

                if ($categoryCount > 0) {
                    $encoded = array_fill(0, $categoryCount, 0.0);

                    if ($encodedCategory !== '' && array_key_exists($encodedCategory, $categoryIndex)) {
                        $encoded[$categoryIndex[$encodedCategory]] = 1.0;
                    }

                    $features = array_merge($features, $encoded);
                }

                self::writeBufferedRow($file, $includeTimestamps ? $timestamp : null, $features, $normalizedLabel, $risk);

                $rowCount++;

                if (($rowCount % 2_500) === 0) {
                    gc_collect_cycles();
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rowCount === 0) {
            return new DatasetRowBuffer($file, $includeTimestamps, 1.1, $maxRisk, false, 0);
        }

        $threshold = $needsGenerated
            ? self::determineRiskThresholdFromHistogram($histogram, $rowCount)
            : 1.1;

        $finalPositiveCount = $rawPositiveCount;

        if ($needsGenerated && $threshold <= 1.0) {
            $finalPositiveCount += self::countGeneratedPositives($file, $threshold);
        }

        $forceMaxRiskPositive = $finalPositiveCount === 0 && $maxRisk > 0.0;

        $file->rewind();

        return new DatasetRowBuffer(
            $file,
            $includeTimestamps,
            $threshold,
            $maxRisk,
            $forceMaxRiskPositive,
            $rowCount
        );
    }

    private static function writeBufferedRow(
        SplTempFileObject $file,
        ?CarbonImmutable $timestamp,
        array $features,
        ?int $rawLabel,
        float $risk
    ): void {
        $payload = [
            'features' => array_map(static fn ($value) => (float) $value, $features),
            'risk' => $risk,
            'raw_label' => $rawLabel,
        ];

        if ($timestamp instanceof CarbonImmutable) {
            $payload['timestamp'] = $timestamp->toIso8601String();
        }

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode buffered dataset row.', 0, $exception);
        }

        $file->fwrite($encoded . "\n");
    }

    /**
     * @param array<int, int> $histogram
     */
    private static function determineRiskThresholdFromHistogram(array $histogram, int $totalCount): float
    {
        $activeBins = 0;

        foreach ($histogram as $count) {
            if ($count > 0) {
                $activeBins++;
            }
        }

        if ($activeBins <= 1 || $totalCount === 0) {
            return 1.1;
        }

        $targetIndex = (int) floor(0.75 * max($totalCount - 1, 0));
        $targetRank = $targetIndex + 1;
        $cumulative = 0;

        foreach ($histogram as $bin => $count) {
            $cumulative += $count;

            if ($cumulative >= $targetRank) {
                return $bin / 100;
            }
        }

        return 0.0;
    }

    private static function countGeneratedPositives(SplTempFileObject $file, float $threshold): int
    {
        $file->rewind();
        $positive = 0;

        $processed = 0;

        while (! $file->eof()) {
            $line = $file->fgets();

            if ($line === false) {
                break;
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Failed to decode buffered dataset row.', 0, $exception);
            }

            if (! is_array($data) || ! array_key_exists('risk', $data)) {
                continue;
            }

            $rawLabel = $data['raw_label'] ?? null;

            if ($rawLabel !== null) {
                continue;
            }

            $risk = (float) $data['risk'];

            if ($risk >= $threshold && $risk > 0.0) {
                $positive++;
            }

            $processed++;

            if (($processed % 10_000) === 0) {
                gc_collect_cycles();
            }
        }

        return $positive;
    }

    private static function createTempFile(): SplTempFileObject
    {
        $file = new SplTempFileObject(self::TEMPFILE_MEMORY_LIMIT);
        $file->setFlags(SplTempFileObject::DROP_NEW_LINE);

        return $file;
    }

    private static function computeRiskScore(string $category, CarbonImmutable $timestamp, array $analysis): float
    {
        $counts = $analysis['category_counts'];
        $count = 0;

        if ($category !== '') {
            if (array_key_exists($category, $counts)) {
                $count = $counts[$category];
            } elseif (array_key_exists(self::CATEGORY_OVERFLOW_KEY, $counts)) {
                $count = $counts[self::CATEGORY_OVERFLOW_KEY];
            }
        } elseif ($counts !== []) {
            $count = $analysis['min_count'];
        }

        if ($analysis['max_count'] === $analysis['min_count']) {
            $categoryScore = $analysis['max_count'] > 0 ? 0.5 : 0.0;
        } else {
            $denominator = max($analysis['max_count'] - $analysis['min_count'], 1);
            $categoryScore = ($count - $analysis['min_count']) / $denominator;
        }

        $recencyScore = 0.5;

        if ($analysis['time_span'] !== null && $analysis['time_span'] > 0 && $analysis['min_time'] !== null) {
            $recencyScore = ($timestamp->getTimestamp() - $analysis['min_time']) / $analysis['time_span'];
            $recencyScore = max(0.0, min(1.0, $recencyScore));
        }

        $score = (0.6 * $categoryScore) + (0.4 * $recencyScore);

        return max(0.0, min(1.0, $score));
    }

    /**
     * @param array<int, string|null> $row
     *
     * @return list<string>
     */
    private static function normalizeHeaderRow(array $row): array
    {
        $normalized = [];
        $used = [];

        foreach ($row as $value) {
            if (! is_string($value)) {
                $normalized[] = '';
                continue;
            }

            $column = self::normalizeColumnName($value);

            if ($column === '') {
                $column = trim($value);
            }

            $base = $column;
            $suffix = 1;

            while ($column !== '' && in_array($column, $used, true)) {
                $suffix++;
                $column = sprintf('%s_%d', $base, $suffix);
            }

            if ($column !== '') {
                $used[] = $column;
            }

            $normalized[] = $column;
        }

        return $normalized;
    }

    private static function normalizeColumnName(string $column): string
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
     * @param list<string> $header
     * @param array<string, string> $columnMap
     *
     * @return array<string, int|null>
     */
    private static function mapColumnIndexes(array $header, array $columnMap): array
    {
        $indexes = [];
        $headerIndexMap = [];

        foreach ($header as $index => $column) {
            if ($column === '') {
                continue;
            }

            $headerIndexMap[$column] = $index;
        }

        foreach ($columnMap as $logical => $column) {
            if (! is_string($column) || $column === '') {
                $indexes[$logical] = null;
                continue;
            }

            $indexes[$logical] = $headerIndexMap[$column] ?? null;
        }

        return $indexes;
    }

    /**
     * @param array<string, int|null> $indexes
     */
    private static function assertRequiredColumns(array $indexes): void
    {
        foreach (['timestamp', 'latitude', 'longitude', 'category'] as $required) {
            if (! array_key_exists($required, $indexes) || $indexes[$required] === null) {
                throw new RuntimeException(sprintf('Dataset is missing required column "%s".', $required));
            }
        }
    }

    /**
     * @param list<string|null> $row
     */
    private static function extractValue(array $row, ?int $index): mixed
    {
        if ($index === null) {
            return null;
        }

        return $row[$index] ?? null;
    }

    private static function normalizeString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private static function extractNumeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '' || ! is_numeric($trimmed)) {
                return null;
            }

            return (float) $trimmed;
        }

        return null;
    }

    private static function toFloat(mixed $value): float
    {
        $numeric = self::extractNumeric($value);

        return $numeric ?? 0.0;
    }
}
