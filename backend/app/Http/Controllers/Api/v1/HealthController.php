<?php

namespace App\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class HealthController extends BaseController
{
    public function __invoke(): JsonResponse
    {
        $databaseStatus = $this->determineDatabaseHealth();
        $queueStatus = $this->determineQueueHealth();

        $overallStatus = collect([$databaseStatus, $queueStatus])
            ->contains('error') ? 'degraded' : 'ok';

        return $this->successResponse([
            'status' => $overallStatus,
            'checks' => [
                'database' => $databaseStatus,
                'queue' => $queueStatus,
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function determineDatabaseHealth(): string
    {
        try {
            DB::connection()->getPdo();

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function determineQueueHealth(): string
    {
        $connection = (string) config('queue.default', 'sync');

        if ($connection === '' || in_array($connection, ['sync', 'null'], true)) {
            return 'ok';
        }

        try {
            Queue::connection($connection);

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }
}
