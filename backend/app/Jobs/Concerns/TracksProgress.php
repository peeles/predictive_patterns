<?php

namespace App\Jobs\Concerns;

use App\Events\ProgressUpdated;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Throwable;

trait TracksProgress
{
    private array $lastBroadcasts = [];

    protected ?PredictiveModel $progressModel = null;

    protected ?TrainingRun $progressRun = null;

    protected ?string $progressEntityId = null;

    protected ?string $progressStage = null;

    protected array $progressStages = [
        0 => 'Initialising',
        5 => 'Loading dataset',
        15 => 'Preprocessing data',
        25 => 'Starting training',
        35 => 'Configuring model pipeline',
        45 => 'Preparing feature set',
        55 => 'Training model',
        65 => 'Analysing intermediate metrics',
        75 => 'Validating results',
        85 => 'Finalising artefacts',
        95 => 'Saving model',
        100 => 'Training complete',
    ];

    /**
     * @param array{current_epoch?: int|null, total_epochs?: int|null, loss?: float|null, accuracy?: float|null}|null $metrics
     */
    protected function updateProgress(int $progress, string $message, ?array $metrics = null): void
    {
        $context = $this->resolveProgressContext();
        $percent = $this->normaliseProgress($progress);
        $resolvedMessage = trim($message) !== ''
            ? $message
            : $this->defaultProgressMessage((int) round($percent));

        $metricsPayload = $this->normaliseMetrics($metrics);

        $payload = [
            'percent' => $percent,
            'message' => $resolvedMessage,
            'updated_at' => now()->toIso8601String(),
            'current_epoch' => $metricsPayload['current_epoch'],
            'total_epochs' => $metricsPayload['total_epochs'],
            'loss' => $metricsPayload['loss'],
            'accuracy' => $metricsPayload['accuracy'],
        ];

        Cache::put($context['cache_key'], $payload, now()->addMinutes(10));

        $this->persistModelProgress($context['model'], $payload);

        if ($this->shouldBroadcastProgress($context['broadcast_key'], $percent)) {
            broadcast(new ProgressUpdated(
                $context['entity_id'],
                $context['stage'],
                $percent,
                $resolvedMessage,
                $metricsPayload
            ));
        }
    }

    protected function defaultProgressMessage(int $progress): string
    {
        $thresholds = array_keys($this->progressStages);
        rsort($thresholds);

        foreach ($thresholds as $threshold) {
            if ($progress >= $threshold) {
                return $this->progressStages[$threshold];
            }
        }

        return $this->progressStages[0] ?? 'Processing';
    }

    private function resolveProgressContext(): array
    {
        $entityId = $this->resolveProgressEntityId();
        $stage = $this->resolveProgressStage();
        $model = $this->resolveProgressModel();
        $cacheKey = sprintf('progress.%s.%s', $entityId, $stage);

        return [
            'entity_id' => $entityId,
            'stage' => $stage,
            'cache_key' => $cacheKey,
            'broadcast_key' => $cacheKey,
            'model' => $model,
        ];
    }

    private function resolveProgressEntityId(): string
    {
        if ($this->progressEntityId !== null) {
            return $this->progressEntityId;
        }

        if ($this->progressModel instanceof PredictiveModel) {
            return $this->progressModel->getKey();
        }

        if ($this->progressRun instanceof TrainingRun) {
            return $this->progressRun->getKey();
        }

        if (property_exists($this, 'trainingRunId')) {
            return (string) $this->trainingRunId;
        }

        if (property_exists($this, 'modelId')) {
            return (string) $this->modelId;
        }

        return spl_object_hash($this);
    }

    private function resolveProgressStage(): string
    {
        $stage = $this->progressStage;

        if ($stage === null || $stage === '') {
            $stage = str_contains(static::class, 'Evaluate') ? 'evaluating' : 'training';
        }

        $this->progressStage = $stage;

        return $stage;
    }

    private function persistModelProgress(?PredictiveModel $model, array $payload): void
    {
        if (! $model instanceof PredictiveModel) {
            return;
        }

        $metadata = $model->metadata ?? [];
        $metadata['training_progress'] = $payload;

        try {
            $model->forceFill(['metadata' => $metadata])->save();
        } catch (Throwable) {
            // Persisting progress is a best-effort operation.
        }
    }

    private function resolveProgressModel(): ?PredictiveModel
    {
        if ($this->progressModel instanceof PredictiveModel) {
            return $this->progressModel;
        }

        if ($this->progressRun instanceof TrainingRun && $this->progressRun->model instanceof PredictiveModel) {
            $this->progressModel = $this->progressRun->model;

            return $this->progressModel;
        }

        if (property_exists($this, 'trainingRunId') && $this->progressRun === null) {
            $this->progressRun = TrainingRun::query()->with('model')->find($this->trainingRunId);

            if ($this->progressRun?->model instanceof PredictiveModel) {
                $this->progressModel = $this->progressRun->model;

                return $this->progressModel;
            }
        }

        if (property_exists($this, 'modelId') && ! ($this->progressModel instanceof PredictiveModel)) {
            $this->progressModel = PredictiveModel::find($this->modelId);
        }

        return $this->progressModel;
    }

    private function shouldBroadcastProgress(string $key, float $newPercent): bool
    {
        $last = $this->lastBroadcasts[$key] ?? null;

        if ($last === null || abs($newPercent - $last) >= 5.0 || $newPercent >= 100.0) {
            $this->lastBroadcasts[$key] = $newPercent;

            return true;
        }

        return false;
    }

    private function normaliseProgress(int $progress): float
    {
        return (float) round(min(100, max(0, $progress)), 2);
    }

    /**
     * @param array{current_epoch?: int|null, total_epochs?: int|null, loss?: float|null, accuracy?: float|null}|null $metrics
     * @return array{current_epoch: int|null, total_epochs: int|null, loss: float|null, accuracy: float|null}
     */
    private function normaliseMetrics(?array $metrics): array
    {
        $currentEpoch = Arr::get($metrics, 'current_epoch', Arr::get($metrics, 'epoch'));
        $totalEpochs = Arr::get($metrics, 'total_epochs', Arr::get($metrics, 'epochs'));
        $loss = Arr::get($metrics, 'loss');
        $accuracy = Arr::get($metrics, 'accuracy', Arr::get($metrics, 'best_accuracy'));

        return [
            'current_epoch' => $this->filterInt($currentEpoch),
            'total_epochs' => $this->filterInt($totalEpochs),
            'loss' => $this->filterFloat($loss),
            'accuracy' => $this->filterFloat($accuracy),
        ];
    }

    private function filterInt(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function filterFloat(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
