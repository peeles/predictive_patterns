<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Memory monitoring utility for tracking and logging memory usage.
 *
 * Provides convenient methods for monitoring memory consumption during
 * resource-intensive operations like model training.
 */
class MemoryMonitor
{
    private float $startMemory;
    private float $peakMemory;
    private string $context;

    /**
     * Create a new memory monitor instance.
     *
     * @param string $context Context description for logging
     */
    public function __construct(string $context = 'operation')
    {
        $this->context = $context;
        $this->startMemory = memory_get_usage(true);
        $this->peakMemory = memory_get_peak_usage(true);
    }

    /**
     * Get current memory usage in bytes.
     *
     * @param bool $realUsage If true, get system allocated memory
     *
     * @return int Memory usage in bytes
     */
    public static function currentUsage(bool $realUsage = true): int
    {
        return memory_get_usage($realUsage);
    }

    /**
     * Get peak memory usage in bytes.
     *
     * @param bool $realUsage If true, get system allocated memory
     *
     * @return int Peak memory usage in bytes
     */
    public static function peakUsage(bool $realUsage = true): int
    {
        return memory_get_peak_usage($realUsage);
    }

    /**
     * Format bytes to human-readable string.
     *
     * @param int|float $bytes Number of bytes
     * @param int $precision Decimal precision
     *
     * @return string Formatted string (e.g., "512.5 MB")
     */
    public static function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1024 ** $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Check if memory usage exceeds a threshold.
     *
     * @param int $thresholdBytes Memory threshold in bytes
     * @param bool $realUsage If true, check system allocated memory
     *
     * @return bool True if current usage exceeds threshold
     */
    public static function exceedsThreshold(int $thresholdBytes, bool $realUsage = true): bool
    {
        return self::currentUsage($realUsage) > $thresholdBytes;
    }

    /**
     * Log current memory usage with context.
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context
     *
     * @return void
     */
    public static function log(string $message, array $context = []): void
    {
        $current = self::currentUsage(true);
        $peak = self::peakUsage(true);

        Log::info($message, array_merge([
            'memory_current' => $current,
            'memory_current_formatted' => self::formatBytes($current),
            'memory_peak' => $peak,
            'memory_peak_formatted' => self::formatBytes($peak),
        ], $context));
    }

    /**
     * Take a memory snapshot with automatic logging.
     *
     * @param string $label Snapshot label
     *
     * @return array{current: int, peak: int, current_formatted: string, peak_formatted: string}
     */
    public function snapshot(string $label): array
    {
        $current = self::currentUsage(true);
        $peak = self::peakUsage(true);
        $delta = $current - $this->startMemory;

        $snapshot = [
            'current' => $current,
            'peak' => $peak,
            'delta' => $delta,
            'current_formatted' => self::formatBytes($current),
            'peak_formatted' => self::formatBytes($peak),
            'delta_formatted' => self::formatBytes($delta),
        ];

        Log::debug("Memory snapshot: {$this->context} - {$label}", $snapshot);

        return $snapshot;
    }

    /**
     * Log memory usage delta since monitor creation.
     *
     * @param string $message Completion message
     *
     * @return array{freed: int, freed_formatted: string}
     */
    public function logDelta(string $message = 'Operation completed'): array
    {
        $endMemory = self::currentUsage(true);
        $freed = $this->startMemory - $endMemory;

        Log::info("{$this->context}: {$message}", [
            'memory_start' => $this->startMemory,
            'memory_end' => $endMemory,
            'memory_freed' => $freed,
            'memory_freed_formatted' => self::formatBytes(abs($freed)),
            'freed_positive' => $freed > 0,
        ]);

        return [
            'freed' => $freed,
            'freed_formatted' => self::formatBytes(abs($freed)),
        ];
    }

    /**
     * Force garbage collection and log memory impact.
     *
     * @return array{before: int, after: int, freed: int, cycles: int}
     */
    public static function gc(): array
    {
        $before = self::currentUsage(true);
        $cycles = gc_collect_cycles();
        $after = self::currentUsage(true);
        $freed = $before - $after;

        Log::debug('Garbage collection executed', [
            'memory_before' => $before,
            'memory_after' => $after,
            'memory_freed' => $freed,
            'memory_freed_formatted' => self::formatBytes($freed),
            'cycles_collected' => $cycles,
        ]);

        return [
            'before' => $before,
            'after' => $after,
            'freed' => $freed,
            'cycles' => $cycles,
        ];
    }
}
