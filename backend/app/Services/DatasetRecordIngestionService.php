<?php

namespace App\Services;

use App\DataTransferObjects\DatasetRecordIngestionStats;
use App\DataTransferObjects\DownloadArchive;
use App\Enums\DatasetRecordIngestionStatus;
use App\Exceptions\DatasetRecordIngestionException;
use App\Models\DatasetRecordIngestionRun;
use App\Notifications\DatasetRecordIngestionFailed;
use App\Services\DatasetIngestion\ArchiveDownloader;
use App\Services\DatasetIngestion\CsvRecordParser;
use App\Services\DatasetIngestion\RecordBatchInserter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Coordinates downloading and importing dataset archives into the relational store.
 */
class DatasetRecordIngestionService
{
    private const ARCHIVE_URL = 'https://data.dataset.uk/data/archive/%s.zip';
    private const CHUNK_SIZE = 500;
    private readonly int $chunkSize;
    private readonly int $progressInterval;
    private readonly string $tempDirectory;

    public function __construct(
        private readonly H3IndexService $h3IndexService,
        private readonly ArchiveDownloader $archiveDownloader,
        private readonly CsvRecordParser $csvRecordParser,
        private readonly RecordBatchInserter $recordBatchInserter,
    ) {
        $config = (array) config('dataset_records.ingestion');
        $this->chunkSize = max(1, (int) ($config['chunk_size'] ?? 500));
        $this->progressInterval = max(0, (int) ($config['progress_interval'] ?? 5000));
        $this->tempDirectory = (string) ($config['temp_directory'] ?? storage_path('app/dataset-record-ingestion'));
    }

    /**
     * Download and ingest the archive for the supplied year-month string.
     *
     * @param string $yearMonth
     * @param bool $dryRun
     *
     * @return DatasetRecordIngestionRun Number of datasets inserted into the database
     */
    public function ingest(string $yearMonth, bool $dryRun = false): DatasetRecordIngestionRun
    {
        $normalisedMonth = $this->normaliseMonth($yearMonth);
        $url = sprintf(self::ARCHIVE_URL, $normalisedMonth);

        $run = DatasetRecordIngestionRun::query()->create([
            'month' => $normalisedMonth,
            'dry_run' => $dryRun,
            'status' => DatasetRecordIngestionStatus::Running,
            'started_at' => Carbon::now(),
            'archive_url' => $url,
        ]);

        Log::info('Starting dataset ingestion', [
            'month' => $normalisedMonth,
            'dry_run' => $dryRun,
            'run_id' => $run->id,
        ]);

        $archive = null;

        try {
            $archive = $this->archiveDownloader->download($normalisedMonth, $url);

            $run->forceFill([
                'archive_checksum' => $archive->checksum,
                'status' => DatasetRecordIngestionStatus::Running,
            ])->save();

            $stats = $this->importArchive($archive, $run, $dryRun);

            $run->forceFill([
                'status' => DatasetRecordIngestionStatus::Completed,
                'records_detected' => $stats->recordsDetected,
                'records_expected' => $stats->recordsExpected,
                'records_inserted' => $dryRun ? 0 : $stats->recordsExpected,
                'records_existing' => $stats->existingRecords(),
                'finished_at' => Carbon::now(),
            ])->save();

            Log::info('Completed dataset ingestion', [
                'month' => $normalisedMonth,
                'dry_run' => $dryRun,
                'run_id' => $run->id,
                'records_detected' => $stats->recordsDetected,
                'records_expected' => $stats->recordsExpected,
                'records_existing' => $stats->existingRecords(),
                'records_duplicates' => $stats->recordsDuplicates,
                'records_invalid' => $stats->recordsInvalid,
                'records_skipped' => $stats->skippedRecords(),
                'checksum' => $archive->checksum,
            ]);

            return $run->refresh();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => DatasetRecordIngestionStatus::Failed,
                'error_message' => $e->getMessage(),
                'finished_at' => Carbon::now(),
            ])->save();

            Log::error('Failed to ingest dataset records', [
                'month' => $normalisedMonth,
                'dry_run' => $dryRun,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $this->notifyFailure($run->refresh(), $e);

            if ($e instanceof DatasetRecordIngestionException) {
                throw $e;
            }

            throw new DatasetRecordIngestionException(
                sprintf('Dataset record ingestion for %s failed: %s', $normalisedMonth, $e->getMessage()),
                previous: $e,
            );
        } finally {
            $this->archiveDownloader->cleanup($archive?->path ?? null);
        }
    }

    private function importArchive(DownloadArchive $archive, DatasetRecordIngestionRun $run, bool $dryRun): DatasetRecordIngestionStats
    {
        $zip = new ZipArchive();
        if ($zip->open($archive->path) !== true) {
            throw new RuntimeException('Unable to open dataset archive: ' . $archive->path);
        }

        $toH3 = [$this->h3IndexService, 'toH3'];
        $detected = 0;
        $insertable = 0;
        $existing = 0;
        $duplicates = 0;
        $invalid = 0;
        $buffer = [];
        $seen = [];
        $nextProgressThreshold = $this->progressInterval;
        $ingestedAt = Carbon::now()->toDateTimeString();

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) {
                    continue;
                }

