<?php

declare(strict_types=1);

namespace App\Services\MachineLearning;

use App\Support\DatasetRowBuffer;
use App\Support\FeatureBuffer;
use Phpml\Exception\InvalidArgumentException;
use Phpml\Preprocessing\Normalizer;
use RuntimeException;

/**
 * Service for preprocessing machine learning data.
 *
 * Handles data splitting, feature standardisation, normalization, and sampling.
 * Uses Welford's algorithm for computing running statistics efficiently.
 */
class DataPreprocessor
{
    /**
     * Split buffered dataset entries into training and validation sets.
     *
     * Computes training set statistics (means, standard deviations) using
     * Welford's algorithm for numerical stability. If validation split is too
     * small, clones training data for validation.
     *
     * @param DatasetRowBuffer $buffer Raw dataset buffer
     * @param float $validationSplit Fraction of data to reserve for validation (0.0-1.0)
     *
     * @return array{
     *     train_buffer: FeatureBuffer,
     *     validation_buffer: FeatureBuffer,
     *     means: list<float>,
     *     std_devs: list<float>
     * }
     *
     * @throws RuntimeException If dataset is empty or training split produces no rows
     */
    public function splitDataset(DatasetRowBuffer $buffer, float $validationSplit): array
    {
        $total = $buffer->count();

        if ($total === 0) {
            throw new RuntimeException('Dataset file does not contain any rows.');
        }

        $validationCount = (int) round($total * $validationSplit);
        $validationCount = max(0, min($validationCount, max(0, $total - 1)));
        $trainCount = $total - $validationCount;
        $cloneForValidation = false;

        if ($trainCount < 1) {
            $trainCount = $total;
            $validationCount = 0;
        }

        if ($validationCount === 0) {
            $cloneForValidation = true;
        }

        $trainBuffer = new FeatureBuffer();
        $validationBuffer = new FeatureBuffer();

        $means = [];
        $variances = [];
        $trainSeen = 0;
        $rowIndex = 0;

        foreach ($buffer as $row) {
            $useValidation = ! $cloneForValidation && $rowIndex >= $trainCount;

            if ($rowIndex < $trainCount || $cloneForValidation) {
                $trainBuffer->append($row['features'], $row['label']);
                $this->updateRunningStatistics($means, $variances, $trainSeen, $row['features']);
                $trainSeen++;
            }

            if ($useValidation || $cloneForValidation) {
                $validationBuffer->append($row['features'], $row['label']);
            }

            $rowIndex++;
        }

        if ($trainSeen === 0) {
            throw new RuntimeException('Training split did not produce any rows.');
        }

        $stdDevs = [];

        foreach ($means as $index => $mean) {
            $variance = $variances[$index] ?? 0.0;
            $std = sqrt($variance / max(1, $trainSeen));
            $std = $std > 1e-12 ? $std : 1.0;
            $stdDevs[$index] = $std;
            $means[$index] = $mean;
        }

        return [
            'train_buffer' => $trainBuffer,
            'validation_buffer' => $validationBuffer,
            'means' => array_values($means),
            'std_devs' => array_values($stdDevs),
        ];
    }

    /**
     * Convert a FeatureBuffer into arrays of samples and labels.
     *
     * @param FeatureBuffer $buffer Feature buffer to convert
     *
     * @return array{samples: list<list<float>>, labels: list<int>}
     */
    public function bufferToSamples(FeatureBuffer $buffer): array
    {
        $samples = [];
        $labels = [];

        foreach ($buffer as $row) {
            $samples[] = array_map('floatval', $row['features']);
            $labels[] = (int) $row['label'];
        }

        return [
            'samples' => $samples,
            'labels' => $labels,
        ];
    }

    /**
     * Apply standardisation to samples using Z-score normalization.
     *
     * Transforms each feature to have mean=0 and std=1 using provided
     * statistics. Protects against division by zero.
     *
     * @param list<list<float>> $samples Samples to standardise
     * @param list<float> $means Feature means
     * @param list<float> $stdDevs Feature standard deviations
     *
     * @return list<list<float>> Standardised samples
     */
    public function applyStandardisation(array $samples, array $means, array $stdDevs): array
    {
        $standardized = [];

        foreach ($samples as $sample) {
            $standardized[] = array_map(
                static fn ($value, $mean, $std) => ($value - $mean) / ($std > 1e-12 ? $std : 1.0),
                $sample,
                $means,
                $stdDevs
            );
        }

        return $standardized;
    }

