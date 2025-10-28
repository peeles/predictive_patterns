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
        public string $modelId,
        public string $status,
        public float $progress,
        ?string $message = null,
        public ?array $metrics = null,
        public ?string $errorMessage = null,
        public readonly ?array $trainingMetrics = null,
    ) {
        $this->message = $message;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("model.{$this->modelId}"),
            new PrivateChannel('models'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'status.updated';
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
            'status' => $this->status,
            'progress' => $this->progress,
            'message' => $this->message,
            'metrics' => $this->metrics,
            'error_message' => $this->errorMessage,
            'timestamp' => now()->toIso8601String(),
            'training_metrics' => $this->trainingMetrics,
        ];
    }
}
