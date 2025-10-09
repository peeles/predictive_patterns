<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

class LogJobExecution
{
    /**
     * @template TJob of object
     * @param TJob $job
     * @param Closure(TJob): mixed $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $start = microtime(true);

        Log::info('Job started', [
            'job' => $job::class,
            'queue' => $job->queue ?? null,
        ]);

        try {
            $result = $next($job);

            Log::info('Job completed', [
                'job' => $job::class,
                'duration_ms' => (microtime(true) - $start) * 1000,
            ]);

            return $result;
        } catch (Throwable $exception) {
            Log::error('Job failed', [
                'job' => $job::class,
                'queue' => $job->queue ?? null,
                'duration_ms' => (microtime(true) - $start) * 1000,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
