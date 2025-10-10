<?php

namespace App\Jobs\Concerns;

use App\Events\ProgressUpdated;
use Illuminate\Support\Facades\Cache;

trait TracksProgress
{
    private array $lastBroadcasts = [];

    protected function updateProgress(
        string $entityId,
        string $stage,
        float $percent,
        ?string $message = null
    ): void {
        $normalisedPercent = max(0.0, min(100.0, round($percent, 2)));

        Cache::put(
            "progress.{$entityId}.{$stage}",
            [
                'percent' => $normalisedPercent,
                'message' => $message,
                'updated_at' => now()->toIso8601String(),
            ],
            600
        );

        if ($this->shouldBroadcastProgress($entityId, $normalisedPercent)) {
            broadcast(new ProgressUpdated($entityId, $stage, $normalisedPercent, $message));
        }
    }

    private function shouldBroadcastProgress(string $entityId, float $newPercent): bool
    {
        $last = $this->lastBroadcasts[$entityId] ?? null;

        if ($last === null || abs($newPercent - $last) >= 5.0) {
            $this->lastBroadcasts[$entityId] = $newPercent;
            return true;
        }

        return false;
    }
}
