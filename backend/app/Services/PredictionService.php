<?php

namespace App\Services;

use App\Enums\PredictionStatus;
use App\Events\PredictionStatusUpdated;
use App\Jobs\GenerateHeatmapJob;
use App\Models\Dataset;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use App\Models\User;
use App\Support\Broadcasting\BroadcastDispatcher;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Bus\Dispatcher;
use App\Support\DatabaseTransactionHelper;
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
        return DatabaseTransactionHelper::runWithoutNestedTransaction(function () use ($model, $dataset, $parameters, $generateTiles, $user, $metadata): Prediction {
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

            $prediction->refresh();

            $event = PredictionStatusUpdated::fromPrediction(
                $prediction,
                0.0,
                'Prediction queued. Awaiting available worker.'
            );

            BroadcastDispatcher::dispatch($event, [
                'prediction_id' => $event->predictionId,
                'status' => $event->status,
            ]);

            $this->dispatcher->dispatch(new GenerateHeatmapJob($prediction->id, $parameters, $generateTiles));

            return $prediction->fresh() ?? $prediction;
        });
    }
}
