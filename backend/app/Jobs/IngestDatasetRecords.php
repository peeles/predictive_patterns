<?php

namespace App\Jobs;

use App\Jobs\Middleware\LogJobExecution;
use App\Services\DatasetRecordIngestionService;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class IngestDatasetRecords implements ShouldQueue, ShouldBeUnique
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;
    public int $maxExceptions = 3;
    public int $uniqueFor = 3600;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public string $yearMonth,
        public bool $dryRun = false
    ) {
    }

    public function uniqueId(): string
    {
        return "ingest-dataset-{$this->yearMonth}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LogJobExecution(),
            new RateLimited('jobs:ingest-remote-dataset')
        ];
    }

    /**
     * @param DatasetRecordIngestionService $service
     *
     * @throws Throwable
     */
    public function handle(DatasetRecordIngestionService $service): void
    {
        $service->ingest($this->yearMonth, $this->dryRun);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('IngestDatasetRecords job failed', [
            'month' => $this->yearMonth,
            'dry_run' => $this->dryRun,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
