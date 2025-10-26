<?php

namespace App\Services;

use App\Models\Dataset;
use App\Services\Dataset\ColumnMapper;
use App\Support\DatasetRowBuffer;
use App\Support\DatasetRowPreprocessor;
use App\Support\Phpml\ImputerFactory;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Phpml\Exception\InvalidOperationException;
use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Normalizer;
use RuntimeException;

class DatasetAnalysisService
{
    private const CACHE_PREFIX = 'dataset_analysis:';
    private const CACHE_TTL_MINUTES = 10;

    public function __construct(
        private readonly ColumnMapper $columnMapper = new ColumnMapper(),
    ) {
    }

    public function analyze(Dataset $dataset): array
    {
        if ($dataset->getKey() === null) {
            throw new RuntimeException('Dataset must be persisted before analysis.');
        }

        if ($dataset->file_path === null) {
            throw new RuntimeException('Dataset does not have an associated file.');
        }

        $cacheKey = $this->cacheKey($dataset);
        $ttl = now()->addMinutes(self::CACHE_TTL_MINUTES);

        if ($this->cacheSupportsTagging()) {
            $cacheTags = $this->cacheTags($dataset);

            return Cache::tags($cacheTags)->remember($cacheKey, $ttl, function () use ($dataset): array {
                return $this->performAnalysis($dataset);
            });
        }

        return Cache::remember($cacheKey, $ttl, function () use ($dataset): array {
            return $this->performAnalysis($dataset);
        });
    }

