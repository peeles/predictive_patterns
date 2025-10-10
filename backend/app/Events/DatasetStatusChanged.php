<?php

namespace App\Events;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class DatasetStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly string $datasetId,
        public readonly DatasetStatus $status,
        public readonly ?float $progress = null,
        public readonly ?string $message = null,
        public readonly ?array $metadata = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $ingestedAt = null,
    ) {
    }

    public static function fromDataset(
        Dataset $dataset,
        ?float $progress = null,
        ?string $message = null,
        ?array $metadata = null,
    ): self {
        $status = $dataset->status instanceof DatasetStatus
            ? $dataset->status
            : DatasetStatus::tryFrom((string) $dataset->status);

        if (! $status instanceof DatasetStatus) {
            $status = DatasetStatus::Processing;
        }

        $updatedAt = optional($dataset->updated_at)->toIso8601String() ?? now()->toIso8601String();
        $ingestedAt = optional($dataset->ingested_at)->toIso8601String();

        $metadata ??= is_array($dataset->metadata) ? $dataset->metadata : null;

        if ($message === null && $status === DatasetStatus::Failed && is_array($metadata)) {
            $message = Arr::get($metadata, 'ingest_error');
        }

        return new self(
            (string) $dataset->getKey(),
            $status,
            $progress,
            $message,
            $metadata,
            $updatedAt,
            $ingestedAt,
        );
    }
}
