<?php

namespace Tests\Feature;

use App\Contracts\Queue\ShouldBeAuthorized;
use App\Enums\ModelStatus;
use App\Enums\Role;
use App\Events\ModelStatusUpdated;
use App\Jobs\EvaluateModelJob;
use App\Jobs\TrainModelJob;
use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ModelApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_model(): void
    {
        $dataset = Dataset::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/v1/models', [
                'name' => 'Spatial Graph Attention',
                'dataset_id' => $dataset->id,
                'version' => '2.0.0',
                'tag' => 'baseline',
                'area' => 'Downtown',
                'hyperparameters' => ['learning_rate' => 0.05],
                'metadata' => ['notes' => 'Initial run'],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Spatial Graph Attention');
        $response->assertJsonPath('data.dataset_id', $dataset->id);
        $response->assertJsonPath('data.status', ModelStatus::Draft->value);

        $this->assertDatabaseHas('models', [
            'name' => 'Spatial Graph Attention',
            'dataset_id' => $dataset->id,
            'version' => '2.0.0',
            'tag' => 'baseline',
            'area' => 'Downtown',
        ]);
    }

    public function test_non_admin_cannot_create_model(): void
    {
        $tokens = $this->issueTokensForRole(Role::Analyst);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/v1/models', [
                'name' => 'Unauthorized Model',
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('models', [
            'name' => 'Unauthorized Model',
        ]);
    }

    public function test_dataset_is_required_when_creating_model(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/v1/models', [
                'name' => 'Missing Dataset Model',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['dataset_id']);

        $this->assertDatabaseMissing('models', [
            'name' => 'Missing Dataset Model',
        ]);
    }

    public function test_training_request_dispatches_job(): void
    {
        Bus::fake();
        Event::fake([ModelStatusUpdated::class]);
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        $model = PredictiveModel::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        $webhookUrl = 'https://example.test/webhooks/model-training';

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/models/train', [
            'model_id' => $model->id,
            'hyperparameters' => ['learning_rate' => 0.2],
            'webhook_url' => $webhookUrl,
        ]);

        $response->assertAccepted();

        Bus::assertDispatched(TrainModelJob::class, function (TrainModelJob $job) use ($webhookUrl): bool {
            return $job->connection === 'training'
                && $job->queue === config('queue.connections.training.queue', 'training')
                && $job->getWebhookUrl() === $webhookUrl
                && $job instanceof ShouldBeAuthorized;
        });

        Event::assertDispatched(ModelStatusUpdated::class, function (ModelStatusUpdated $event) use ($model): bool {
            return $event->modelId === $model->id && $event->state === 'training' && $event->progress === 0.0;
        });

        $this->assertDatabaseHas('training_runs', [
            'model_id' => $model->id,
            'status' => 'queued',
        ]);
    }

    public function test_training_request_uses_idempotency_key(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        Bus::fake();
        Event::fake([ModelStatusUpdated::class]);
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        $model = PredictiveModel::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        $headers = [
            'Authorization' => 'Bearer '.$tokens['accessToken'],
            'Idempotency-Key' => 'train:'.$model->id,
        ];

        $firstResponse = $this->withHeaders($headers)->postJson('/api/v1/models/train', [
            'model_id' => $model->id,
            'hyperparameters' => ['max_depth' => 4],
        ]);

        $firstResponse->assertAccepted();

        $secondResponse = $this->withHeaders($headers)->postJson('/api/v1/models/train', [
            'model_id' => $model->id,
            'hyperparameters' => ['max_depth' => 4],
        ]);

        $secondResponse->assertAccepted();

        $this->assertSame($firstResponse->json('data.training_run_id'), $secondResponse->json('data.training_run_id'));
        $this->assertSame($firstResponse->json('data.job_id'), $secondResponse->json('data.job_id'));

        Bus::assertDispatchedTimes(TrainModelJob::class, 1);
        Event::assertDispatchedTimes(ModelStatusUpdated::class, 2);
        $this->assertSame(1, TrainingRun::query()->count());
    }

    public function test_training_request_idempotency_is_scoped_per_user(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        Bus::fake();
        Event::fake([ModelStatusUpdated::class]);
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        $model = PredictiveModel::factory()->create();
        $firstTokens = $this->issueTokensForRole(Role::Admin);
        $secondTokens = $this->issueTokensForRole(Role::Analyst);

        $idempotencyKey = 'train:'.$model->id;

        $firstResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$firstTokens['accessToken'],
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/models/train', [
            'model_id' => $model->id,
            'hyperparameters' => ['max_depth' => 4],
        ]);

        $firstResponse->assertAccepted();

        $secondResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$secondTokens['accessToken'],
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/models/train', [
            'model_id' => $model->id,
            'hyperparameters' => ['max_depth' => 4],
        ]);

        $secondResponse->assertAccepted();

        $this->assertNotSame($firstResponse->json('data.training_run_id'), $secondResponse->json('data.training_run_id'));
        $this->assertNotSame($firstResponse->json('data.job_id'), $secondResponse->json('data.job_id'));

        Bus::assertDispatchedTimes(TrainModelJob::class, 2);
        Event::assertDispatchedTimes(ModelStatusUpdated::class, 4);
        $this->assertSame(2, TrainingRun::query()->count());
    }

    public function test_model_training_is_rate_limited(): void
    {
        Bus::fake();
        Event::fake([ModelStatusUpdated::class]);
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        config(['api.model_training_rate_limit' => 5]);

        $model = PredictiveModel::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
                ->postJson('/api/v1/models/train', [
                    'model_id' => $model->id,
                    'hyperparameters' => ['learning_rate' => 0.1 + $i],
                ]);

            $response->assertAccepted();
        }

        $limitResponse = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/v1/models/train', [
                'model_id' => $model->id,
                'hyperparameters' => ['learning_rate' => 0.6],
            ]);

        $limitResponse->assertStatus(429);
        $limitResponse->assertJsonPath('error.code', 'too_many_requests');

        RateLimiter::clear('model-train|'.$tokens['user']->getAuthIdentifier());
    }

    public function test_admin_can_activate_model(): void
    {
        $adminTokens = $this->issueTokensForRole(Role::Admin);

        $currentActive = PredictiveModel::factory()->create([
            'tag' => 'baseline',
            'area' => 'Downtown',
            'status' => ModelStatus::Active,
        ]);

        $candidate = PredictiveModel::factory()->create([
            'tag' => 'baseline',
            'area' => 'Downtown',
            'status' => ModelStatus::Inactive,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$adminTokens['accessToken'])
            ->postJson("/api/v1/models/{$candidate->id}/activate");

        $response->assertOk();
        $response->assertJsonPath('data.id', $candidate->id);
        $response->assertJsonPath('data.status', ModelStatus::Active->value);

        $this->assertDatabaseHas('models', [
            'id' => $candidate->id,
            'status' => ModelStatus::Active->value,
        ]);

        $this->assertDatabaseHas('models', [
            'id' => $currentActive->id,
            'status' => ModelStatus::Inactive->value,
        ]);
    }

    public function test_activation_requires_admin_role(): void
    {
        $analystTokens = $this->issueTokensForRole(Role::Analyst);

        $model = PredictiveModel::factory()->create([
            'status' => ModelStatus::Inactive,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$analystTokens['accessToken'])
            ->postJson("/api/v1/models/{$model->id}/activate");

        $response->assertForbidden();

        $this->assertDatabaseHas('models', [
            'id' => $model->id,
            'status' => ModelStatus::Inactive->value,
        ]);
    }

    public function test_admin_can_deactivate_model(): void
    {
        $adminTokens = $this->issueTokensForRole(Role::Admin);

        $model = PredictiveModel::factory()->create([
            'status' => ModelStatus::Active,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$adminTokens['accessToken'])
            ->postJson("/api/v1/models/{$model->id}/deactivate");

        $response->assertOk();
        $response->assertJsonPath('data.id', $model->id);
        $response->assertJsonPath('data.status', ModelStatus::Inactive->value);

        $this->assertDatabaseHas('models', [
            'id' => $model->id,
            'status' => ModelStatus::Inactive->value,
        ]);
    }

    public function test_deactivation_requires_admin_role(): void
    {
        $analystTokens = $this->issueTokensForRole(Role::Analyst);

        $model = PredictiveModel::factory()->create([
            'status' => ModelStatus::Active,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$analystTokens['accessToken'])
            ->postJson("/api/v1/models/{$model->id}/deactivate");

        $response->assertForbidden();

        $this->assertDatabaseHas('models', [
            'id' => $model->id,
            'status' => ModelStatus::Active->value,
        ]);
    }

    public function test_evaluation_request_dispatches_job(): void
    {
        Bus::fake();
        Event::fake([ModelStatusUpdated::class]);
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        $model = PredictiveModel::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson("/api/v1/models/{$model->id}/evaluate", [
                'dataset_id' => $model->dataset_id,
                'metrics' => ['f1' => 0.82],
                'notes' => 'Smoke test',
            ]);

        $response->assertAccepted();

        Bus::assertDispatched(EvaluateModelJob::class);

        Event::assertDispatched(ModelStatusUpdated::class, function (ModelStatusUpdated $event) use ($model): bool {
            return $event->modelId === $model->id && $event->state === 'evaluating' && $event->progress === 0.0;
        });
    }

    public function test_evaluation_request_uses_idempotency_key(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        Bus::fake();
        Event::fake([ModelStatusUpdated::class]);
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        $model = PredictiveModel::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        $headers = [
            'Authorization' => 'Bearer '.$tokens['accessToken'],
            'Idempotency-Key' => 'evaluate:'.$model->id,
        ];

        $firstResponse = $this->withHeaders($headers)->postJson("/api/v1/models/{$model->id}/evaluate", [
            'dataset_id' => $model->dataset_id,
            'metrics' => ['macro_precision' => 0.9],
        ]);

        $firstResponse->assertAccepted();

        $secondResponse = $this->withHeaders($headers)->postJson("/api/v1/models/{$model->id}/evaluate", [
            'dataset_id' => $model->dataset_id,
            'metrics' => ['macro_precision' => 0.9],
        ]);

        $secondResponse->assertAccepted();

        $this->assertSame($firstResponse->json('data.job_id'), $secondResponse->json('data.job_id'));

        Bus::assertDispatchedTimes(EvaluateModelJob::class);
        Event::assertDispatchedTimes(ModelStatusUpdated::class, 2);
    }

    public function test_evaluation_request_requires_numeric_metrics(): void
    {
        $model = PredictiveModel::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson("/api/v1/models/{$model->id}/evaluate", [
                'dataset_id' => $model->dataset_id,
                'metrics' => ['macro_precision' => 'high'],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['metrics.macro_precision']);
    }

    public function test_model_evaluation_is_rate_limited(): void
    {
        Bus::fake();
        Event::fake([ModelStatusUpdated::class]);
        Redis::shouldReceive('setex')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturnTrue();
        Redis::shouldReceive('get')->zeroOrMoreTimes()->andReturn(null);
        Redis::shouldReceive('del')->zeroOrMoreTimes()->andReturnTrue();

        config(['api.model_evaluation_rate_limit' => 5]);

        $model = PredictiveModel::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
                ->postJson("/api/v1/models/{$model->id}/evaluate", [
                    'dataset_id' => $model->dataset_id,
                    'metrics' => ['accuracy' => 0.8 + $i * 0.01],
                ]);

            $response->assertAccepted();
        }

        $limitResponse = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson("/api/v1/models/{$model->id}/evaluate", [
                'dataset_id' => $model->dataset_id,
                'metrics' => ['accuracy' => 0.9],
            ]);

        $limitResponse->assertStatus(429);
        $limitResponse->assertJsonPath('error.code', 'too_many_requests');

        RateLimiter::clear('model-evaluate|'.$tokens['user']->getAuthIdentifier());
    }

    public function test_status_endpoint_returns_progress_snapshot(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);
        $model = PredictiveModel::factory()->create();

        $snapshot = [
            'state' => 'training',
            'progress' => 45.5,
            'updated_at' => now()->toIso8601String(),
        ];

        Redis::shouldReceive('get')->once()->andReturn(json_encode($snapshot));

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson("/api/v1/models/{$model->id}/status");

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'state' => 'training',
                'progress' => 45.5,
                'updated_at' => $snapshot['updated_at'],
                'message' => null,
            ],
        ]);
    }

    public function test_index_returns_paginated_collection_with_filters_and_sorting(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);

        PredictiveModel::factory()->create([
            'id' => 'model-latest',
            'name' => 'Latest Model',
            'status' => ModelStatus::Active,
            'tag' => 'baseline',
            'trained_at' => now(),
            'updated_at' => now(),
        ]);

        PredictiveModel::factory()->create([
            'id' => 'model-older',
            'name' => 'Older Model',
            'status' => ModelStatus::Active,
            'tag' => 'baseline',
            'trained_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        PredictiveModel::factory()->create([
            'id' => 'model-other',
            'name' => 'Filtered Model',
            'status' => ModelStatus::Inactive,
            'tag' => 'baseline',
        ]);

        $firstPageQuery = http_build_query([
            'page' => 1,
            'per_page' => 1,
            'sort' => '-trained_at',
            'filter' => [
                'status' => 'active',
                'tag' => 'baseline',
            ],
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/v1/models?'.$firstPageQuery);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $payload = $response->json();

        $this->assertSame('model-latest', $payload['data'][0]['id']);
        $this->assertSame(2, $payload['meta']['total']);
        $this->assertSame(1, $payload['meta']['per_page']);
        $this->assertSame(1, $payload['meta']['current_page']);
        $this->assertNotEmpty($payload['links']['next']);

        $secondPageQuery = http_build_query([
            'page' => 2,
            'per_page' => 1,
            'sort' => '-trained_at',
            'filter' => [
                'status' => 'active',
                'tag' => 'baseline',
            ],
        ], '', '&', PHP_QUERY_RFC3986);

        $secondPage = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/v1/models?'.$secondPageQuery);

        $secondPage->assertOk();
        $secondPage->assertJsonPath('success', true);

        $secondPayload = $secondPage->json();

        $this->assertSame('model-older', $secondPayload['data'][0]['id']);
        $this->assertNull($secondPayload['links']['next']);
    }
}
