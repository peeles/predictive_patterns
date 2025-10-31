<?php

namespace App\Observers;

use App\Enums\ModelStatus;
use App\Events\ModelStatusUpdated;
use App\Models\PredictiveModel;

class PredictiveModelObserver
{
    /**
     * @var array<int, bool>
     */
    private array $shouldBroadcast = [];

    public function updating(PredictiveModel $model): void
    {
        $key = spl_object_id($model);
        $this->shouldBroadcast[$key] = $model->isDirty('status')
            || $model->isDirty('progress')
            || $model->isDirty('state');
    }

    public function updated(PredictiveModel $model): void
    {
        $key = spl_object_id($model);
        $shouldBroadcast = $this->shouldBroadcast[$key] ?? false;
        unset($this->shouldBroadcast[$key]);

        if (! $shouldBroadcast) {
            return;
        }

        $status = $this->resolveStatus($model);
        $state = $this->resolveState($model, $status);
        $progress = $this->resolveProgress($model);
        $metrics = $this->resolveTrainingMetrics($model);
        $errorMessage = $this->resolveErrorMessage($model, $state);

        event(new ModelStatusUpdated(
            modelId: (string) $model->getKey(),
            status: $status,
            progress: $progress ?? 0.0,
            message: null,
            metrics: $metrics,
            errorMessage: $errorMessage,
            trainingMetrics: $metrics,
        ));
    }

    private function resolveStatus(PredictiveModel $model): string
    {
        $status = $model->status;

        if ($status instanceof ModelStatus) {
            return $status->value;
        }

        $status = (string) $status;

        return $status !== '' ? $status : ModelStatus::Draft->value;
    }

    private function resolveState(PredictiveModel $model, string $fallback): string
    {
        $state = $model->getAttribute('state');

        if (is_string($state) && $state !== '') {
            return $state;
        }

        return $fallback;
    }

    private function resolveProgress(PredictiveModel $model): ?float
    {
        $progress = $model->getAttribute('progress');

        if (is_numeric($progress)) {
            return (float) $progress;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveTrainingMetrics(PredictiveModel $model): ?array
    {
        $metrics = $model->getAttribute('training_metrics');

        if (is_array($metrics)) {
            return $metrics;
        }

        $metrics = $model->getAttribute('metrics');

        return is_array($metrics) ? $metrics : null;
    }

    private function resolveErrorMessage(PredictiveModel $model, string $state): ?string
    {
        $error = $model->getAttribute('error_message');

        if (is_string($error)) {
            $trimmed = trim($error);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        if ($state !== ModelStatus::Failed->value) {
            return null;
        }

        $latestRun = $model->trainingRuns()
            ->latest('created_at')
            ->first();

        $message = data_get($latestRun, 'error_message');

        if (! is_string($message)) {
            return null;
        }

        $trimmed = trim($message);

        return $trimmed !== '' ? $trimmed : null;
    }
}
