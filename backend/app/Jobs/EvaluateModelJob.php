<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksProgress;
use App\Jobs\Middleware\LogJobExecution;
use App\Models\Dataset;
use App\Repositories\DatasetRepositoryInterface;
use App\Repositories\PredictiveModelRepositoryInterface;
use App\Services\ModelEvaluationService;
use App\Services\ModelStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Random\RandomException;
use RuntimeException;
use Throwable;

class EvaluateModelJob implements ShouldQueue
{
    use TracksProgress;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed>|null $metrics
     */
    public function __construct(
        private readonly string $modelId,
        private readonly ?string $datasetId = null,
        private readonly ?array $metrics = null,
        private readonly ?string $notes = null,
    ) {
        $this->onConnection('training');
        $this->onQueue(config('queue.connections.training.queue', 'training'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LogJobExecution(),
            new RateLimited('default'),
        ];
    }

    /**
     * @throws Throwable
     * @throws RandomException
     */
    public function handle(
        ModelStatusService $statusService,
        ModelEvaluationService $evaluationService,
        ?PredictiveModelRepositoryInterface $models = null,
        ?DatasetRepositoryInterface $datasets = null,
    ): void {
        $models ??= app(PredictiveModelRepositoryInterface::class);
        $datasets ??= app(DatasetRepositoryInterface::class);

        $model = $models->findOrFail($this->modelId);
        $metadata = $model->metadata ?? [];

        $this->progressModel = $model;
        $this->progressEntityId = $model->id;
        $this->progressStage = 'evaluating';

        $statusService->markProgress($model->id, 'evaluating', 5.0, 'Preparing evaluation run');
        $this->updateProgress(5, 'Preparing evaluation run');

        $dataset = null;
        $metrics = $this->metrics;

        try {
            if ($this->datasetId !== null) {
                $dataset = $datasets->findOrFail($this->datasetId);
            } else {
                $dataset = $model->dataset;
            }

            if ($metrics === null) {
                if (! $dataset instanceof Dataset) {
                    throw new RuntimeException('No dataset available for evaluation.');
                }

                $metrics = $evaluationService->evaluate($model, $dataset);
            }

            $entry = [
                'id' => (string) Str::uuid(),
                'evaluated_at' => now()->toIso8601String(),
                'dataset_id' => $dataset?->id,
                'metrics' => $metrics,
            ];

            if ($this->notes !== null) {
                $entry['notes'] = $this->notes;
            }

            $metadata['evaluations'] = array_values(array_filter(
                array_merge($metadata['evaluations'] ?? [], [$entry]),
                static fn ($value): bool => is_array($value)
            ));

            $statusService->markProgress($model->id, 'evaluating', 55.0, 'Recording evaluation metrics');
            $this->updateProgress(55, 'Recording evaluation metrics');

            $model->metadata = $metadata;
            $model->save();

            $statusService->markProgress($model->id, 'evaluating', 85.0, 'Finalising evaluation summary');
            $this->updateProgress(85, 'Finalising evaluation summary');
            $statusService->markIdle($model->id);
            $accuracy = is_array($metrics) ? ($metrics['accuracy'] ?? null) : null;
            $this->updateProgress(100, 'Evaluation complete', ['accuracy' => $accuracy]);
        } catch (Throwable $exception) {
            Log::error('Failed to evaluate model', [
                'model_id' => $model->id,
                'dataset_id' => $dataset?->id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_trace' => $exception->getTraceAsString(),
            ]);

            $statusService->markFailed($model->id, $exception->getMessage());
            $this->updateProgress(100, $exception->getMessage());

            throw $exception;
        }
    }
}
