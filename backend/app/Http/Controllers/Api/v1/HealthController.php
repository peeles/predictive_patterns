<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class HealthController extends BaseController
{
    public function __invoke(): JsonResponse
    {
        $dbStatus = 'ok';
        $queueStatus = 'ok';

        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $dbStatus = 'error';
        }

        try {
            $queueStatus = Queue::size() >= 0 ? 'ok' : 'error';
        } catch (Throwable) {
            $queueStatus = 'error';
        }

        $status = $dbStatus === 'ok' && $queueStatus === 'ok' ? 'ok' : 'degraded';

        return $this->successResponse([
            'status' => $status,
            'checks' => [
                'database' => $dbStatus,
                'queue' => $queueStatus,
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
