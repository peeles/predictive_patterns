<?php

namespace App\Jobs\Concerns;

use App\Events\ProgressUpdated;
use Illuminate\Support\Facades\Cache;

trait TracksProgress
{
    protected function updateProgress(
        string $entityId,
        string $stage,
        float $percent,
        ?string $message = null
    ): void {
        $normalizedPercent = max(0.0, min(100.0, round($percent, 2)));

        Cache::put(
            "progress.{$entityId}.{$stage}",
            [
                'percent' => $normalizedPercent,
                'message' => $message,
                'updated_at' => now()->toIso8601String(),
            ],
            600 // 10 minutes
        );

        // Broadcast if significant change
        if ($this->shouldBroadcastProgress($normalizedPercent)) {
            broadcast(new ProgressUpdated($entityId, $stage, $normalizedPercent, $message));
        }
    }

    private function shouldBroadcastProgress(float $newPercent): bool
    {
        static $lastBroadcast = null;

        if ($lastBroadcast === null || abs($newPercent - $lastBroadcast) >= 5.0) {
            $lastBroadcast = $newPercent;
            return true;
        }

        return false;
    }
}
