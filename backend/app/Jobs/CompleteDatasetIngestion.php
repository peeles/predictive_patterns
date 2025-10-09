<?php

namespace App\Jobs;

use App\Jobs\Middleware\LogJobExecution;
use App\Repositories\DatasetRepositoryInterface;
use App\Services\DatasetProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
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

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LogJobExecution(),
            new RateLimited('default'),
        ];
    }

    public function handle(DatasetProcessingService $processingService, DatasetRepositoryInterface $datasets): void
    {
        $dataset = $datasets->find($this->datasetId);

        if ($dataset === null) {
            return;
        }

        $processingService->finalise($dataset, $this->schemaMapping, $this->additionalMetadata);
    }

    public function failed(Throwable $exception): void
    {
        $dataset = app(DatasetRepositoryInterface::class)->find($this->datasetId);

        if ($dataset === null) {
            return;
        }

        app(DatasetProcessingService::class)->markAsFailed($dataset, $exception);
    }
}
