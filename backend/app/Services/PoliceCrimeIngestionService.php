<?php

namespace App\Services;

use App\DataTransferObjects\CrimeIngestionStats;
use App\DataTransferObjects\DownloadArchive;
use App\Enums\CrimeIngestionStatus;
use App\Exceptions\PoliceCrimeIngestionException;
use App\Models\Crime;
use App\Models\CrimeIngestionRun;
use App\Notifications\CrimeIngestionFailed;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Coordinates downloading and importing crime archives into the relational store.
 */
class PoliceCrimeIngestionService
{
    private const ARCHIVE_URL = 'https://data.police.uk/data/archive/%s.zip';
    private const CHUNK_SIZE = 500;
    private readonly int $chunkSize;
    private readonly int $progressInterval;
    private readonly string $tempDirectory;

    public function __construct(
        private readonly H3IndexService $h3IndexService,
        private readonly H3AggregationService $h3AggregationService,
    )
    {
        $config = (array)config('crime.ingestion');
        $this->chunkSize = max(1, (int)($config['chunk_size'] ?? 500));
        $this->progressInterval = max(0, (int)($config['progress_interval'] ?? 5000));
        $this->tempDirectory = (string)($config['temp_directory'] ?? storage_path('app/crime-ingestion'));
    }

    /**
     * Download and ingest the archive for the supplied year-month string.
     *
     * @param string $yearMonth
     * @param bool $dryRun
     *
     * @return CrimeIngestionRun Number of crimes inserted into the database
     */
    public function ingest(string $yearMonth, bool $dryRun = false): CrimeIngestionRun
    {
        $normalisedMonth = $this->normaliseMonth($yearMonth);
        $url = sprintf(self::ARCHIVE_URL, $normalisedMonth);

        $run = CrimeIngestionRun::query()->create([
            'month' => $normalisedMonth,
            'dry_run' => $dryRun,
            'status' => CrimeIngestionStatus::Running,
            'started_at' => Carbon::now(),
            'archive_url' => $url,
        ]);

        Log::info('Starting police crime ingestion', [
            'month' => $normalisedMonth,
            'dry_run' => $dryRun,
            'run_id' => $run->id,
        ]);

        $archive = null;

        try {
            $archive = $this->downloadArchive($normalisedMonth, $url);

            $run->forceFill([
                'archive_checksum' => $archive->checksum,
                'status' => CrimeIngestionStatus::Running,
            ])->save();

            $stats = $this->importArchive($archive, $run, $dryRun);

            $run->forceFill([
                'status' => CrimeIngestionStatus::Completed,
                'records_detected' => $stats->recordsDetected,
                'records_expected' => $stats->recordsExpected,
                'records_inserted' => $dryRun ? 0 : $stats->recordsExpected,
                'records_existing' => $stats->existingRecords(),
                'finished_at' => Carbon::now(),
            ])->save();

            Log::info('Completed police crime ingestion', [
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
                'status' => CrimeIngestionStatus::Failed,
                'error_message' => $e->getMessage(),
                'finished_at' => Carbon::now(),
            ])->save();

            Log::error('Failed to ingest police crimes', [
                'month' => $normalisedMonth,
                'dry_run' => $dryRun,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $this->notifyFailure($run->refresh(), $e);

            if ($e instanceof PoliceCrimeIngestionException) {
                throw $e;
            }

            throw new PoliceCrimeIngestionException(
                sprintf('Crime ingestion for %s failed: %s', $normalisedMonth, $e->getMessage()),
                previous: $e,
            );
        } finally {
            $this->cleanupArchive($archive?->path ?? null);
        }
    }

    /**
     * Fetch a crime archive for the given month and persist it to a temp file.
     *
     * @param string $yearMonth
     * @param string $url
     *
     * @return DownloadArchive Absolute path to the downloaded archive
     * @throws ConnectionException
     * @throws RequestException
     */
    private function downloadArchive(string $yearMonth, string $url): DownloadArchive
    {
        $directory = $this->prepareTempDirectory();
        $partialPath = $directory . DIRECTORY_SEPARATOR . $this->sanitiseMonth($yearMonth) . '.zip.part';

        try {
            $metadata = $this->fetchArchiveMetadata($url);
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 404) {
                throw $this->missingArchiveException($yearMonth, $exception);
            }

            throw $exception;
        }

        $metadata = $this->fetchArchiveMetadata($url);
        $supportsRanges = $metadata['accept_ranges'] ?? false;
        $expectedSize = $metadata['content_length'] ?? null;
        $expectedSha = $metadata['sha256'] ?? null;
        $expectedMd5 = $metadata['md5'] ?? null;

        $existingBytes = file_exists($partialPath) ? (int)filesize($partialPath) : 0;
        if (!$supportsRanges && $existingBytes > 0) {
            $this->safeUnlink($partialPath);
            $existingBytes = 0;
        }

        if ($expectedSize !== null && $existingBytes > $expectedSize) {
            $this->safeUnlink($partialPath);
            $existingBytes = 0;
        }

        $headers = [
            'User-Agent' => 'PredictivePatternsBot/1.0',
            'Accept' => 'application/zip',
        ];

        if ($supportsRanges && $existingBytes > 0) {
            $headers['Range'] = sprintf('bytes=%d-', $existingBytes);
        }

        $response = Http::timeout(120)
            ->retry(3, 1500)
            ->withOptions(['stream' => true])
            ->withHeaders($headers)
            ->get($url);

        if ($response->status() === 404) {
            throw $this->missingArchiveException($yearMonth);
        }

        if ($response->status() === 416) {
            $this->safeUnlink($partialPath);

            return $this->downloadArchive($yearMonth, $url);
        }

        if (!in_array($response->status(), [200, 206], true)) {
            throw new RuntimeException(sprintf('Unable to download police archive (%d): %s', $response->status(), $url));
        }

        $status = $response->status();
        if (isset($headers['Range']) && $status === 200) {
            // Server ignored the range header; restart download.
            $existingBytes = 0;
            $this->safeUnlink($partialPath);
        }

        $stream = $response->toPsrResponse()->getBody();

        $handle = fopen($partialPath, $existingBytes > 0 ? 'ab' : 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open temporary file for police archive');
        }

        while (!$stream->eof()) {
            $chunk = $stream->read(1024 * 1024);
            if ($chunk === '' || $chunk === false) {
                break;
            }

            if (fwrite($handle, $chunk) === false) {
                fclose($handle);
                throw new RuntimeException('Unable to write police archive to temporary file');
            }
        }

        fclose($handle);

        $downloadedSize = (int)(filesize($partialPath) ?: 0);
        if ($expectedSize !== null && $downloadedSize < $expectedSize) {
            throw new RuntimeException(sprintf('Download incomplete (%d/%d bytes): %s', $downloadedSize, $expectedSize, $url));
        }

        $sha256 = hash_file('sha256', $partialPath);
        $expectedSha ??= $this->normaliseChecksum($response, 'x-amz-meta-sha256')
            ?? $this->normaliseChecksum($response, 'x-checksum-sha256');

        if ($expectedSha !== null && !hash_equals($expectedSha, $sha256)) {
            $this->safeUnlink($partialPath);
            throw new RuntimeException('Downloaded archive checksum mismatch (sha256)');
        }

        if ($expectedSha === null) {
            $expectedMd5 ??= $response->header('Content-MD5');
            if ($expectedMd5 !== null) {
                $calculatedMd5 = base64_encode(md5_file($partialPath, true));
                if (!hash_equals($expectedMd5, $calculatedMd5)) {
                    $this->safeUnlink($partialPath);
                    throw new RuntimeException('Downloaded archive checksum mismatch (md5)');
                }
            }
        }

        $zip = new ZipArchive();
        $zipOpen = $zip->open($partialPath, ZipArchive::CHECKCONS);
        if ($zipOpen !== true) {
            $this->safeUnlink($partialPath);
            $zip->close();
            throw new RuntimeException('Downloaded archive failed integrity check');
        }
        $zip->close();

        $finalPath = tempnam($directory, 'police_' . $this->sanitiseMonth($yearMonth) . '_');
        if ($finalPath === false) {
            throw new RuntimeException('Unable to allocate final police archive path');
        }

        if (!rename($partialPath, $finalPath)) {
            if (!copy($partialPath, $finalPath)) {
                throw new RuntimeException('Unable to finalise police archive download');
            }
            $this->safeUnlink($partialPath);
        }

        $this->registerCleanup($finalPath);

        return new DownloadArchive($finalPath, $downloadedSize, $sha256, $url);
    }

    private function importArchive(DownloadArchive $archive, CrimeIngestionRun $run, bool $dryRun): CrimeIngestionStats
    {
        $zip = new ZipArchive();
        if ($zip->open($archive->path) !== true) {
            throw new RuntimeException('Unable to open police archive: ' . $archive->path);
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
                    Log::warning('Unable to read CSV from police archive', [
                        'file' => $name,
                        'run_id' => $run->id,
                        'month' => $run->month,
                    ]);
                    continue;
                }

                $headers = null;
                while (($row = fgetcsv($stream)) !== false) {
                    if ($headers === null) {
                        $headers = $this->normaliseHeaders($row);
                        continue;
                    }

                    $assoc = $this->combineRow($headers, $row);
                    if ($assoc === null) {
                        continue;
                    }

                    $record = $this->transformRow($assoc, $toH3, $seen, $duplicates, $invalid, $ingestedAt);
                    if ($record === null) {
                        continue;
                    }

                    $buffer[] = $record;

                    if (count($buffer) >= $this->chunkSize) {
                        [$processed, $expected, $existingCount] = $this->flushBuffer($buffer, $dryRun);
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
            [$processed, $expected, $existingCount] = $this->flushBuffer($buffer, $dryRun);
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

        return new CrimeIngestionStats($detected, $insertable, $existing, $duplicates, $invalid);
    }

    /**
     * Combine a CSV row with its headers, trimming both keys and values.
     *
     * @param array<int, string> $headers
     * @param array<int, string|null> $row
     * @return array<string, mixed>|null
     */
    private function combineRow(array $headers, array $row): ?array
    {
        $headerCount = count($headers);
        if ($headerCount === 0) {
            return null;
        }

        $rowCount = count($row);
        if ($rowCount < $headerCount) {
            $row = array_pad($row, $headerCount, null);
        } elseif ($rowCount > $headerCount) {
            $row = array_slice($row, 0, $headerCount);
        }

        $assoc = [];
        foreach ($headers as $index => $header) {
            $header = trim((string) $header);
            if ($header === '') {
                continue;
            }

            $value = $row[$index] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            $assoc[$header] = $value;
        }

        return $assoc === [] ? null : $assoc;
    }

    /**
     * Transform a CSV row into an insertable payload for the crimes table.
     *
     * @param array<string, mixed> $row
     * @param callable $toH3
     * @param array<string, bool> $seen
     * @param int $duplicateCount
     * @param int $invalidCount
     * @param string $ingestedAt
     *
     * @return array|null
     */
    private function transformRow(
        array $row,
        callable $toH3,
        array &$seen,
        int &$duplicateCount,
        int &$invalidCount,
        string $ingestedAt
    ): ?array {
        $normalised = $this->normaliseRecordKeys($row);

        $month = $normalised['month'] ?? null;
        if ($month === null || $month === '') {
            $invalidCount++;
            return null;
        }

        $occurredAt = Carbon::createFromFormat('Y-m', (string) $month);
        if ($occurredAt === false) {
            $invalidCount++;
            return null;
        }
        $occurredAt = $occurredAt->startOfMonth();

        $lat = $this->parseCoordinate($normalised['latitude'] ?? $normalised['lat'] ?? null);
        $lng = $this->parseCoordinate($normalised['longitude'] ?? $normalised['lng'] ?? null);

        if ($lat === null || $lng === null) {
            $invalidCount++;
            return null;
        }

        $category = (string) ($normalised['crime_type'] ?? $normalised['category'] ?? '');
        $category = $category !== '' ? $category : 'unknown';

        $identifier = trim((string) ($normalised['crime_id'] ?? $normalised['id'] ?? ''));
        if ($identifier === '') {
            $location = (string) ($normalised['location'] ?? '');
            $identifier = $this->deterministicUuid(
                implode('|', [
                    $occurredAt->format('Y-m'),
                    $category,
                    $lat,
                    $lng,
                    $location,
                ])
            );
        }

        if (isset($seen[$identifier])) {
            $duplicateCount++;
            return null;
        }

        $seen[$identifier] = true;

        $raw = $this->encodeRaw($row);

        return [
            'id' => $identifier,
            'category' => $category,
            'occurred_at' => $occurredAt->toDateTimeString(),
            'lat' => $lat,
            'lng' => $lng,
            'h3_res6' => call_user_func($toH3, $lat, $lng, 6),
            'h3_res7' => call_user_func($toH3, $lat, $lng, 7),
            'h3_res8' => call_user_func($toH3, $lat, $lng, 8),
            'raw' => $raw,
            'created_at' => $ingestedAt,
            'updated_at' => $ingestedAt,
        ];
    }

    /**
     * Normalise the supplied row keys for easier lookups.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseRecordKeys(array $row): array
    {
        $normalised = [];

        foreach ($row as $key => $value) {
            $key = strtolower((string) $key);
            $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
            $key = trim($key, '_');

            if ($key === '') {
                continue;
            }

            $normalised[$key] = $value;
        }

        return $normalised;
    }

    private function deterministicUuid(string $seed): string
    {
        $hash = substr(hash('sha1', $seed), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function encodeRaw(array $row): string
    {
        $encoded = json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * Trim and normalise the CSV header row from the police archive.
     *
     * @param array<int, string|null> $headers
     * @return array<int, string>
     */
    private function normaliseHeaders(array $headers): array
    {
        return array_map(static function (?string $value) {
            $value = $value ?? '';
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value ?? '');
            return trim($value);
        }, $headers);
    }


    /**
     * Normalise latitude/longitude values, discarding invalid values.
     *
     * @param mixed $value
     * @return float|null
     */
    private function parseCoordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round((float)$value, 6);
    }

    /**
     * Insert the accumulated crime rows, skipping any that already exist.
     *
     * @param array<int, array<string, mixed>> $buffer
     * @return array{0:int,1:int,2:int} Array containing processed rows, expected insertions, and existing records
     */
    private function flushBuffer(array &$buffer, bool $dryRun): array
    {
        $processed = count($buffer);
        if ($processed === 0) {
            return [0, 0, 0];
        }

        $ids = array_column($buffer, 'id');
        $existing = Crime::query()->whereIn('id', $ids)->pluck('id')->all();

        if ($existing) {
            $existing = array_flip($existing);
            $buffer = array_values(array_filter($buffer, static fn(array $row): bool => !isset($existing[$row['id']])));
        }

        $insertable = count($buffer);
        $existingCount = $processed - $insertable;

        if (!$dryRun && $insertable > 0) {
            Crime::query()->insert($buffer);
            $this->h3AggregationService->bumpCacheVersion();
        }

        $buffer = [];

        return [$processed, $insertable, $existingCount];
    }

    private function prepareTempDirectory(): string
    {
        if (!is_dir($this->tempDirectory)) {
            if (!mkdir($concurrentDirectory = $this->tempDirectory, 0755, true) && !is_dir($concurrentDirectory)) {
                throw new RuntimeException('Unable to prepare directory for police archive downloads');
            }
        }

        return $this->tempDirectory;
    }

    private function sanitiseMonth(string $yearMonth): string
    {
        return preg_replace('/[^0-9a-z\-]/i', '_', $yearMonth) ?? $yearMonth;
    }

    private function normaliseMonth(string $yearMonth): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            throw new PoliceCrimeIngestionException('The supplied month must be in YYYY-MM format.');
        }

