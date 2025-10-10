<?php

namespace Tests\Unit\Jobs\Factories;

use App\Jobs\EvaluateModelJob;
use App\Jobs\Factories\ModelJobFactory;
use App\Jobs\TrainModelJob;
use Tests\TestCase;

class ModelJobFactoryTest extends TestCase
{
    public function test_it_creates_train_model_job(): void
    {
        $job = ModelJobFactory::training(
            'run-id',
            ['learning_rate' => 0.1],
            'https://example.test/webhook',
            'user-123',
        );

        $this->assertInstanceOf(TrainModelJob::class, $job);
        $this->assertSame('train-model-run-id', $job->uniqueId());
        $this->assertSame('https://example.test/webhook', $job->getWebhookUrl());
        $this->assertSame('training', $job->connection);
        $this->assertSame('training', $job->queue);
    }

    public function test_it_creates_evaluate_model_job(): void
    {
        config(['queue.connections.training.queue' => 'priority-training']);

        $job = ModelJobFactory::evaluation(
            'model-id',
            'dataset-id',
            ['accuracy' => 0.9],
            'Initial baseline evaluation',
        );

        $this->assertInstanceOf(EvaluateModelJob::class, $job);
        $this->assertSame('training', $job->connection);
        $this->assertSame('priority-training', $job->queue);
    }
}
