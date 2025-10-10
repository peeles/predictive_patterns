<?php

namespace App\Jobs\Factories;

use App\Jobs\EvaluateModelJob;
use App\Jobs\TrainModelJob;

class ModelJobFactory
{
    /**
     * @param array<string, mixed>|null $hyperparameters
     */
    public static function training(
        string $trainingRunId,
        ?array $hyperparameters = null,
        ?string $webhookUrl = null,
        ?string $userId = null,
    ): TrainModelJob {
        return new TrainModelJob($trainingRunId, $hyperparameters, $webhookUrl, $userId);
    }

    /**
     * @param array<string, mixed>|null $metrics
     */
    public static function evaluation(
        string $modelId,
        ?string $datasetId = null,
        ?array $metrics = null,
        ?string $notes = null,
    ): EvaluateModelJob {
        return new EvaluateModelJob($modelId, $datasetId, $metrics, $notes);
    }
}
