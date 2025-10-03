<?php

namespace Database\Factories;

use App\Enums\PredictionStatus;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Prediction>
 */
class PredictionFactory extends Factory
{
    protected $model = Prediction::class;

    public function definition(): array
    {
        $queuedAt = Carbon::now()->subMinutes($this->faker->numberBetween(1, 120));

        return [
            'id' => (string) Str::uuid(),
            'model_id' => PredictiveModel::factory(),
            'dataset_id' => null,
            'status' => PredictionStatus::Queued,
            'parameters' => [
                'center' => [
                    'lat' => $this->faker->latitude(51.0, 52.0),
                    'lng' => $this->faker->longitude(-0.5, 0.5),
                ],
                'horizon_hours' => $this->faker->numberBetween(1, 72),
                'radius_km' => $this->faker->randomFloat(1, 0.5, 5),
            ],
            'metadata' => ['source' => 'factory'],
            'error_message' => null,
            'queued_at' => $queuedAt,
            'started_at' => null,
            'finished_at' => null,
            'initiated_by' => User::factory(),
            'created_at' => $queuedAt,
            'updated_at' => $queuedAt,
        ];
    }

    public function running(): self
    {
        return $this->state(function (array $attributes): array {
            $queuedAt = $attributes['queued_at'] instanceof Carbon ? $attributes['queued_at'] : Carbon::now();
            return [
                'status' => PredictionStatus::Running,
                'started_at' => (clone $queuedAt)->addMinutes(5),
                'finished_at' => null,
                'error_message' => null,
            ];
        });
    }

    public function completed(): self
    {
        return $this->state(function (array $attributes): array {
            $start = $attributes['started_at'] instanceof Carbon
                ? $attributes['started_at']
                : ($attributes['queued_at'] instanceof Carbon ? (clone $attributes['queued_at'])->addMinutes(5) : Carbon::now());

            return [
                'status' => PredictionStatus::Completed,
                'started_at' => $start,
                'finished_at' => (clone $start)->addMinutes(10),
                'error_message' => null,
            ];
        });
    }

    public function failed(): self
    {
        return $this->state(function (array $attributes): array {
            $start = $attributes['started_at'] instanceof Carbon
                ? $attributes['started_at']
                : ($attributes['queued_at'] instanceof Carbon ? (clone $attributes['queued_at'])->addMinutes(3) : Carbon::now());

            return [
                'status' => PredictionStatus::Failed,
                'started_at' => $start,
                'finished_at' => (clone $start)->addMinutes(2),
                'error_message' => 'Prediction failed during processing.',
            ];
        });
    }
}
