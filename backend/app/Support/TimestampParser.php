<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class TimestampParser
{
    private const YEAR_MONTH_PATTERN = '/^\d{4}-\d{2}$/';

    private function __construct()
    {
    }

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::createFromInterface($value);
        }

        if (is_int($value)) {
            try {
                return CarbonImmutable::createFromTimestamp($value);
            } catch (Throwable) {
                return null;
            }
        }

        if (is_float($value)) {
            try {
                return CarbonImmutable::createFromTimestamp((int) $value);
            } catch (Throwable) {
                return null;
            }
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        try {
            if (preg_match(self::YEAR_MONTH_PATTERN, $trimmed) === 1) {
                $month = CarbonImmutable::createFromFormat('!Y-m', $trimmed);

                if ($month === false) {
                    return null;
                }

                return $month->endOfMonth();
            }

            return CarbonImmutable::parse($trimmed);
        } catch (Throwable) {
            return null;
        }
    }
}
