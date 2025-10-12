<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ModelStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $connection = 'broadcasts';
    public string $queue = 'broadcasts';
    public int $tries = 3;
    public int $timeout = 30;

    public readonly ?string $message;

    /**
     * @param array<string, mixed>|null $trainingMetrics
     */
    public function __construct(
        public readonly string $modelId,
        public readonly string $state,
        public readonly ?float $progress,
        public readonly string $updatedAt,
        ?string $message = null,
        public readonly ?string $status = null,
        public readonly ?array $trainingMetrics = null,
        public readonly ?string $errorMessage = null,
    ) {
        $this->message = $message;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(sprintf('models.%s.status', $this->modelId));
    }

    public function broadcastAs(): string
    {
        return 'ModelStatusUpdated';
    }

    /**
     * @return array{
     *     model_id: string,
     *     state: string,
     *     status: string,
     *     progress: float|null,
     *     updated_at: string,
     *     message: string|null,
     *     training_metrics: array<string, mixed>|null,
     *     error_message: string|null
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'model_id' => $this->modelId,
            'state' => $this->state,
            'status' => $this->status ?? $this->state,
            'progress' => $this->progress,
            'updated_at' => $this->updatedAt,
            'message' => $this->message,
            'training_metrics' => $this->trainingMetrics,
            'error_message' => $this->errorMessage,
        ];
    }
}
