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

        Log::shouldReceive('warning')->twice();
        Log::shouldReceive('error')->once();
        Log::shouldReceive('info')->once();

        $job = new QueueHealthCheck();
        $job->handle();

        Mockery::close();
    }
}
