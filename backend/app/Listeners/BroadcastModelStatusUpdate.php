<?php

namespace App\Listeners;

use App\Domain\Models\Events\ModelStatusChanged;
use App\Domain\Models\Events\ModelTrained;
use App\Enums\ModelStatus;
use App\Events\ModelStatusUpdated;
use App\Models\PredictiveModel;
use App\Support\Broadcasting\BroadcastDispatcher;
use Carbon\CarbonInterface;

class BroadcastModelStatusUpdate
{
    public function handle(ModelStatusChanged|ModelTrained $event): void
    {
        if ($event instanceof ModelStatusChanged) {
            $modelId = $event->modelId;
            $state = $event->state;
            $progress = $this->normalizeProgress($event->progress);
            $updatedAt = $event->updatedAt;
            $message = $event->message;
        } else {
            $model = $event->model;
            $modelId = (string) $model->getKey();
            $state = $this->resolveModelState($model);
            $progress = 100.0;
            $updatedAt = $this->resolveTimestamp($model);
            $message = null;
        }

        $broadcastEvent = new ModelStatusUpdated(
            $modelId,
            $state,
            $progress,
            $updatedAt,
            $message,
        );

        BroadcastDispatcher::dispatch($broadcastEvent, [
            'model_id' => $modelId,
            'state' => $state,
        ]);
    }

    private function normalizeProgress(?float $progress): ?float
    {
        if ($progress === null) {
            return null;
        }

        if (is_nan($progress) || is_infinite($progress)) {
            return null;
        }

        return round(min(100.0, max(0.0, $progress)), 2);
    }

    private function resolveModelState(PredictiveModel $model): string
    {
        $status = $model->status;

        if ($status instanceof ModelStatus) {
            return $status->value;
        }

        $value = (string) $status;

        return $value !== '' ? $value : ModelStatus::Active->value;
    }

    private function resolveTimestamp(PredictiveModel $model): string
    {
        $timestamp = $model->trained_at ?? $model->updated_at ?? $model->created_at;

        if ($timestamp instanceof CarbonInterface) {
            return $timestamp->toIso8601String();
        }

        return now()->toIso8601String();
    }
}
