<?php

namespace App\Support;

/**
 * Normalizes probability outputs from various classifiers into scalar scores.
 */
final class ProbabilityScoreExtractor
{
    /**
     * @param iterable<mixed> $probabilities
     *
     * @return list<float>
     */
    public static function extractList(iterable $probabilities): array
    {
        $scores = [];

        foreach ($probabilities as $probability) {
            $scores[] = self::extract($probability);
        }

        return $scores;
    }

    public static function extract(mixed $probability): float
    {
        if (is_numeric($probability)) {
            return self::clamp((float) $probability);
        }

        if (is_array($probability)) {
            $preferredKeys = ['1', 1, 'true', true, 'yes', 'positive'];

            foreach ($preferredKeys as $key) {
                if (array_key_exists($key, $probability)) {
                    return self::extract($probability[$key]);
                }
            }

            $best = null;

            foreach ($probability as $value) {
                $score = self::extract($value);

                if ($best === null || $score > $best) {
                    $best = $score;
                }
            }

            return $best ?? 0.0;
        }

        return 0.0;
    }

    private static function clamp(float $value): float
    {
        if (is_nan($value) || ! is_finite($value)) {
            return $value > 0 ? 1.0 : 0.0;
        }

        return max(0.0, min(1.0, $value));
    }
}
