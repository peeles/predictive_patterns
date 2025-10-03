<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ModelStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public readonly ?string $message;

    public function __construct(
        public readonly string $modelId,
        public readonly string $state,
        public readonly ?float $progress,
        public readonly string $updatedAt,
        ?string $message = null,
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
     * @return array{model_id: string, state: string, progress: float|null, updated_at: string, message: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'model_id' => $this->modelId,
            'state' => $this->state,
            'progress' => $this->progress,
            'updated_at' => $this->updatedAt,
            'message' => $this->message,
        ];
    }
}