    /**
     * Compute means and standard deviations for each feature.
     *
     * Uses two-pass algorithm for numerical stability.
     *
     * @param list<list<float>> $samples Training samples
     *
     * @return array{means: list<float>, std_devs: list<float>}
     */
    public function computeStatistics(array $samples): array
    {
        if ($samples === []) {
            return [
                'means' => [],
                'std_devs' => [],
            ];
        }

        $featureCount = count($samples[0]);
        $means = array_fill(0, $featureCount, 0.0);
        $variances = array_fill(0, $featureCount, 0.0);

        foreach ($samples as $sample) {
            foreach ($sample as $index => $value) {
                $means[$index] += $value;
            }
        }

        foreach ($means as $index => $sum) {
            $means[$index] = $sum / count($samples);
        }

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

    /**
     * Apply normalisation safely, handling edge cases.
     *
     * Transforms samples in-place using the provided normalizer.
     * Silently handles insufficient sample exceptions (< 2 elements).
     *
     * @param Normalizer $normalizer Normalizer instance
     * @param list<list<float>> $samples Samples to normalise (modified in-place)
     *
     * @return void
     * @throws InvalidArgumentException If normalization fails for reasons other than insufficient samples
     */
    public function normaliseSamplesSafely(Normalizer $normalizer, array &$samples): void
    {
        if ($samples === []) {
            return;
        }

        try {
            $normalizer->transform($samples);
        } catch (InvalidArgumentException $exception) {
            if (! $this->isInsufficientSampleException($exception)) {
                throw $exception;
            }
        }
    }

    /**
     * Sample a subset of the dataset randomly.
     *
     * Uses array_rand for efficient random sampling without replacement.
     * Useful for reducing memory consumption during training.
     *
     * @param list<list<float>> $samples Original samples
     * @param list<int> $labels Original labels
     * @param float $sampleRate Fraction of data to sample (0.0-1.0)
     *
     * @return array{samples: list<list<float>>, labels: list<int>}
     */
    public function sampleDataset(array $samples, array $labels, float $sampleRate = 0.5): array
    {
        $count = count($samples);
        if ($count === 0) {
            return [
                'samples' => [],
                'labels' => [],
            ];
        }

        $sampleSize = (int) ceil($count * $sampleRate);
        $sampleSize = max(1, min($count, $sampleSize));

        // Random sampling without allocating an additional index array
        $indices = array_rand($samples, $sampleSize);
        if (! is_array($indices)) {
            $indices = [$indices];
        }

        $sampledSamples = [];
        $sampledLabels = [];

        foreach ($indices as $index) {
            $sampledSamples[] = $samples[$index];
            $sampledLabels[] = $labels[$index];
        }

        return [
            'samples' => $sampledSamples,
            'labels' => $sampledLabels,
        ];
    }

    /**
     * Update running means and variances using Welford's algorithm.
     *
     * Provides numerically stable computation of statistics in a single pass.
     * Modifies arrays in-place for efficiency.
     *
     * @param array<int, float> $means Current feature means (modified in-place)
     * @param array<int, float> $variances Current feature variances (modified in-place)
     * @param int $count Number of samples seen so far
     * @param list<float> $features New feature vector to incorporate
     *
     * @return void
     */
    private function updateRunningStatistics(array &$means, array &$variances, int $count, array $features): void
    {
        if ($count === 0) {
            foreach ($features as $index => $value) {
                $means[$index] = (float) $value;
                $variances[$index] = 0.0;
            }

            return;
        }

        $newCount = $count + 1;

        foreach ($features as $index => $value) {
            $currentMean = $means[$index] ?? 0.0;
            $delta = $value - $currentMean;
            $updatedMean = $currentMean + ($delta / $newCount);
            $means[$index] = $updatedMean;

            $variance = $variances[$index] ?? 0.0;
            $variances[$index] = $variance + $delta * ($value - $updatedMean);
        }
    }

    /**
     * Check if an exception is due to insufficient samples for normalization.
     *
     * @param InvalidArgumentException $exception Exception to check
     *
     * @return bool True if exception is due to insufficient samples
     */
    private function isInsufficientSampleException(InvalidArgumentException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'zero elements') || str_contains($message, 'at least 2 elements');
    }
}
