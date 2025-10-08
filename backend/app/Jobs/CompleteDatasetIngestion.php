<?php

namespace App\Jobs;

use App\Models\Dataset;
use App\Services\DatasetProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

use function app;

class CompleteDatasetIngestion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, string> $schemaMapping
     * @param array<string, mixed> $additionalMetadata
     */
    public function __construct(
        public readonly string $datasetId,
        public readonly array $schemaMapping = [],
        public readonly array $additionalMetadata = [],
    ) {
    }

    public function handle(DatasetProcessingService $processingService): void
    {
        $dataset = Dataset::query()->find($this->datasetId);

        if ($dataset === null) {
            return;
        }

        $processingService->finalise($dataset, $this->schemaMapping, $this->additionalMetadata);
    }

    public function failed(Throwable $exception): void
    {
        $dataset = Dataset::query()->find($this->datasetId);

        if ($dataset === null) {
            return;
        }

        app(DatasetProcessingService::class)->markAsFailed($dataset, $exception);
    }
}
