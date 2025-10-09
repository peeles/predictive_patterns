<?php

namespace Tests\Feature\Jobs;

use App\Jobs\TrainModelJob;
use App\Models\TrainingRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrainModelJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_dispatched_to_training_queue(): void
    {
        Queue::fake();

        $run = TrainingRun::factory()->create();

        TrainModelJob::dispatch($run->id);

        Queue::assertPushedOn('training', TrainModelJob::class);
    }

    public function test_job_has_unique_constraint(): void
    {
        Queue::fake();

        $run = TrainingRun::factory()->create();

        // Dispatch twice
        TrainModelJob::dispatch($run->id);
        TrainModelJob::dispatch($run->id);

        // Should only be dispatched once due to uniqueness
        Queue::assertPushed(TrainModelJob::class, 1);
    }

    public function test_job_has_correct_timeout(): void
    {
        $job = new TrainModelJob('test-id');

        $this->assertEquals(3600, $job->timeout);
        $this->assertEquals('training', $job->connection);
        $this->assertEquals('training', $job->queue);
    }
}
