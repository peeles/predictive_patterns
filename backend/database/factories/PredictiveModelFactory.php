<?php

namespace Database\Factories;

use App\Enums\ModelStatus;
use App\Models\Dataset;
use App\Models\PredictiveModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PredictiveModel>
 */
class PredictiveModelFactory extends Factory
{
    protected $model = PredictiveModel::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'dataset_id' => Dataset::factory(),
            'name' => $this->faker->words(2, true),
            'version' => '1.0.0',
            'tag' => $this->faker->randomElement(['baseline', 'experimental']),
            'area' => $this->faker->city(),
            'status' => ModelStatus::Active,
            'hyperparameters' => ['learning_rate' => 0.1],
            'metadata' => ['created_by' => 'factory'],
            'metrics' => ['accuracy' => 0.8],
            'trained_at' => now(),
        ];
    }
}
