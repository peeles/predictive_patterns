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
use Illuminate\Support\Arr;
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
        $this->onConnection('training');
        $this->onQueue(config('queue.connections.training.queue', 'training'));
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
        $run = TrainingRun::query()->with('model')->findOrFail($this->trainingRunId);
        $model = $run->model;

        if ($model === null) {
            Log::warning('Training run without associated model', ['training_run_id' => $run->id]);
            return;
        }

        $this->progressRun = $run;
        $this->progressModel = $model;
        $this->progressEntityId = $model->id;
        $this->progressStage = 'training';

        $effectiveHyperparameters = $this->hyperparameters ?? $run->hyperparameters ?? [];

        $run->fill([
            'status' => TrainingStatus::Running,
            'started_at' => now(),
            'error_message' => null,
            'hyperparameters' => $effectiveHyperparameters,
        ])->save();

        $model->fill([
            'status' => ModelStatus::Training,
        ])->save();

        $this->notifyProgressStage($statusService, $model, 0);
        $this->notifyProgressStage($statusService, $model, 5);
        $this->notifyProgressStage($statusService, $model, 15);
        $this->notifyProgressStage($statusService, $model, 25);

        $totalEpochs = $this->resolveTotalEpochsFromHyperparameters($effectiveHyperparameters);
        $this->notifyProgressStage($statusService, $model, 35);
        $this->notifyProgressStage($statusService, $model, 45);

        try {
            $result = $trainingService->train(
                $run,
                $model,
                $effectiveHyperparameters,
                function (float $progress, ?string $message = null) use ($statusService, $model, $totalEpochs): void {
                    $normalised = (int) round($progress);
                    $resolvedMessage = $message ?? $this->defaultProgressMessage($normalised);
                    $epochMetrics = $this->buildEpochMetrics($normalised, $totalEpochs);

                    $statusService->markProgress($model->id, 'training', $progress, $resolvedMessage);
                    $this->updateProgress($normalised, $resolvedMessage, $epochMetrics);
                    $this->recordQueueProgress($progress);
                }
            );

            $this->notifyProgressStage(
                $statusService,
                $model,
                75,
                null,
                $this->buildEpochMetrics(75, $totalEpochs)
            );
            $this->notifyProgressStage(
                $statusService,
                $model,
                95,
                null,
                $this->buildEpochMetrics(95, $totalEpochs)
            );
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

            $finalEpochs = $this->resolveTotalEpochsFromHyperparameters($result['hyperparameters'] ?? $effectiveHyperparameters);
            $this->notifyProgressStage(
                $statusService,
                $model,
                100,
                null,
                $this->buildFinalMetricsForProgress($metrics, $finalEpochs)
            );

            $statusService->markIdle($model->id);
            $model->refresh();

            event(new ModelTrained($model));
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
            $this->updateProgress(100, $exception->getMessage());

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
            $normalised = (int) round($progress);
            $normalised = max(0, min(100, $normalised));

            $job->setProgress($normalised);
        }
    }

    private function notifyProgressStage(ModelStatusService $statusService, PredictiveModel $model, int $progress, ?string $message = null, ?array $metrics = null): void
    {
        $resolvedMessage = $message ?? $this->defaultProgressMessage($progress);

        $statusService->markProgress($model->id, 'training', (float) $progress, $resolvedMessage);
        $this->updateProgress($progress, $resolvedMessage, $metrics);
        $this->recordQueueProgress((float) $progress);
    }

    /**
     * @param array<string, mixed> $hyperparameters
     */
    private function resolveTotalEpochsFromHyperparameters(array $hyperparameters): ?int
    {
        foreach (['epochs', 'max_epochs', 'n_estimators', 'iterations'] as $key) {
            $value = Arr::get($hyperparameters, $key);

            if (is_numeric($value)) {
                $cast = (int) $value;

                if ($cast > 0) {
                    return $cast;
                }
            }
        }

        return null;
    }

    private function buildEpochMetrics(int $progress, ?int $totalEpochs): ?array
    {
        if ($totalEpochs === null || $totalEpochs <= 0 || $progress < 25) {
            return null;
        }

        if ($progress >= 100) {
            return [
                'current_epoch' => $totalEpochs,
                'total_epochs' => $totalEpochs,
                'loss' => null,
                'accuracy' => null,
            ];
        }

        $progressWindow = max(1, 70);
        $relative = max(0, min(1, ($progress - 25) / $progressWindow));
        $currentEpoch = (int) round($totalEpochs * $relative);

        if ($currentEpoch < 1) {
            $currentEpoch = 1;
        }

        if ($currentEpoch > $totalEpochs) {
            $currentEpoch = $totalEpochs;
        }

        return [
            'current_epoch' => $currentEpoch,
            'total_epochs' => $totalEpochs,
            'loss' => null,
            'accuracy' => null,
        ];
    }

    /**
     * @param array<string, mixed>|null $metrics
     */
    private function buildFinalMetricsForProgress(?array $metrics, ?int $totalEpochs): ?array
    {
        $loss = Arr::get($metrics, 'loss', Arr::get($metrics, 'best_loss'));
        $accuracy = Arr::get($metrics, 'accuracy', Arr::get($metrics, 'best_accuracy'));

        if ($loss === null && $accuracy === null && $totalEpochs === null) {
            return null;
        }

        return [
            'current_epoch' => $totalEpochs,
            'total_epochs' => $totalEpochs,
            'loss' => $loss,
            'accuracy' => $accuracy,
        ];
    }
}
