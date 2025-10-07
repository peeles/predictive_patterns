<?php

namespace App\DataTransferObjects;

/**
 * Immutable value object wrapping aggregate metrics for a single H3 index.
 */
class HexAggregate
{
    private const Z_SCORE_MIN = 0.0001;
    private const Z_SCORE_MAX = 0.9999;

    /**
     * @param array<string, int> $categories
     */
    public function __construct(
        public readonly string $h3Index,
        public readonly int $count,
        public readonly array $categories,
        public readonly int $riskValueCount,
        public readonly float $riskValueSum,
        public readonly float $riskValueSumSquares,
    ) {
    }

    public function meanRiskScore(): ?float
    {
        if ($this->riskValueCount === 0) {
            return null;
        }

        return $this->riskValueSum / $this->riskValueCount;
    }

    /**
     * @return array{lower: float, upper: float, level: float}|null
     */
    public function confidenceInterval(float $confidenceLevel): ?array
    {
        if ($this->riskValueCount === 0) {
            return null;
        }

        $mean = $this->meanRiskScore();

        if ($mean === null) {
            return null;
        }

        if ($this->riskValueCount === 1) {
            return [
                'lower' => $mean,
                'upper' => $mean,
                'level' => $confidenceLevel,
            ];
        }

        $varianceNumerator = $this->riskValueSumSquares - ($this->riskValueSum ** 2) / $this->riskValueCount;
        $variance = $varianceNumerator / max(1, $this->riskValueCount - 1);
        $variance = max(0.0, $variance);

        $standardDeviation = sqrt($variance);

        if ($standardDeviation <= 0.0) {
            return [
                'lower' => $mean,
                'upper' => $mean,
                'level' => $confidenceLevel,
            ];
        }

        $zScore = $this->zScoreForConfidenceLevel($confidenceLevel);
        $margin = $zScore * ($standardDeviation / sqrt($this->riskValueCount));

        return [
            'lower' => max(0.0, min(1.0, $mean - $margin)),
            'upper' => max(0.0, min(1.0, $mean + $margin)),
            'level' => $confidenceLevel,
        ];
    }

    private function zScoreForConfidenceLevel(float $confidenceLevel): float
    {
        $clamped = max(self::Z_SCORE_MIN, min(self::Z_SCORE_MAX, $confidenceLevel));
        $p = 0.5 + $clamped / 2;

        return $this->inverseStandardNormalCdf($p);
    }

    private function inverseStandardNormalCdf(float $p): float
    {
        // Coefficients from Peter J. Acklam's approximation
        $a = [
            -3.969683028665376e+01,
            2.209460984245205e+02,
            -2.759285104469687e+02,
            1.383577518672690e+02,
            -3.066479806614716e+01,
            2.506628277459239e+00,
        ];

        $b = [
            -5.447609879822406e+01,
            1.615858368580409e+02,
            -1.556989798598866e+02,
            6.680131188771972e+01,
            -1.328068155288572e+01,
        ];

        $c = [
            -7.784894002430293e-03,
            -3.223964580411365e-01,
            -2.400758277161838e+00,
            -2.549732539343734e+00,
            4.374664141464968e+00,
            2.938163982698783e+00,
        ];

        $d = [
            7.784695709041462e-03,
            3.224671290700398e-01,
            2.445134137142996e+00,
            3.754408661907416e+00,
        ];

        $pLow = 0.02425;
        $pHigh = 1 - $pLow;

        $p = max(1e-10, min(1 - 1e-10, $p));

        if ($p < $pLow) {
            $q = sqrt(-2 * log($p));
            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) /
                (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        if ($p > $pHigh) {
            $q = sqrt(-2 * log(1 - $p));
            return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) /
                (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        $q = $p - 0.5;
        $r = $q * $q;

        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q /
            ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
    }
}
