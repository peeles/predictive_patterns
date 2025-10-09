<?php

namespace App\Events;

use App\Enums\PredictionStatus;
use App\Models\Prediction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PredictionStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $connection = 'broadcasts';
    public string $queue = 'broadcasts';
    public int $tries = 3;

    /**
     * @param float|null $progress Progress as a ratio between 0 and 1.
     */
    public function __construct(
        public readonly string $predictionId,
        public readonly string $status,
        public readonly ?float $progress,
        public readonly string $updatedAt,
        public readonly ?string $queuedAt = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $finishedAt = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function fromPrediction(Prediction $prediction, ?float $progress = null, ?string $message = null): self
    {
        $status = $prediction->status instanceof PredictionStatus
            ? $prediction->status->value
            : (string) $prediction->status;

        $normalizedProgress = self::normalizeProgress($progress);

        return new self(
            $prediction->getKey(),
            $status,
            $normalizedProgress,
            optional($prediction->updated_at)->toIso8601String() ?? now()->toIso8601String(),
            optional($prediction->queued_at)->toIso8601String(),
            optional($prediction->started_at)->toIso8601String(),
            optional($prediction->finished_at)->toIso8601String(),
            self::resolveMessage($message, $prediction),
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(sprintf('predictions.%s.status', $this->predictionId));
    }

    public function broadcastAs(): string
    {
        return 'PredictionStatusUpdated';
    }

    /**
     * @return array{
     *     prediction_id: string,
     *     status: string,
     *     progress: float|null,
     *     updated_at: string,
     *     queued_at: string|null,
     *     started_at: string|null,
     *     finished_at: string|null,
     *     message: string|null
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'prediction_id' => $this->predictionId,
            'status' => $this->status,
            'progress' => $this->progress,
            'updated_at' => $this->updatedAt,
            'queued_at' => $this->queuedAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'message' => $this->message,
        ];
    }

    private static function normalizeProgress(?float $progress): ?float
    {
        if ($progress === null) {
            return null;
        }

        if (is_nan($progress) || is_infinite($progress)) {
            return null;
        }

        return max(0.0, min(1.0, round($progress, 4)));
    }

    private static function resolveMessage(?string $message, Prediction $prediction): ?string
    {
        if ($message !== null) {
            $trimmed = trim($message);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        $error = $prediction->error_message;

        if (is_string($error) && trim($error) !== '') {
            return $error;
        }

        return null;
    }
}
