<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\GenerateHeatmapJob;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Carbon;
use Illuminate\Testing\Fluent\AssertableJson;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PredictionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_prediction_request_dispatches_job(): void
    {
        Bus::fake();

        $model = PredictiveModel::factory()->create();
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/predictions', [
            'model_id' => $model->id,
            'dataset_id' => $model->dataset_id,
            'parameters' => ['horizon_days' => 7],
            'generate_tiles' => true,
        ]);

        $response->assertAccepted();

        Bus::assertDispatched(GenerateHeatmapJob::class);

        $this->assertDatabaseHas('predictions', [
            'model_id' => $model->id,
            'status' => 'queued',
        ]);
    }

    public function test_prediction_request_returns_field_errors_for_invalid_payload(): void
    {
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/v1/predictions', [
                'model_id' => 'not-a-uuid',
                'parameters' => ['horizon_days' => 7],
            ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('errors.model_id.0', 'The model id must be a valid UUID.');
    }

    public function test_predictions_index_returns_paginated_filtered_results(): void
    {
        Carbon::setTestNow('2025-01-01 12:00:00');

        try {
            $model = PredictiveModel::factory()->create();
            $anotherModel = PredictiveModel::factory()->create();

            $matchingPredictions = collect();

            foreach (range(0, 5) as $offset) {
                $matchingPredictions->push(
                    Prediction::factory()
                        ->for($model, 'model')
                        ->completed()
                        ->create([
                            'queued_at' => Carbon::now()->subMinutes($offset + 10),
                            'started_at' => Carbon::now()->subMinutes($offset + 9),
                            'finished_at' => Carbon::now()->subMinutes($offset + 8),
                            'created_at' => Carbon::now()->subMinutes($offset + 10),
                            'updated_at' => Carbon::now()->subMinutes($offset + 8),
                        ])
                );
            }

            // Should be excluded due to model filter.
            Prediction::factory()
                ->for($anotherModel, 'model')
                ->completed()
                ->create([
                    'queued_at' => Carbon::now()->subMinutes(15),
                    'finished_at' => Carbon::now()->subMinutes(14),
                    'created_at' => Carbon::now()->subMinutes(15),
                ]);

            // Should be excluded due to timeframe filter.
            Prediction::factory()
                ->for($model, 'model')
                ->completed()
                ->create([
                    'queued_at' => Carbon::now()->subDays(3),
                    'finished_at' => Carbon::now()->subDays(3)->addMinutes(1),
                    'created_at' => Carbon::now()->subDays(3),
                ]);

            $tokens = $this->issueTokensForRole(Role::Analyst);

            $query = http_build_query([
                'per_page' => 5,
                'filter' => [
                    'status' => 'completed',
                    'model_id' => $model->id,
                    'from' => Carbon::now()->subDay()->toIso8601String(),
                ],
            ]);

            $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
                ->getJson('/api/v1/predictions?'.$query);

            $response->assertOk();
            $response->assertJsonPath('success', true);

            $response->assertJsonPath('meta.per_page', 5)
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.total', 6);

            $response->assertJsonCount(5, 'data');

            $ids = collect($response->json('data'))->pluck('id');

            $sorted = $matchingPredictions
                ->sortByDesc(fn (Prediction $prediction) => $prediction->created_at)
                ->values();

            $this->assertTrue($ids->contains($sorted->get(0)->id));
            $this->assertFalse($ids->contains($anotherModel->predictions()->latest()->first()?->id));
            $this->assertFalse($ids->contains(
                $model->predictions()
                    ->whereDate('created_at', '<', Carbon::now()->subDays(2)->toDateString())
                    ->value('id')
            ));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_predictions_index_filters_by_multiple_statuses(): void
    {
        $model = PredictiveModel::factory()->create();
        $otherModel = PredictiveModel::factory()->create();

        $matchingPredictions = [
            Prediction::factory()->for($model, 'model')->completed()->create(),
            Prediction::factory()->for($model, 'model')->failed()->create(),
        ];

        Prediction::factory()->for($model, 'model')->running()->create();
        Prediction::factory()->for($otherModel, 'model')->completed()->create();

        $tokens = $this->issueTokensForRole(Role::Analyst);

        $query = http_build_query([
            'filter' => [
                'status' => ['completed', 'failed'],
                'model_id' => $model->id,
            ],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/v1/predictions?'.$query);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $statuses = collect($response->json('data'))
            ->pluck('status')
            ->sort()
            ->values()
            ->all();

        $expectedStatuses = collect($matchingPredictions)
            ->map(fn (Prediction $prediction): string => $prediction->status->value)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedStatuses, $statuses);
    }
    public function test_prediction_show_includes_shap_values(): void
    {
        $tokens = $this->issueTokensForRole(Role::Analyst);

        $prediction = Prediction::factory()->completed()->create();

        $prediction->shapValues()->createMany([
            [
                'feature_name' => 'Response time',
                'value' => 0.432157,
                'details' => ['direction' => 'positive'],
            ],
            [
                'feature_name' => 'Population density',
                'value' => -0.278914,
                'details' => null,
            ],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson(sprintf('/api/v1/predictions/%s', $prediction->id));

        $response->assertOk();

        $response->assertJson(function (AssertableJson $json): void {
            $json->where('success', true)
                ->where('data.shap_values.0.name', 'Response time')
                ->where('data.shap_values.0.feature_name', 'Response time')
                ->where('data.shap_values.0.contribution', fn ($value) => abs($value - 0.432157) < 1e-6)
                ->where('data.shap_values.0.details.direction', 'positive')
                ->where('data.shap_values.1.name', 'Population density')
                ->where('data.shap_values.1.contribution', fn ($value) => abs($value + 0.278914) < 1e-6)
                ->etc();
        });
    }
}
