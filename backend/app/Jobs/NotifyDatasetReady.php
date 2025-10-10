<?php

namespace App\Jobs;

use App\Events\DatasetStatusUpdated;
use App\Jobs\Middleware\LogJobExecution;
use App\Models\Dataset;
use App\Notifications\DatasetReadyNotification;
use App\Repositories\DatasetRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class NotifyDatasetReady implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public readonly string $datasetId)
    {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LogJobExecution(),
            new RateLimited('notifications'),
        ];
    }

    public function handle(DatasetRepositoryInterface $datasets): void
    {
        $dataset = $datasets->find($this->datasetId);

        if (! $dataset instanceof Dataset) {
            Log::warning('Dataset ready notification skipped, dataset not found', [
                'dataset_id' => $this->datasetId,
            ]);

            return;
        }

        $dataset->refresh();

        $this->notifyCreator($dataset);
        $this->broadcastStatus($dataset);
    }

    private function notifyCreator(Dataset $dataset): void
    {
        $creator = $dataset->creator;

        if ($creator === null) {
            return;
        }

        try {
            Notification::send($creator, new DatasetReadyNotification($dataset));
        } catch (Throwable $exception) {
            Log::warning('Failed to send dataset ready notification', [
                'dataset_id' => $dataset->getKey(),
                'user_id' => $creator->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function broadcastStatus(Dataset $dataset): void
    {
        try {
            event(DatasetStatusUpdated::fromDataset($dataset, 1.0));
        } catch (Throwable $exception) {
            Log::warning('Failed to broadcast dataset ready status', [
                'dataset_id' => $dataset->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
