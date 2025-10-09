<?php

namespace App\Events;

use App\Enums\DatasetRecordIngestionStatus;
use App\Models\DatasetRecordIngestionRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DatasetIngestionRunUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $connection = 'broadcasts';
    public string $queue = 'broadcasts';
    public int $tries = 3;

    public function __construct(
        public readonly int $runId,
        public readonly string $status,
        public readonly ?float $progress,
        public readonly string $updatedAt,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly int $recordsDetected,
        public readonly int $recordsExpected,
        public readonly int $recordsInserted,
        public readonly int $recordsExisting,
        public readonly bool $dryRun,
        public readonly ?string $message = null,
    ) {
    }

    public static function fromRun(DatasetRecordIngestionRun $run, ?float $progress = null, ?string $message = null): self
    {
        $status = $run->status instanceof DatasetRecordIngestionStatus
            ? $run->status->value
            : (string) $run->status;

        return new self(
            (int) $run->getKey(),
            $status,
            self::normalizeProgress($progress),
            optional($run->updated_at)->toIso8601String() ?? now()->toIso8601String(),
            optional($run->started_at)->toIso8601String(),
            optional($run->finished_at)->toIso8601String(),
            (int) $run->records_detected,
            (int) $run->records_expected,
            (int) $run->records_inserted,
            (int) $run->records_existing,
            (bool) $run->dry_run,
            $message ?? (is_string($run->error_message) && $run->error_message !== '' ? $run->error_message : null),
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('dataset.ingestion.runs');
    }

    public function broadcastAs(): string
    {
        return 'DatasetIngestionRunUpdated';
    }

    /**
     * @return array{
     *     run_id: int,
     *     status: string,
     *     progress: float|null,
     *     updated_at: string,
     *     started_at: string|null,
     *     finished_at: string|null,
     *     records_detected: int,
     *     records_expected: int,
     *     records_inserted: int,
     *     records_existing: int,
     *     dry_run: bool,
     *     message: string|null
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'status' => $this->status,
            'progress' => $this->progress,
            'updated_at' => $this->updatedAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'records_detected' => $this->recordsDetected,
            'records_expected' => $this->recordsExpected,
            'records_inserted' => $this->recordsInserted,
            'records_existing' => $this->recordsExisting,
            'dry_run' => $this->dryRun,
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
}