                $name = $stat['name'] ?? '';
                if (!str_ends_with(strtolower($name), '.csv')) {
                    continue;
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    Log::warning('Unable to read CSV from dataset archive', [
                        'file' => $name,
                        'run_id' => $run->id,
                        'month' => $run->month,
                    ]);
                    continue;
                }

                $headers = null;
                while (($row = fgetcsv($stream)) !== false) {
                    if ($headers === null) {
                        $headers = $this->csvRecordParser->normaliseHeaders($row);
                        continue;
                    }

                    $assoc = $this->csvRecordParser->combineRow($headers, $row);
                    if ($assoc === null) {
                        continue;
                    }

                    $record = $this->csvRecordParser->transformRow($assoc, $toH3, $seen, $duplicates, $invalid, $ingestedAt);
                    if ($record === null) {
                        continue;
                    }

                    $buffer[] = $record;

                    if (count($buffer) >= $this->chunkSize) {
                        [$processed, $expected, $existingCount] = $this->recordBatchInserter->flush($buffer, $dryRun);
                        $detected += $processed;
                        $insertable += $expected;
                        $existing += $existingCount;
                        $this->maybeLogProgress(
                            $run,
                            $dryRun,
                            $detected,
                            $insertable,
                            $existing,
                            $duplicates,
                            $invalid,
                            $nextProgressThreshold
                        );
                    }
                }

                fclose($stream);
            }
        } finally {
            $zip->close();
        }

        if ($buffer) {
            [$processed, $expected, $existingCount] = $this->recordBatchInserter->flush($buffer, $dryRun);
            $detected += $processed;
            $insertable += $expected;
            $existing += $existingCount;
            $this->maybeLogProgress(
                $run,
                $dryRun,
                $detected,
                $insertable,
                $existing,
                $duplicates,
                $invalid,
                $nextProgressThreshold,
                true
            );
        }

        return new DatasetRecordIngestionStats($detected, $insertable, $existing, $duplicates, $invalid);
    }

    private function normaliseMonth(string $yearMonth): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            throw new DatasetRecordIngestionException('The supplied month must be in YYYY-MM format.');
        }

        $date = Carbon::createFromFormat('Y-m', $yearMonth);
        if ($date === false) {
            throw new DatasetRecordIngestionException('Unable to parse the supplied month value.');
        }

        return $date->format('Y-m');
    }

    private function notifyFailure(DatasetRecordIngestionRun $run, Throwable $exception): void
    {
        $mailRecipients = array_filter((array)(config('dataset_records.ingestion.notifications.mail') ?? []));
        $slackWebhook = config('dataset_records.ingestion.notifications.slack_webhook');

        if (empty($mailRecipients) && !$slackWebhook) {
            return;
        }

        if ($mailRecipients) {
            foreach ($mailRecipients as $recipient) {
                try {
                    Notification::route('mail', $recipient)->notify(
                        new DatasetRecordIngestionFailed($run, $exception, ['mail'])
                    );
                } catch (Throwable $notificationError) {
                    Log::warning('Unable to send dataset ingestion failure mail notification', [
                        'run_id' => $run->id,
                        'recipient' => $recipient,
                        'error' => $notificationError->getMessage(),
                    ]);
                }
            }
        }

        if ($slackWebhook) {
            try {
                Notification::route('slack', $slackWebhook)->notify(
                    new DatasetRecordIngestionFailed($run, $exception, ['slack'])
                );
            } catch (Throwable $notificationError) {
                Log::warning('Unable to send dataset ingestion failure Slack notification', [
                    'run_id' => $run->id,
                    'webhook' => $slackWebhook,
                    'error' => $notificationError->getMessage(),
                ]);
            }
        }
    }

    private function maybeLogProgress(
        DatasetRecordIngestionRun $run,
        bool $dryRun,
        int $detected,
        int $expected,
        int $existing,
        int $duplicates,
        int $invalid,
        int &$nextThreshold,
        bool $force = false
    ): void {
        if ($this->progressInterval === 0) {
            return;
        }

        if (!$force && $detected < $nextThreshold) {
            return;
        }

        Log::info('Dataset ingestion progress', [
            'run_id' => $run->id,
            'month' => $run->month,
            'dry_run' => $dryRun,
            'records_detected' => $detected,
            'records_expected' => $expected,
            'records_inserted' => $dryRun ? 0 : $expected,
            'records_existing' => $existing,
            'records_duplicates' => $duplicates,
            'records_invalid' => $invalid,
            'records_skipped' => $duplicates + $invalid,
        ]);

        while ($detected >= $nextThreshold) {
            $nextThreshold += $this->progressInterval;
        }

    }
}
