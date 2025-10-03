<?php

namespace App\Support\Metrics;

use Phpml\Metric\ConfusionMatrix;

class ClassificationReportGenerator
{
    /**
     * Generates a classification report and confusion matrix from expected and predicted labels.
     *
     * @param list<int|float|string> $expected
     * @param list<int|float|string> $predicted
     *
     * @return array{
     *     report: array{
     *         accuracy: float,
     *         macro: array{precision: float, recall: float, f1: float},
     *         weighted: array{precision: float, recall: float, f1: float},
     *         per_class: array<int|float|string, array{precision: float, recall: float, f1: float, support: int}>
     *     },
     *     confusion: array{labels: list<int|float|string>, matrix: list<list<int>>}
     * }
     */
    public static function generate(array $expected, array $predicted): array
    {
        $labels = self::deriveLabelSet($expected, $predicted);
        $rawConfusion = ConfusionMatrix::compute($expected, $predicted, $labels);
        $confusion = self::normaliseConfusionMatrix($rawConfusion, $labels);
        $report = self::fromConfusionMatrix($confusion);

        return [
            'report' => $report,
            'confusion' => $confusion,
        ];
    }

    /**
     * Generates a classification report from a confusion matrix.
     *
     * @param array{labels: list<int|float|string>, matrix: list<list<int>>} $confusion
     *
     * @return array{
     *     accuracy: float,
     *     macro: array{precision: float, recall: float, f1: float},
     *     weighted: array{precision: float, recall: float, f1: float},
     *     per_class: array<int|float|string, array{precision: float, recall: float, f1: float, support: int}>
     * }
     */
    private static function fromConfusionMatrix(array $confusion): array
    {
        $labels = $confusion['labels'];
        $matrix = $confusion['matrix'];

        $perClass = [];
        $total = 0;
        $correct = 0;
        $weightedPrecision = 0.0;
        $weightedRecall = 0.0;
        $weightedF1 = 0.0;

        foreach ($labels as $index => $label) {
            $row = $matrix[$index] ?? [];
            $support = array_sum($row);
            $tp = $row[$index] ?? 0;
            $fp = 0;

            foreach ($matrix as $candidateRow) {
                $fp += $candidateRow[$index] ?? 0;
            }

            $fp -= $tp;

            $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
            $recall = $support > 0 ? $tp / $support : 0.0;
            $f1 = ($precision + $recall) > 0 ? (2 * $precision * $recall) / ($precision + $recall) : 0.0;

            $perClass[$label] = [
                'precision' => $precision,
                'recall' => $recall,
                'f1' => $f1,
                'support' => (int) $support,
            ];

            $total += $support;
            $correct += $tp;
            $weightedPrecision += $precision * $support;
            $weightedRecall += $recall * $support;
            $weightedF1 += $f1 * $support;
        }

        $nonEmpty = array_filter(
            $perClass,
            static fn (array $metrics): bool => $metrics['support'] > 0
        );

        if ($nonEmpty === []) {
            $nonEmpty = $perClass;
        }

        $macroDenominator = max(1, count($nonEmpty));
        $macroPrecision = array_sum(array_column($nonEmpty, 'precision')) / $macroDenominator;
        $macroRecall = array_sum(array_column($nonEmpty, 'recall')) / $macroDenominator;
        $macroF1 = array_sum(array_column($nonEmpty, 'f1')) / $macroDenominator;

        $accuracy = $total > 0 ? $correct / $total : 0.0;
        $weightedPrecision = $total > 0 ? $weightedPrecision / $total : 0.0;
        $weightedRecall = $total > 0 ? $weightedRecall / $total : 0.0;
        $weightedF1 = $total > 0 ? $weightedF1 / $total : 0.0;

        return [
            'accuracy' => $accuracy,
            'macro' => [
                'precision' => $macroPrecision,
                'recall' => $macroRecall,
                'f1' => $macroF1,
            ],
            'weighted' => [
                'precision' => $weightedPrecision,
                'recall' => $weightedRecall,
                'f1' => $weightedF1,
            ],
            'per_class' => $perClass,
        ];
    }

    /**
     * @param list<int|float|string> $expected
     * @param list<int|float|string> $predicted
     *
     * @return list<int|float|string>
     */
    private static function deriveLabelSet(array $expected, array $predicted): array
    {
        $labels = [];

        foreach (array_merge($expected, $predicted) as $value) {
            if (! in_array($value, $labels, true)) {
                $labels[] = $value;
            }
        }

        return $labels;
    }

    /**
     * Normalise the confusion matrix structure returned by Phpml to a predictable shape.
     *
     * @param mixed $confusion
     * @param list<int|float|string> $labels
     *
     * @return array{labels: list<int|float|string>, matrix: list<list<int>>}
     */
    private static function normaliseConfusionMatrix(mixed $confusion, array $labels): array
    {
        if (is_array($confusion) && isset($confusion['labels'], $confusion['matrix'])) {
            $normalisedLabels = array_values($confusion['labels']);

            return [
                'labels' => $normalisedLabels,
                'matrix' => self::normaliseMatrixRows($confusion['matrix'], count($normalisedLabels)),
            ];
        }

        if (is_array($confusion) && isset($confusion['matrix']) && is_array($confusion['matrix'])) {
            return [
                'labels' => $labels,
                'matrix' => self::normaliseMatrixRows($confusion['matrix'], count($labels)),
            ];
        }

        if (is_array($confusion) && self::isAssociativeLabelMatrix($confusion, $labels)) {
            $matrix = [];

            foreach ($labels as $rowLabel) {
                $row = [];

                foreach ($labels as $columnLabel) {
                    $row[] = (int) ($confusion[$rowLabel][$columnLabel] ?? 0);
                }

                $matrix[] = $row;
            }

            return [
                'labels' => $labels,
                'matrix' => $matrix,
            ];
        }

        $size = count($labels);

        return [
            'labels' => $labels,
            'matrix' => self::normaliseMatrixRows($confusion, $size),
        ];
    }

    /**
     * Normalise the rows of a confusion matrix to ensure it is a square matrix of the given size.
     *
     * @param mixed $matrix
     * @param int $size
     *
     * @return list<list<int>>
     */
    private static function normaliseMatrixRows(mixed $matrix, int $size): array
    {
        $rows = [];

        if (! is_array($matrix)) {
            return array_fill(0, $size, array_fill(0, $size, 0));
        }

        $index = 0;

        foreach ($matrix as $row) {
            if ($index >= $size) {
                break;
            }

            $values = is_array($row) ? array_values($row) : [];
            $values = array_map(static fn ($value): int => (int) $value, $values);

            if (count($values) < $size) {
                $values = array_pad($values, $size, 0);
            } elseif (count($values) > $size) {
                $values = array_slice($values, 0, $size);
            }

            $rows[] = $values;
            $index++;
        }

        while (count($rows) < $size) {
            $rows[] = array_fill(0, $size, 0);
        }

        return $rows;
    }

    /**
     * Determine if the confusion matrix is keyed by labels and each entry contains
     * associative rows keyed by labels as well.
     *
     * @param array $confusion
     * @param list<int|float|string> $labels
     *
     * @return bool
     */
    private static function isAssociativeLabelMatrix(array $confusion, array $labels): bool
    {
        if ($confusion === [] || $labels === []) {
            return false;
        }

        foreach ($labels as $label) {
            if (! array_key_exists($label, $confusion) || ! is_array($confusion[$label])) {
                return false;
            }
        }

        return true;
    }
}
