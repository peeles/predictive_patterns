<?php

namespace App\Jobs;

use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Models\TrainingRun;
use App\Services\ModelStatusService;
use App\Services\ModelTrainingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Random\RandomException;
use Throwable;

class TrainModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed>|null $hyperparameters
     */
    public function __construct(private readonly string $trainingRunId, private readonly ?array $hyperparameters = null)
    {
        $this->onConnection('training');
        $this->onQueue(config('queue.connections.training.queue', 'training'));
    }

    /**
     * @throws Throwable
     * @throws RandomException
     */
    public function handle(ModelTrainingService $trainingService, ModelStatusService $statusService): void
    {
        $run = TrainingRun::query()->with('model')->findOrFail($this->trainingRunId);
        $model = $run->model;

        if ($model === null) {
            Log::warning('Training run without associated model', ['training_run_id' => $run->id]);

            return;
        }

        $run->fill([
            'status' => TrainingStatus::Running,
            'started_at' => now(),
            'error_message' => null,
            'hyperparameters' => $this->hyperparameters ?? $run->hyperparameters,
        ])->save();

        $model->fill([
            'status' => ModelStatus::Training,
        ])->save();

        $statusService->markProgress($model->id, 'training', 5.0, 'Preparing training run');

        try {
            $result = $trainingService->train(
                $run,
                $model,
                $this->hyperparameters ?? $run->hyperparameters ?? [],
                function (float $progress, ?string $message = null) use ($statusService, $model): void {
                    $statusService->markProgress($model->id, 'training', $progress, $message);
                }
            );

            $statusService->markProgress($model->id, 'training', 95.0, 'Finalizing training results');
            $metrics = $result['metrics'];
            $metadata = array_merge($model->metadata ?? [], $result['metadata']);

            $run->fill([
                'status' => TrainingStatus::Completed,
                'finished_at' => now(),
                'metrics' => $metrics,
                'hyperparameters' => $result['hyperparameters'],
            ])->save();

            $model->fill([
                'status' => ModelStatus::Active,
                'trained_at' => now(),
                'metrics' => $metrics,
                'version' => $result['version'],
                'metadata' => $metadata,
                'hyperparameters' => $result['hyperparameters'],
            ])->save();

            $statusService->markIdle($model->id);
        }
        catch (Throwable $exception) {
            $run->fill([
                'status' => TrainingStatus::Failed,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            $model->fill([
                'status' => ModelStatus::Failed,
            ])->save();

            Log::error('Training job failed', [
                'training_run_id' => $run->id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_trace' => $exception->getTraceAsString(),
                'memory_usage_bytes' => memory_get_usage(true),
                'memory_peak_bytes' => memory_get_peak_usage(true),
            ]);

            $statusService->markFailed($model->id, $exception->getMessage());

            throw $exception;
        }
    }
}
