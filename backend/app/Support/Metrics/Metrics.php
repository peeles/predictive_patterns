<?php

namespace App\Support\Metrics;

use Illuminate\Support\Facades\Log;

class Metrics
{
    /**
     * Record a gauge metric with the configured monitoring system.
     */
    public static function gauge(string $name, int|float $value, array $context = []): void
    {
        Log::info('metrics.gauge', array_merge($context, [
            'metric' => $name,
            'value' => $value,
        ]));
    }

    /**
     * Record a counter metric with the configured monitoring system.
     */
    public static function counter(string $name, int|float $value, array $context = []): void
    {
        Log::info('metrics.counter', array_merge($context, [
            'metric' => $name,
            'value' => $value,
        ]));
    }
}
