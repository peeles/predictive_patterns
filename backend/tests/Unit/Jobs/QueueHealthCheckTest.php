<?php

namespace Tests\Unit\Jobs;

use App\Jobs\QueueHealthCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class QueueHealthCheckTest extends TestCase
{
    public function test_queue_health_metrics_are_logged(): void
    {
        Redis::shouldReceive('llen')->with('queues:default')->once()->andReturn(1_500);
        Redis::shouldReceive('llen')->with('queues:training')->once()->andReturn(120);
        Redis::shouldReceive('llen')->with('queues:broadcasts')->once()->andReturn(0);
        Redis::shouldReceive('zcard')->with('queues:default:reserved')->once()->andReturn(75);
        Redis::shouldReceive('zcard')->with('queues:default:delayed')->once()->andReturn(5);
        Redis::shouldReceive('zcard')->with('queues:training:reserved')->once()->andReturn(60);
        Redis::shouldReceive('zcard')->with('queues:training:delayed')->once()->andReturn(3);
        Redis::shouldReceive('zcard')->with('queues:broadcasts:reserved')->once()->andReturn(0);
        Redis::shouldReceive('zcard')->with('queues:broadcasts:delayed')->once()->andReturn(0);

        $query = Mockery::mock();
        $query->shouldReceive('count')->andReturn(125);

        DB::shouldReceive('table')->with('failed_jobs')->andReturn($query);

        $expectedMetrics = [
            'queues' => [
                'default' => [
                    'queued' => 1_500,
                    'processing' => 75,
                    'delayed' => 5,
                ],
                'training' => [
                    'queued' => 120,
                    'processing' => 60,
                    'delayed' => 3,
                ],
                'broadcasts' => [
                    'queued' => 0,
                    'processing' => 0,
                    'delayed' => 0,
                ],
            ],
            'failed_jobs' => 125,
        ];

        $capturedLogs = [
            'warning' => [],
            'error' => [],
            'info' => [],
        ];

        Log::shouldReceive('warning')
            ->times(2)
            ->andReturnUsing(static function (string $message, array $metrics) use (&$capturedLogs) {
                $capturedLogs['warning'][] = compact('message', 'metrics');
            });

        Log::shouldReceive('error')
            ->once()
            ->andReturnUsing(static function (string $message, array $metrics) use (&$capturedLogs) {
                $capturedLogs['error'][] = compact('message', 'metrics');
            });

        Log::shouldReceive('info')
            ->once()
            ->andReturnUsing(static function (string $message, array $metrics) use (&$capturedLogs) {
                $capturedLogs['info'][] = compact('message', 'metrics');
            });

        $job = new QueueHealthCheck();
        $job->handle();

        self::assertSame([
            ['message' => 'High default queue backlog detected', 'metrics' => $expectedMetrics],
            ['message' => 'High training queue utilization detected', 'metrics' => $expectedMetrics],
        ], $capturedLogs['warning']);

        self::assertSame([
            ['message' => 'High number of failed jobs detected', 'metrics' => $expectedMetrics],
        ], $capturedLogs['error']);

        self::assertSame([
            ['message' => 'Queue health check metrics collected', 'metrics' => $expectedMetrics],
        ], $capturedLogs['info']);

        Mockery::close();
    }
}
