<?php

declare(strict_types=1);

namespace App\Support\Helpers;

use Carbon\Carbon;

final class DateFormatter
{
    public static function formatForDisplay(Carbon $date): string
    {
        return $date->format('d/m/Y H:i:s');
    }

    public static function formatDateOnly(Carbon $date): string
    {
        return $date->format('d/m/Y');
    }

    public static function formatShortDate(Carbon $date): string
    {
        return $date->format('j M Y');
    }
}
