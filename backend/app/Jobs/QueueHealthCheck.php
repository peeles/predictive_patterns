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
        $metrics = [
            'default_queue_size' => Redis::llen('queues:default'),
            'training_queue_size' => Redis::llen('queues:training'),
            'broadcasts_queue_size' => Redis::llen('queues:broadcasts'),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];

        // Alert if queue backlog is too high
        if ($metrics['default_queue_size'] > 1000) {
            Log::warning('High queue backlog detected', $metrics);
        }

        if ($metrics['training_queue_size'] > 10) {
            Log::warning('Training queue backlog detected', $metrics);
        }

        if ($metrics['failed_jobs'] > 100) {
            Log::error('High number of failed jobs', $metrics);
        }

        Log::info('Queue health check', $metrics);
    }
}