    /**
     * @return array{
     *     rows: int,
     *     feature_names: list<string>,
     *     feature_summary: array<string, array<string, float>>,
     *     label_distribution: array<string, int>,
     *     categories: mixed,
     *     normalized_preview: list<array<string, mixed>>,
     * }
     */
    private function performAnalysis(Dataset $dataset): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($dataset->file_path)) {
            throw new RuntimeException(sprintf('Dataset file "%s" could not be found.', $dataset->file_path));
        }

        $columnMap = $this->columnMapper->resolveColumnMap($dataset);
        $prepared = DatasetRowPreprocessor::prepareTrainingData($disk->path($dataset->file_path), $columnMap);
        $buffer = $prepared['buffer'];

        if (! $buffer instanceof DatasetRowBuffer || $buffer->count() === 0) {
            throw new RuntimeException('Dataset does not contain any records.');
        }

        $stats = $this->summariseBuffer($buffer);

        return [
            'rows' => $stats['row_count'],
            'feature_names' => $prepared['feature_names'],
            'feature_summary' => $stats['features'],
            'label_distribution' => $stats['labels'],
            'categories' => $prepared['categories'],
            'normalized_preview' => $this->normalizedPreview($stats['sample'], $prepared['feature_names']),
        ];
    }

    /**
     * @return list<string>
     */
    private function cacheTags(Dataset $dataset): array
    {
        return [sprintf('dataset:%s', $dataset->getKey())];
    }

    public static function cacheKeyFor(Dataset $dataset): string
    {
        return self::CACHE_PREFIX . $dataset->getKey();
    }

    private function cacheKey(Dataset $dataset): string
    {
        return self::cacheKeyFor($dataset);
    }

    private function cacheSupportsTagging(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    /**
     * @return array<string, string>
     */
    private function resolveColumnMap(Dataset $dataset): array
    {
        $mapping = is_array($dataset->schema_mapping) ? $dataset->schema_mapping : [];
        $column = preg_replace('/_+/', '_', $column) ?? $column;

        return trim($column, '_');
    }

    /**
     * @return array{row_count: int, features: array<string, array<string, float>>, labels: array<string, int>, sample: list<list<float>>}
     */
    private function summariseBuffer(DatasetRowBuffer $buffer): array
    {
        $featureStats = [];
        $labelCounts = [];
        $rowCount = 0;
        $sample = [];

        foreach ($buffer as $row) {
            $rowCount++;
            $label = (string) $row['label'];
            $labelCounts[$label] = ($labelCounts[$label] ?? 0) + 1;

            foreach ($row['features'] as $index => $value) {
                $value = (float) $value;
                $featureStats[$index] ??= [
                    'sum' => 0.0,
                    'sum_sq' => 0.0,
                    'min' => $value,
                    'max' => $value,
                ];

                $featureStats[$index]['sum'] += $value;
                $featureStats[$index]['sum_sq'] += $value * $value;
                $featureStats[$index]['min'] = min($featureStats[$index]['min'], $value);
                $featureStats[$index]['max'] = max($featureStats[$index]['max'], $value);
            }

            if (count($sample) < 100) {
                $sample[] = array_map('floatval', $row['features']);
            }
        }

        $features = [];

        foreach ($featureStats as $index => $stat) {
            $mean = $stat['sum'] / max(1, $rowCount);
            $variance = ($stat['sum_sq'] / max(1, $rowCount)) - ($mean * $mean);
            $std = sqrt(max(0.0, $variance));

            $features[$index] = [
                'mean' => round($mean, 4),
                'std' => round($std, 4),
                'min' => round($stat['min'], 4),
                'max' => round($stat['max'], 4),
            ];
        }

        ksort($features);
        ksort($labelCounts);

        return [
            'row_count' => $rowCount,
            'features' => $features,
            'labels' => $labelCounts,
            'sample' => $sample,
        ];
    }

    /**
     * @param list<list<float>> $sample
     * @param list<string> $featureNames
     *
     * @return list<array<string, mixed>>
     * @throws InvalidOperationException
     */
    private function normalizedPreview(array $sample, array $featureNames): array
    {
        if ($sample === []) {
            return [];
        }

        $imputer = ImputerFactory::create(Imputer::STRATEGY_MEAN);
        $imputer->fit($sample);
        $imputer->transform($sample);

        $statistics = $this->computeStatistics($sample);
        $normalized = [];

        foreach ($sample as $row) {
            $normalized[] = $this->standardizeRow($row, $statistics['means'], $statistics['std_devs']);
        }

        $normalizer = new Normalizer(Normalizer::NORM_L2);
        $normalizer->transform($normalized);

        $preview = [];

        foreach (array_slice($normalized, 0, 5) as $row) {
            $preview[] = array_combine($featureNames, array_map(static fn ($value) => round($value, 4), $row));
        }

        return $preview;
    }

    /**
     * @param list<float> $row
     * @param list<float> $means
     * @param list<float> $stdDevs
     *
     * @return list<float>
     */
    private function standardizeRow(array $row, array $means, array $stdDevs): array
    {
        $standardized = [];

        foreach ($row as $index => $value) {
            $mean = $means[$index] ?? 0.0;
            $std = $stdDevs[$index] ?? 1.0;
            $standardized[] = ($value - $mean) / ($std > 1e-12 ? $std : 1.0);
        }

        return $standardized;
    }

    /**
     * @param list<list<float>> $samples
     *
     * @return array{means: list<float>, std_devs: list<float>}
     */
    private function computeStatistics(array $samples): array
    {
        $featureCount = count($samples[0]);
        $means = array_fill(0, $featureCount, 0.0);

        foreach ($samples as $sample) {
            foreach ($sample as $index => $value) {
                $means[$index] += $value;
            }
        }

        foreach ($means as $index => $sum) {
            $means[$index] = $sum / max(1, count($samples));
        }

        $variances = array_fill(0, $featureCount, 0.0);

        foreach ($samples as $sample) {
            foreach ($sample as $index => $value) {
                $delta = $value - $means[$index];
                $variances[$index] += $delta * $delta;
            }
        }

        $stdDevs = [];

        foreach ($variances as $index => $variance) {
            $std = sqrt($variance / max(1, count($samples) - 1));
            $stdDevs[$index] = $std > 1e-12 ? $std : 1.0;
        }

        return [
            'means' => $means,
            'std_devs' => $stdDevs,
        ];
    }
}
