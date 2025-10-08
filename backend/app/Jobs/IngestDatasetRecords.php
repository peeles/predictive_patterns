<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\DatasetRecordIngestionService;
use Illuminate\Support\Facades\Log;
use Throwable;

class IngestDatasetRecords implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $yearMonth, public bool $dryRun = false)
    {
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
        ]);
    }
}
