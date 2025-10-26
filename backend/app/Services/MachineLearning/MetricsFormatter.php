<?php

declare(strict_types=1);

namespace App\Services\MachineLearning;

/**
 * Service for formatting machine learning metrics into standardised output.
 *
 * Handles the formatting of classification reports, confusion matrices,
 * and AUC calculations into a consistent structure.
 */
class MetricsFormatter
{
    /**
     * Format classification metrics into standardised output structure.
     *
     * @param array{
     *     accuracy: float,
     *     macro: array{precision: float, recall: float, f1: float},
     *     weighted: array{precision: float, recall: float, f1: float},
     *     per_class: array<int, array{precision: float, recall: float, f1: float, support: int}>
     * } $report
     * @param array{labels: list<int>, matrix: list<list<int>>} $confusion
     * @param list<float> $probabilities
     * @param list<int> $actual
     *
     * @return array{
     *     accuracy: float,
     *     macro_precision: float,
     *     macro_recall: float,
     *     macro_f1: float,
     *     weighted_precision: float,
     *     weighted_recall: float,
     *     weighted_f1: float,
     *     per_class: array<int, array{precision: float, recall: float, f1: float, support: int}>,
     *     confusion_matrix: array{labels: list<int>, matrix: list<list<int>>},
     *     auc: float
     * }
     */
    public function format(array $report, array $confusion, array $probabilities, array $actual): array
    {
        $accuracy = $report['accuracy'];
        $macro = $report['macro'];
        $weighted = $report['weighted'];

        $perClass = array_map(function ($classMetrics) {
            return [
                'precision' => round($classMetrics['precision'], 4),
                'recall' => round($classMetrics['recall'], 4),
                'f1' => round($classMetrics['f1'], 4),
                'support' => $classMetrics['support'],
            ];
        }, $report['per_class']);

        return [
            'accuracy' => round($accuracy, 4),
            'macro_precision' => round($macro['precision'], 4),
            'macro_recall' => round($macro['recall'], 4),
            'macro_f1' => round($macro['f1'], 4),
            'weighted_precision' => round($weighted['precision'], 4),
            'weighted_recall' => round($weighted['recall'], 4),
            'weighted_f1' => round($weighted['f1'], 4),
            'per_class' => $perClass,
            'confusion_matrix' => $confusion,
            'auc' => $this->computeAuc($actual, $probabilities),
        ];
    }

    /**
     * Compute AUC (Area Under the Curve) for binary classification.
     *
     * Uses the Mann-Whitney U statistic approach, which is equivalent
     * to the area under the ROC curve for binary classification.
     *
     * @param list<int> $labels True labels (0 or 1)
     * @param list<float> $scores Predicted probability scores
     *
     * @return float AUC score between 0.0 and 1.0
     */
    public function computeAuc(array $labels, array $scores): float
    {
        $positives = [];
        $negatives = [];

        foreach ($labels as $index => $label) {
            $score = $scores[$index] ?? 0.0;

            if ($label === 1) {
                $positives[] = $score;
            } else {
                $negatives[] = $score;
            }
        }

        $posCount = count($positives);
        $negCount = count($negatives);

        if ($posCount === 0 || $negCount === 0) {
            return 0.0;
        }

        $wins = 0.0;
        $ties = 0.0;

        foreach ($positives as $positiveScore) {
            foreach ($negatives as $negativeScore) {
                if ($positiveScore > $negativeScore) {
                    $wins++;
                } elseif ($positiveScore === $negativeScore) {
                    $ties++;
                }
            }
        }

        $auc = ($wins + (0.5 * $ties)) / ($posCount * $negCount);

        return round($auc, 4);
    }
}
