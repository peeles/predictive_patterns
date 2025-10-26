<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProgressUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $connection = 'broadcasts';
    public string $queue = 'broadcasts';
    public int $tries = 3;

    public readonly string $updatedAt;

    /**
     * @param array{current_epoch: int|null, total_epochs: int|null, loss: float|null, accuracy: float|null}|null $metrics
     */
    public function __construct(
        public readonly string $entityId,
        public readonly string $stage,
        public readonly float $percent,
        public readonly ?string $message,
        public readonly ?array $metrics = null,
    ) {
        $this->updatedAt = now()->toIso8601String();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(sprintf('progress.%s.%s', $this->entityId, $this->stage));
    }

    public function broadcastAs(): string
    {
        return 'ProgressUpdated';
    }

    public function broadcastWith(): array
    {
        $metrics = [
            'current_epoch' => $this->metrics['current_epoch'] ?? null,
            'total_epochs' => $this->metrics['total_epochs'] ?? null,
            'loss' => $this->metrics['loss'] ?? null,
            'accuracy' => $this->metrics['accuracy'] ?? null,
        ];

        return [
            'entity_id' => $this->entityId,
            'stage' => $this->stage,
            'percent' => $this->percent,
            'message' => $this->message,
            'updated_at' => $this->updatedAt,
            'metrics' => $metrics,
        ];
    }
}
