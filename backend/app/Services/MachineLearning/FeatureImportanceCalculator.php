<?php

declare(strict_types=1);

namespace App\Services\MachineLearning;

/**
 * Service for calculating feature importance scores.
 *
 * Computes feature importance using correlation analysis between
 * features and labels, providing insights into which features
 * contribute most to the model's predictions.
 */
class FeatureImportanceCalculator
{
    /**
     * Compute feature importances using correlation with the label.
     *
     * Uses Pearson correlation coefficient to measure the linear
     * relationship between each feature and the target variable.
     * Returns the top 10 features sorted by absolute correlation.
     *
     * @param list<list<float>> $samples Training samples (rows of feature values)
     * @param list<int> $labels Target labels
     * @param list<string> $featureNames Names of features
     *
     * @return list<array{name: string, contribution: float}> Top 10 features with importance scores
     */
    public function calculate(array $samples, array $labels, array $featureNames): array
    {
        if ($samples === [] || $labels === []) {
            return [];
        }

        $labelMean = array_sum($labels) / count($labels);
        $labelVariance = 0.0;

        foreach ($labels as $label) {
            $delta = $label - $labelMean;
            $labelVariance += $delta * $delta;
        }

        $labelStd = sqrt($labelVariance / max(1, count($labels) - 1));
        $labelStd = $labelStd > 0.0 ? $labelStd : 1.0;

        $importances = [];
        $featureCount = count($samples[0]);

        for ($index = 0; $index < $featureCount; $index++) {
            $values = array_column($samples, $index);
            $mean = array_sum($values) / max(1, count($values));
            $variance = 0.0;
            $covariance = 0.0;

            foreach ($values as $key => $value) {
                $delta = $value - $mean;
                $variance += $delta * $delta;
                $covariance += $delta * ($labels[$key] - $labelMean);
            }

            $std = sqrt($variance / max(1, count($values) - 1));
            $std = $std > 0.0 ? $std : 1.0;
            $correlation = $covariance / (max(1, count($values) - 1) * $std * $labelStd);
            $importances[] = [
                'name' => $this->prettifyFeatureName($featureNames[$index] ?? ('Feature ' . ($index + 1))),
                'contribution' => round(abs($correlation), 4),
            ];
        }

        usort($importances, static fn ($a, $b) => $b['contribution'] <=> $a['contribution']);

        return array_slice($importances, 0, 10);
    }

    /**
     * Convert a feature name to a human-readable format.
     *
     * Replaces underscores and hyphens with spaces and capitalises each word.
     *
     * @param string $name Raw feature name
     *
     * @return string Prettified feature name
     */
    private function prettifyFeatureName(string $name): string
    {
        $name = str_replace(['_', '-'], ' ', $name);
        $name = trim($name);

        return ucwords($name);
    }
}
