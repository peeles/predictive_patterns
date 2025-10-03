<?php

namespace App\Services;

use App\Enums\PredictionStatus;
use App\Jobs\GenerateHeatmapJob;
use App\Models\Dataset;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PredictionService
{
    public function __construct(private readonly Dispatcher $dispatcher)
    {
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed>|null $metadata
     */
    public function queuePrediction(PredictiveModel $model, ?Dataset $dataset, array $parameters, bool $generateTiles, ?Authenticatable $user = null, ?array $metadata = null): Prediction
    {
        return DB::transaction(function () use ($model, $dataset, $parameters, $generateTiles, $user, $metadata): Prediction {
            $prediction = new Prediction([
                'id' => (string) Str::uuid(),
                'status' => PredictionStatus::Queued,
                'parameters' => $parameters,
                'metadata' => $metadata,
                'queued_at' => now(),
                'initiated_by' => $user instanceof User ? $user->getKey() : null,
            ]);

            $prediction->model()->associate($model);

            if ($dataset !== null) {
                $prediction->dataset()->associate($dataset);
            }

            $prediction->save();

            $this->dispatcher->dispatch(new GenerateHeatmapJob($prediction->id, $parameters, $generateTiles));

            return $prediction->fresh() ?? $prediction;
        });
    }
}
