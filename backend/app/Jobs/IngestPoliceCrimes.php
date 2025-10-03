<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\PoliceCrimeIngestionService;
use Illuminate\Support\Facades\Log;
use Throwable;

class IngestPoliceCrimes implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $yearMonth, public bool $dryRun = false) {}

    /**
     * @param PoliceCrimeIngestionService $service
     *
     * @throws Throwable
     */
    public function handle(PoliceCrimeIngestionService $service): void
    {
        $service->ingest($this->yearMonth, $this->dryRun);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('IngestPoliceCrimes job failed', [
            'month' => $this->yearMonth,
            'dry_run' => $this->dryRun,
            'error' => $exception->getMessage(),
        ]);
    }
}
