<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Facades\Horizon;
use Mockery;
use Tests\TestCase;

class ExportQueueMetricsTest extends TestCase
{
    public function test_metrics_are_exported_successfully(): void
    {
        Redis::shouldReceive('llen')->with('queues:default')->andReturn(25);

        Horizon::shouldReceive('workload')->andReturn([
            ['name' => 'default', 'length' => 30],
        ]);

        $stats = Mockery::mock();
        $stats->shouldReceive('jobsProcessedPerHour')->andReturn([
            ['count' => 120],
        ]);
        $stats->shouldReceive('failedJobsPerHour')->andReturn([
            ['count' => 5],
        ]);

        Horizon::shouldReceive('stats')->andReturn($stats);

        Log::shouldReceive('info')->times(5);

        $this->artisan('metrics:export-queue')->assertExitCode(0);
    }
}
