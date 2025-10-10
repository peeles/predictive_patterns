<?php

namespace App\Events;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class DatasetStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $connection = 'broadcasts';
    public string $queue = 'broadcasts';
    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public readonly string $datasetId,
        public readonly DatasetStatus $status,
        public readonly ?float $progress,
        public readonly string $updatedAt,
        public readonly ?string $ingestedAt = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function fromStatusChange(DatasetStatusChanged $event, ?float $progress = null): self
    {
        $updatedAt = $event->updatedAt ?? now()->toIso8601String();

        return new self(
            $event->datasetId,
            $event->status,
            $progress ?? $event->progress,
            $updatedAt,
            $event->ingestedAt,
            $event->message,
        );
    }

    public static function fromDataset(Dataset $dataset, ?float $progress = null, ?string $message = null): self
    {
        $status = $dataset->status instanceof DatasetStatus
            ? $dataset->status
            : DatasetStatus::tryFrom((string) $dataset->status);

        if (! $status instanceof DatasetStatus) {
            $status = DatasetStatus::Processing;
        }

        $updatedAt = optional($dataset->updated_at)->toIso8601String() ?? now()->toIso8601String();
        $ingestedAt = optional($dataset->ingested_at)->toIso8601String();

        if ($message === null && $status === DatasetStatus::Failed) {
            $metadata = $dataset->metadata;
            if (is_array($metadata)) {
                $message = Arr::get($metadata, 'ingest_error');
            }
        }

        return new self(
            $dataset->getKey(),
            $status,
            $progress,
            $updatedAt,
            $ingestedAt,
            $message,
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(sprintf('datasets.%s.status', $this->datasetId));
    }

    public function broadcastAs(): string
    {
        return 'DatasetStatusUpdated';
    }

    /**
     * @return array{dataset_id: string, status: string, progress: float|null, updated_at: string, ingested_at: string|null, message: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'dataset_id' => $this->datasetId,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'updated_at' => $this->updatedAt,
            'ingested_at' => $this->ingestedAt,
            'message' => $this->message,
        ];
    }
}
