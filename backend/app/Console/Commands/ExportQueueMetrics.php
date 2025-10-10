<?php

namespace App\Console\Commands;

use App\Support\Metrics\Metrics;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Facades\Horizon;

class ExportQueueMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metrics:export-queue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export queue metrics to the configured monitoring system.';

    public function handle(): int
    {
        $this->info('Collecting queue metrics for export...');

        $metrics = [
            'queue_size' => $this->resolveQueueSize('default'),
            'processed_last_hour' => 0,
            'failed_last_hour' => 0,
            'memory_usage' => memory_get_usage(true),
        ];

        if (class_exists(\Laravel\Horizon\Horizon::class)) {
            $metrics['queue_size'] = $this->resolveQueueSizeFromHorizon('default') ?? $metrics['queue_size'];

            $stats = Horizon::stats();

            if ($stats !== null) {
                $metrics['processed_last_hour'] = $this->resolveHourlyMetric($stats, 'jobsProcessedPerHour', 'jobsProcessed');
                $metrics['failed_last_hour'] = $this->resolveHourlyMetric($stats, 'failedJobsPerHour', 'failedJobs');
            }
        }

        Metrics::gauge('laravel.queue.size', $metrics['queue_size']);
        Metrics::counter('laravel.queue.processed_last_hour', $metrics['processed_last_hour']);
        Metrics::counter('laravel.queue.failed_last_hour', $metrics['failed_last_hour']);
        Metrics::gauge('laravel.queue.memory_usage', $metrics['memory_usage']);

        Log::info('Queue metrics exported', $metrics);

        $this->info('Queue metrics export complete.');

        return self::SUCCESS;
    }

    private function resolveQueueSize(string $queue): int
    {
        return (int) Redis::llen("queues:{$queue}");
    }

    private function resolveQueueSizeFromHorizon(string $queue): ?int
    {
        $workload = Horizon::workload();

        if (is_iterable($workload)) {
            foreach ($workload as $item) {
                $name = Arr::get($item, 'name');
                $length = Arr::get($item, 'length');

                if ($name === $queue && is_numeric($length)) {
                    return (int) $length;
                }
            }
        }

        return null;
    }

    /**
     * @param  object  $stats
     */
    private function resolveHourlyMetric(object $stats, string $hourlyMethod, string $fallbackMethod): int
    {
        if (method_exists($stats, $hourlyMethod)) {
            $values = $stats->{$hourlyMethod}();

            if (is_iterable($values)) {
                $first = Arr::first($values);

                if (is_array($first) && isset($first['count'])) {
                    return (int) $first['count'];
                }

                if (is_numeric($first)) {
                    return (int) $first;
                }
            }
        }

        if (method_exists($stats, $fallbackMethod)) {
            $value = $stats->{$fallbackMethod}();

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
    }
}
