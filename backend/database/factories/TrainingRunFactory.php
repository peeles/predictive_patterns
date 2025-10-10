<?php

namespace Database\Factories;

use App\Enums\TrainingStatus;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingRun>
 */
class TrainingRunFactory extends Factory
{
    protected $model = TrainingRun::class;

    public function definition(): array
    {
        return [
            'model_id' => PredictiveModel::factory(),
            'status' => TrainingStatus::Queued,
            'hyperparameters' => null,
            'metrics' => null,
            'error_message' => null,
            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
            'initiated_by' => User::factory(),
        ];
    }
}
