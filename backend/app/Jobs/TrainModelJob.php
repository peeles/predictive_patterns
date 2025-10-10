<?php

namespace App\Jobs;

use App\Contracts\Queue\ShouldBeAuthorized;
use App\Domain\Models\Events\ModelTrained;
use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Jobs\Concerns\TracksProgress;
use App\Jobs\Middleware\EnsureJobIsAuthorized;
use App\Jobs\Middleware\LogJobExecution;
use App\Jobs\Middleware\NotifyWebhook;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Models\User;
use App\Services\ModelStatusService;
use App\Services\ModelTrainingService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Random\RandomException;
use Throwable;

class TrainModelJob implements ShouldQueue, ShouldBeUnique, ShouldBeAuthorized
{
    use TracksProgress;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CONNECTION = 'training';
    private const QUEUE = 'training';
    public int $tries = 1;
    public int $timeout = 3600;
    public int $maxExceptions = 1;
    public int $uniqueFor = 3600;



    /**
     * @param array<string, mixed>|null $hyperparameters
     */
    public function __construct(
        private readonly string $trainingRunId,
        private readonly ?array $hyperparameters = null,
        private readonly ?string $webhookUrl = null,
        private readonly ?string $userId = null,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new EnsureJobIsAuthorized(),
            new LogJobExecution(),
            new RateLimited('default'),
            new NotifyWebhook(),
        ];
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function uniqueId(): string
    {
        return "train-model-{$this->trainingRunId}";
    }

    public function authorize(): bool
    {
        $run = TrainingRun::query()->with('model')->find($this->trainingRunId);

        if (! $run instanceof TrainingRun) {
            return false;
        }

        $model = $run->model;

        if (! $model instanceof PredictiveModel) {
            return false;
        }

        $userId = $this->userId ?? $run->initiated_by;

        if ($userId === null) {
            return false;
        }

        $user = User::find($userId);

        if (! $user instanceof User) {
            return false;
        }

        return $user->can('train', $model);
    }

    /**
     * @throws Throwable
     * @throws RandomException
     */
    public function handle(ModelTrainingService $trainingService, ModelStatusService $statusService): void
    {
        $this->recordQueueProgress(0.0);

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
        $this->updateProgress($model->id, 'training', 5.0, 'Preparing training run');
        $this->recordQueueProgress(5.0);

        try {
            $result = $trainingService->train(
                $run,
                $model,
                $this->hyperparameters ?? $run->hyperparameters ?? [],
                function (float $progress, ?string $message = null) use ($statusService, $model): void {
                    $statusService->markProgress($model->id, 'training', $progress, $message);
                    $this->updateProgress($model->id, 'training', $progress, $message);
                    $this->recordQueueProgress($progress);
                }
            );

            $statusService->markProgress($model->id, 'training', 95.0, 'Finalizing training results');
            $this->updateProgress($model->id, 'training', 95.0, 'Finalizing training results');
            $this->recordQueueProgress(95.0);
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
            $model->refresh();

            event(new ModelTrained($model));
            $this->updateProgress($model->id, 'training', 100.0, 'Training complete');
            $this->recordQueueProgress(100.0);
        } catch (Throwable $exception) {
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
            $this->updateProgress($model->id, 'training', 100.0, $exception->getMessage());

            $this->recordQueueProgress(100.0);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Training job failed in queue', [
            'training_run_id' => $this->trainingRunId,
            'exception' => $exception->getMessage(),
        ]);
    }

    private function recordQueueProgress(float $progress): void
    {
        if (! property_exists($this, 'job')) {
            return;
        }

        $job = $this->job;

        if (! $job instanceof QueueJobContract) {
            return;
        }

        if (method_exists($job, 'supportsProgressTracking') && ! $job->supportsProgressTracking()) {
            return;
        }

        if (method_exists($job, 'setProgress')) {
            $normalized = (int) round($progress);
            $normalized = max(0, min(100, $normalized));

            $job->setProgress($normalized);
        }
    }
}
