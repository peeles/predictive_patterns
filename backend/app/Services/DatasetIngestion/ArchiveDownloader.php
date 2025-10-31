<?php

declare(strict_types=1);

namespace App\Services\DatasetIngestion;

use App\DataTransferObjects\DownloadArchive;
use App\Exceptions\DatasetRecordIngestionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Service for downloading and verifying dataset archives.
 *
 * Handles resumable downloads, checksum verification, and archive integrity checks.
 */
class ArchiveDownloader
{
    private const ARCHIVE_URL = 'https://data.dataset.uk/data/archive/%s.zip';

    private readonly string $tempDirectory;

    public function __construct(
        ?string $tempDirectory = null,
    ) {
        $this->tempDirectory = $tempDirectory ?? (string) (config('dataset_records.ingestion.temp_directory') ?? storage_path('app/dataset-record-ingestion'));
    }

    /**
     * Download a dataset archive for the given month.
     *
     * @param string $yearMonth Year-month in YYYY-MM format
     * @param string $url Download URL
     *
     * @return DownloadArchive Downloaded archive information
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws DatasetRecordIngestionException
     * @throws RuntimeException
     */
    public function download(string $yearMonth, string $url): DownloadArchive
    {
        $directory = $this->prepareTempDirectory();
        $partialPath = $directory.DIRECTORY_SEPARATOR.$this->sanitiseMonth($yearMonth).'.zip.part';

        try {
            $metadata = $this->fetchMetadata($url);
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 404) {
                throw $this->missingArchiveException($yearMonth, $exception);
            }

            throw $exception;
        }

        $supportsRanges = $metadata['accept_ranges'] ?? false;
        $expectedSize = $metadata['content_length'] ?? null;
        $expectedSha = $metadata['sha256'] ?? null;
        $expectedMd5 = $metadata['md5'] ?? null;

        $existingBytes = file_exists($partialPath) ? (int) filesize($partialPath) : 0;
        if (! $supportsRanges && $existingBytes > 0) {
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

            return $this->download($yearMonth, $url);
        }

        if (! in_array($response->status(), [200, 206], true)) {
            throw new RuntimeException(sprintf('Unable to download dataset archive (%d): %s', $response->status(), $url));
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
            throw new RuntimeException('Unable to open temporary file for dataset archive');
        }

        while (! $stream->eof()) {
            $chunk = $stream->read(1024 * 1024);
            if ($chunk === '' || $chunk === false) {
                break;
            }

            if (fwrite($handle, $chunk) === false) {
                fclose($handle);
                throw new RuntimeException('Unable to write dataset archive to temporary file');
            }
        }

        fclose($handle);

        $downloadedSize = (int) (filesize($partialPath) ?: 0);
        if ($expectedSize !== null && $downloadedSize < $expectedSize) {
            throw new RuntimeException(sprintf('Download incomplete (%d/%d bytes): %s', $downloadedSize, $expectedSize, $url));
        }

        $sha256 = hash_file('sha256', $partialPath);
        $expectedSha ??= $this->normaliseChecksum($response, 'x-amz-meta-sha256')
            ?? $this->normaliseChecksum($response, 'x-checksum-sha256');

        if ($expectedSha !== null && ! hash_equals($expectedSha, $sha256)) {
            $this->safeUnlink($partialPath);
            throw new RuntimeException('Downloaded archive checksum mismatch (sha256)');
        }

        if ($expectedSha === null) {
            $expectedMd5 ??= $response->header('Content-MD5');
            if ($expectedMd5 !== null) {
                $calculatedMd5 = base64_encode(md5_file($partialPath, true));
                if (! hash_equals($expectedMd5, $calculatedMd5)) {
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

        $finalPath = tempnam($directory, 'dataset_'.$this->sanitiseMonth($yearMonth).'_');
        if ($finalPath === false) {
            throw new RuntimeException('Unable to allocate final dataset archive path');
        }

        if (! rename($partialPath, $finalPath)) {
            if (! copy($partialPath, $finalPath)) {
                throw new RuntimeException('Unable to finalise dataset archive download');
            }
            $this->safeUnlink($partialPath);
        }

        $this->registerCleanup($finalPath);

        return new DownloadArchive($finalPath, $downloadedSize, $sha256, $url);
    }

    /**
     * Fetch metadata about the archive without downloading it.
     *
     * @param string $url Archive URL
     *
     * @return array{accept_ranges: bool, content_length: int|null, sha256: string|null, md5: string|null}
     *
     * @throws ConnectionException
     */
    private function fetchMetadata(string $url): array
    {
        $response = Http::timeout(30)->retry(2, 500)->withHeaders([
            'User-Agent' => 'PredictivePatternsBot/1.0',
            'Accept' => 'application/zip',
        ])->head($url);

        if (! $response->successful()) {
            return [
                'accept_ranges' => false,
                'content_length' => null,
                'sha256' => null,
                'md5' => null,
            ];
        }

        $acceptRanges = strtolower((string) $response->header('Accept-Ranges')) === 'bytes';
        $contentLength = $response->header('Content-Length');
        $sha256 = $this->normaliseChecksum($response, 'x-amz-meta-sha256')
            ?? $this->normaliseChecksum($response, 'x-checksum-sha256');
        $md5 = $response->header('Content-MD5');

        return [
            'accept_ranges' => $acceptRanges,
            'content_length' => $contentLength !== null ? (int) $contentLength : null,
            'sha256' => $sha256,
            'md5' => $md5,
        ];
    }

    /**
     * Normalise a checksum header value.
     */
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

    /**
     * Prepare the temporary directory for downloads.
     */
    private function prepareTempDirectory(): string
    {
        if (! is_dir($this->tempDirectory)) {
            if (! mkdir($concurrentDirectory = $this->tempDirectory, 0755, true) && ! is_dir($concurrentDirectory)) {
                throw new RuntimeException('Unable to prepare directory for dataset archive downloads');
            }
        }

        return $this->tempDirectory;
    }

    /**
     * Sanitise a year-month string for use in filenames.
     */
    private function sanitiseMonth(string $yearMonth): string
    {
        return preg_replace('/[^0-9a-z\-]/i', '_', $yearMonth) ?? $yearMonth;
    }

    /**
     * Safely delete a file if it exists.
     */
    private function safeUnlink(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Register a shutdown function to clean up the downloaded file.
     */
    private function registerCleanup(string $path): void
    {
        $cleanupPath = $path;
        register_shutdown_function(static function () use ($cleanupPath): void {
            if (is_string($cleanupPath) && file_exists($cleanupPath)) {
                @unlink($cleanupPath);
            }
        });
    }

    /**
     * Create an exception for when an archive is not found.
     */
    private function missingArchiveException(string $yearMonth, ?Throwable $previous = null): DatasetRecordIngestionException
    {
        return new DatasetRecordIngestionException(
            sprintf('No dataset archive is available for %s yet.', $yearMonth),
            previous: $previous,
        );
    }

    /**
     * Clean up a downloaded archive file.
     */
    public function cleanup(?string $path): void
    {
        $this->safeUnlink($path);
    }
}
