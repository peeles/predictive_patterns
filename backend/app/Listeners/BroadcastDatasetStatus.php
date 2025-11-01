<?php

namespace App\Listeners;

use App\Events\DatasetStatusChanged;
use App\Events\DatasetStatusUpdated;
use App\Support\Broadcasting\BroadcastDispatcher;

class BroadcastDatasetStatus
{
    public function handle(DatasetStatusChanged $event): void
    {
        $normalized = $this->normaliseProgress($event->progress);
        $broadcastEvent = DatasetStatusUpdated::fromStatusChange($event, $normalized);

        BroadcastDispatcher::dispatch($broadcastEvent, [
            'dataset_id' => $broadcastEvent->datasetId,
            'status' => $broadcastEvent->status->value,
            'progress' => $broadcastEvent->progress,
        ]);
    }

    private function normaliseProgress(?float $progress): ?float
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
