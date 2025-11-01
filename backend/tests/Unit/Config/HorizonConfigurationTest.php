<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class HorizonConfigurationTest extends TestCase
{
    public function test_training_supervisor_targets_training_queue(): void
    {
        $defaults = config('horizon.defaults.supervisor-training');

        $this->assertNotNull($defaults, 'Training supervisor configuration is missing.');
        $this->assertSame(env('HORIZON_TRAINING_CONNECTION', 'training'), $defaults['connection']);
        $this->assertSame([config('queue.connections.training.queue', 'training')], $defaults['queue']);
        $this->assertSame('simple', $defaults['balance']);
        $this->assertSame(1, $defaults['minProcesses']);
        // Memory limit configured to 1536MB for ML training workloads
        $this->assertSame((int) env('HORIZON_TRAINING_MEMORY', 1536), $defaults['memory']);
        $this->assertSame((int) env('TRAINING_QUEUE_TIMEOUT', 3600), $defaults['timeout']);
    }

    public function test_training_wait_threshold_and_scaling_overrides_are_configured(): void
    {
        $waits = config('horizon.waits');
        $this->assertArrayHasKey('redis:training', $waits);
        $this->assertSame(300, $waits['redis:training']);

        $production = config('horizon.environments.production.supervisor-training');
        $this->assertNotNull($production);
        $this->assertSame((int) env('HORIZON_TRAINING_MAX_PROCESSES', 4), $production['maxProcesses']);
        $this->assertSame(1, $production['balanceMaxShift']);
        $this->assertSame(10, $production['balanceCooldown']);

        $local = config('horizon.environments.local.supervisor-training');
        $this->assertNotNull($local);
        $this->assertSame(1, $local['maxProcesses']);
    }
}
