<?php

namespace App\Jobs;

use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Services\ModelEvaluationService;
use App\Services\ModelStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Random\RandomException;
use RuntimeException;
use Throwable;

class EvaluateModelJob implements ShouldQueue
{
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
    }

    /**
     * @throws Throwable
     * @throws RandomException
     */
    public function handle(ModelStatusService $statusService, ModelEvaluationService $evaluationService): void
    {
        $model = PredictiveModel::query()->findOrFail($this->modelId);
        $metadata = $model->metadata ?? [];

        $statusService->markProgress($model->id, 'evaluating', 5.0);

        $dataset = null;
        $metrics = $this->metrics;

        try {
            if ($this->datasetId !== null) {
                $dataset = Dataset::query()->findOrFail($this->datasetId);
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

            $statusService->markProgress($model->id, 'evaluating', 55.0);

            $model->metadata = $metadata;
            $model->save();

            $statusService->markProgress($model->id, 'evaluating', 85.0);
            $statusService->markIdle($model->id);
        } catch (Throwable $exception) {
            Log::error('Failed to evaluate model', [
                'model_id' => $model->id,
                'dataset_id' => $dataset?->id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_trace' => $exception->getTraceAsString(),
            ]);

            $statusService->markFailed($model->id, $exception->getMessage());

            throw $exception;
        }
    }
}
