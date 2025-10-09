<?php

namespace App\Listeners;

use App\Events\Datasets\DatasetIngestionCompleted;
use App\Events\Datasets\DatasetIngestionFailed;
use App\Events\Datasets\DatasetIngestionProgressed;
use App\Events\Datasets\DatasetIngestionStarted;
use App\Events\DatasetStatusUpdated;
use App\Support\Broadcasting\BroadcastDispatcher;

class BroadcastDatasetIngestionEvent
{
    public function handle(
        DatasetIngestionStarted|DatasetIngestionProgressed|DatasetIngestionCompleted|DatasetIngestionFailed $event
    ): void {
        $dataset = $event->dataset;
        $progress = null;
        $message = null;

        if ($event instanceof DatasetIngestionStarted) {
            $progress = $event->progress ?? 0.0;
            $message = $event->message;
        } elseif ($event instanceof DatasetIngestionProgressed) {
            $progress = $event->progress;
            $message = $event->message;
        } elseif ($event instanceof DatasetIngestionCompleted) {
            $progress = 1.0;
            $message = $event->message;
        } elseif ($event instanceof DatasetIngestionFailed) {
            $progress = 0.0;
            $message = $event->message;
        }

        $normalized = $this->normalizeProgress($progress);
        $statusEvent = DatasetStatusUpdated::fromDataset($dataset, $normalized, $message);

        BroadcastDispatcher::dispatch($statusEvent, [
            'dataset_id' => $statusEvent->datasetId,
            'status' => $statusEvent->status->value,
            'progress' => $statusEvent->progress,
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

        return max(0.0, min(1.0, round($progress, 4)));
    }
}