        $date = Carbon::createFromFormat('Y-m', $yearMonth);
        if ($date === false) {
            throw new PoliceCrimeIngestionException('Unable to parse the supplied month value.');
        }

        return $date->format('Y-m');
    }

    private function safeUnlink(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    private function cleanupArchive(?string $path): void
    {
        $this->safeUnlink($path);
    }

    private function registerCleanup(string $path): void
    {
        $cleanupPath = $path;
        register_shutdown_function(static function () use ($cleanupPath): void {
            if (is_string($cleanupPath) && file_exists($cleanupPath)) {
                @unlink($cleanupPath);
            }
        });
    }

    private function missingArchiveException(string $yearMonth, ?Throwable $previous = null): PoliceCrimeIngestionException
    {
        return new PoliceCrimeIngestionException(
            sprintf('No police crime archive is available for %s yet.', $yearMonth),
            previous: $previous,
        );
    }

    /**
     *
     * Fetch metadata about the archive without downloading it.
     *
     * @param string $url
     *
     * @return array{accept_ranges: bool, content_length: int|null, sha256: string|null, md5: string|null}
     * @throws ConnectionException
     */
    private function fetchArchiveMetadata(string $url): array
    {
        $response = Http::timeout(30)->retry(2, 500)->withHeaders([
            'User-Agent' => 'PredictivePatternsBot/1.0',
            'Accept' => 'application/zip',
        ])->head($url);

        if (!$response->successful()) {
            return [
                'accept_ranges' => false,
                'content_length' => null,
                'sha256' => null,
                'md5' => null,
            ];
        }

        $acceptRanges = strtolower((string)$response->header('Accept-Ranges')) === 'bytes';
        $contentLength = $response->header('Content-Length');
        $sha256 = $this->normaliseChecksum($response, 'x-amz-meta-sha256')
            ?? $this->normaliseChecksum($response, 'x-checksum-sha256');
        $md5 = $response->header('Content-MD5');

        return [
            'accept_ranges' => $acceptRanges,
            'content_length' => $contentLength !== null ? (int)$contentLength : null,
            'sha256' => $sha256,
            'md5' => $md5,
        ];
    }

    private function normaliseChecksum(Response $response, string $header): ?string
    {
        $value = $response->header($header);
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[0-9a-f]{64}$/i', $value)) {
            return strtolower($value);
        }

        $decoded = base64_decode($value, true);
        if ($decoded !== false) {
            return strtolower(bin2hex($decoded));
        }

        return null;
    }

    private function notifyFailure(CrimeIngestionRun $run, Throwable $exception): void
    {
        $mailRecipients = array_filter((array)(config('crime.ingestion.notifications.mail') ?? []));
        $slackWebhook = config('crime.ingestion.notifications.slack_webhook');

        if (empty($mailRecipients) && !$slackWebhook) {
            return;
        }

        if ($mailRecipients) {
            foreach ($mailRecipients as $recipient) {
                try {
                    Notification::route('mail', $recipient)->notify(
                        new CrimeIngestionFailed($run, $exception, ['mail'])
                    );
                } catch (Throwable $notificationError) {
                    Log::warning('Unable to send crime ingestion failure mail notification', [
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
                    new CrimeIngestionFailed($run, $exception, ['slack'])
                );
            } catch (Throwable $notificationError) {
                Log::warning('Unable to send crime ingestion failure Slack notification', [
                    'run_id' => $run->id,
                    'webhook' => $slackWebhook,
                    'error' => $notificationError->getMessage(),
                ]);
            }
        }
    }

    private function maybeLogProgress(
        CrimeIngestionRun $run,
        bool $dryRun,
        int $detected,
        int $expected,
        int $existing,
        int $duplicates,
        int $invalid,
        int &$nextThreshold,
        bool $force = false
    ): void
    {
        if ($this->progressInterval === 0) {
            return;
        }

        if (!$force && $detected < $nextThreshold) {
            return;
        }

        Log::info('Police crime ingestion progress', [
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
