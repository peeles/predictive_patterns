<?php

declare(strict_types=1);

namespace App\Services\H3;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Service for normalizing and validating H3 aggregation parameters.
 *
 * Handles parsing and validation of bounding boxes, dates, categories,
 * severity levels, time-of-day ranges, and confidence levels.
 */
class H3ParameterNormalizer
{
    /**
     * Convert a bbox string to an array of floats.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     *
     * @throws InvalidArgumentException
     */
    public function parseBoundingBox(string $bboxString): array
    {
        $parts = array_map('trim', explode(',', $bboxString));

        if (count($parts) !== 4) {
            throw new InvalidArgumentException('Bounding box must contain four comma separated numbers.');
        }

        return [
            (float) $parts[0],
            (float) $parts[1],
            (float) $parts[2],
            (float) $parts[3],
        ];
    }

    /**
     * Parse the supplied value into a Carbon instance when possible.
     *
     * @throws InvalidArgumentException
     */
    public function parseDate(CarbonInterface|string|null $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        try {
            return new CarbonImmutable($value);
        } catch (Exception) {
            throw new InvalidArgumentException('Unable to parse date value.');
        }
    }

    /**
     * Normalise a category string, returning null for empty values.
     */
    public function normaliseCategory(?string $category): ?string
    {
        $category = $category !== null ? trim($category) : null;

        return $category !== '' ? $category : null;
    }

    /**
     * Normalise a severity string to lowercase, returning null for empty values.
     */
    public function normaliseSeverity(?string $severity): ?string
    {
        if ($severity === null) {
            return null;
        }

        $severity = trim(Str::lower($severity));

        return $severity !== '' ? $severity : null;
    }

    /**
     * Normalise a time-of-day range, clamping values to 0-23 and filling nulls.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public function normaliseTimeOfDayRange(?int $start, ?int $end): array
    {
        $normalise = static function (?int $value): ?int {
            if ($value === null) {
                return null;
            }

            return max(0, min(23, $value));
        };

        $start = $normalise($start);
        $end = $normalise($end);

        if ($start === null && $end === null) {
            return [null, null];
        }

        if ($start === null) {
            $start = $end;
        }

        if ($end === null) {
            $end = $start;
        }

        return [$start, $end];
    }

    /**
     * Normalise a confidence level to a valid range (0.01-0.999).
     */
    public function normaliseConfidenceLevel(?float $confidenceLevel): float
    {
        if ($confidenceLevel === null || ! is_finite($confidenceLevel)) {
            return 0.95;
        }

        return max(0.01, min(0.999, $confidenceLevel));
    }
}
