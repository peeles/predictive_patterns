<?php

namespace App\Console\Commands;

use App\Exceptions\PoliceCrimeIngestionException;
use App\Jobs\IngestPoliceCrimes;
use App\Services\PoliceCrimeIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CrimeIngest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crimes:ingest
        {months?* : One or more target months in YYYY-MM format}
        {--from= : Inclusive start month in YYYY-MM format}
        {--to= : Inclusive end month in YYYY-MM format}
        {--chunk=5 : Number of months to include in each queued batch}
        {--queue= : Queue name to dispatch jobs onto}
        {--dry-run : Process archives without inserting records}
        {--sync : Execute ingestion immediately instead of queuing jobs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch ingestion jobs for police crimes data for one or more months';

    /**
     * @throws Throwable
     */
    public function handle(PoliceCrimeIngestionService $service): int
    {
        try {
            $months = $this->resolveMonths();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (empty($months)) {
            $this->error('You must provide at least one month or a from/to range.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');

        if ($sync) {
            return $this->runSynchronously($service, $months, $dryRun);
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $queue = $this->option('queue');

        $this->info(sprintf('Queuing ingestion for %d month(s)%s', count($months), $dryRun ? ' (dry-run)' : ''));

        $chunks = array_chunk($months, $chunkSize);
        foreach ($chunks as $index => $chunk) {
            $jobs = array_map(fn(string $month) => new IngestPoliceCrimes($month, $dryRun), $chunk);

            $pendingBatch = Bus::batch($jobs)
                ->name(sprintf('crime-ingest-%s-%d', now()->format('YmdHis'), $index + 1))
                ->allowFailures()
                ->then(function (Batch $batch) use ($chunk, $dryRun): void {
                    Log::info('Crime ingestion batch completed', [
                        'batch_id' => $batch->id,
                        'months' => $chunk,
                        'dry_run' => $dryRun,
                        'failed_jobs' => $batch->failedJobs,
                    ]);
                })
                ->catch(function (Batch $batch, Throwable $exception) use ($chunk, $dryRun): void {
                    Log::error('Crime ingestion batch encountered an error', [
                        'batch_id' => $batch->id,
                        'months' => $chunk,
                        'dry_run' => $dryRun,
                        'error' => $exception->getMessage(),
                    ]);
                });

            if ($queue) {
                $pendingBatch->onQueue($queue);
            }

            $batch = $pendingBatch->dispatch();

            $this->info(sprintf(
                'Dispatched batch %s with %d job(s): %s',
                $batch->id,
                count($chunk),
                implode(', ', $chunk)
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function resolveMonths(): array
    {
        $rawMonths = Arr::wrap($this->argument('months'));
        $months = [];

        foreach ($rawMonths as $month) {
            if ($month === null || $month === '') {
                continue;
            }

            $months[] = $this->normalizeYearMonth((string) $month);
        }

        $from = $this->option('from');
        $to = $this->option('to');

        if ($from !== null || $to !== null) {
            if ($from === null || $to === null) {
                throw new InvalidArgumentException('Both --from and --to options must be supplied to use a date range.');
            }

            $rangeMonths = $this->expandRange($from, $to);
            $months = array_merge($months, $rangeMonths);
        }

        $months = array_unique($months);
        sort($months);

        return $months;
    }

    private function runSynchronously(PoliceCrimeIngestionService $service, array $months, bool $dryRun): int
    {
        $this->info(sprintf('Running ingestion synchronously for %d month(s)%s', count($months), $dryRun ? ' (dry-run)' : ''));

        foreach ($months as $month) {
            try {
                $run = $service->ingest($month, $dryRun);
                $this->info(sprintf(
                    'Ingestion complete for %s (run #%d, detected %d, expected %d, inserted %d)',
                    $month,
                    $run->id,
                    $run->records_detected,
                    $run->records_expected,
                    $run->records_inserted
                ));
            } catch (PoliceCrimeIngestionException $exception) {
                $this->error(sprintf('Ingestion failed for %s: %s', $month, $exception->getMessage()));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function expandRange(string $from, string $to): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $this->normalizeYearMonth($from));
        $end = CarbonImmutable::createFromFormat('Y-m', $this->normalizeYearMonth($to));

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('--from month must be earlier than or equal to --to month.');
        }

        $months = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    private function normalizeYearMonth(string $ym): string
    {
        if ($ym === '') {
            throw new InvalidArgumentException('Year-month values cannot be empty.');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            throw new InvalidArgumentException(sprintf('Invalid year-month value supplied: %s', $ym));
        }

        $date = Carbon::createFromFormat('Y-m', $ym);
        if ($date === false) {
            throw new InvalidArgumentException(sprintf('Unable to parse the provided year-month: %s', $ym));
        }

        return $date->format('Y-m');
    }
}
