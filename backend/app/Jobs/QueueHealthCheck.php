<?php

namespace App\Jobs;

use App\Jobs\Middleware\LogJobExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class QueueHealthCheck implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LogJobExecution(),
            new RateLimited('default'),
        ];
    }

    public function handle(): void
    {
        $queues = [
            'default' => $this->gatherMetricsForQueue('default'),
            'training' => $this->gatherMetricsForQueue('training'),
            'broadcasts' => $this->gatherMetricsForQueue('broadcasts'),
        ];

        $metrics = [
            'queues' => $queues,
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];

        if ($queues['default']['queued'] > 1_000) {
            Log::warning('High default queue backlog detected', $metrics);
        }

        if ($queues['training']['queued'] > 100 || $queues['training']['processing'] > 50) {
            Log::warning('High training queue utilization detected', $metrics);
        }

        if ($metrics['failed_jobs'] > 100) {
            Log::error('High number of failed jobs detected', $metrics);
        }

        Log::info('Queue health check metrics collected', $metrics);
    }

    /**
     * @return array{queued:int, processing:int, delayed:int}
     */
    private function gatherMetricsForQueue(string $queue): array
    {
        return [
            'queued' => (int) Redis::llen("queues:{$queue}"),
            'processing' => (int) Redis::zcard("queues:{$queue}:reserved"),
            'delayed' => (int) Redis::zcard("queues:{$queue}:delayed"),
        ];
    }
}
