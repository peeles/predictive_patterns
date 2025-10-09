<?php

namespace App\Jobs;

use App\Events\Datasets\DatasetIngestionFailed;
use App\Events\Datasets\DatasetIngestionProgressed;
use App\Events\Datasets\DatasetIngestionStarted;
use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Services\DatasetProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function explode;
use function hash_file;
use function is_array;
use function is_string;
use function parse_url;
use function pathinfo;
use function sprintf;
use function strtolower;
use function trim;

use const PATHINFO_EXTENSION;
use const PHP_URL_PATH;

class IngestRemoteDataset implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $datasetId)
    {
    }

    /**
     * @throws Throwable
     * @throws ConnectionException
     */
    public function handle(DatasetProcessingService $processingService): void
    {
        $dataset = Dataset::query()->find($this->datasetId);

        if (! $dataset instanceof Dataset) {
            return;
        }

        if ($dataset->source_type !== 'url' || $dataset->source_uri === null) {
            return;
        }

        $dataset->status = DatasetStatus::Processing;
        $dataset->save();

        event(new DatasetIngestionStarted($dataset, 0.0));
        event(new DatasetIngestionProgressed($dataset, 0.0));

        $disk = Storage::disk('local');
        $path = null;
        $temporaryPath = null;

        try {
            if (! $disk->exists('datasets')) {
                $disk->makeDirectory('datasets');
            }

            $temporaryPath = sprintf('datasets/%s.tmp', Str::uuid());
            $absoluteTemporaryPath = $disk->path($temporaryPath);

            try {
                $response = Http::timeout(60)
                    ->retry(2, 1000)
                    ->sink($absoluteTemporaryPath)
                    ->get($dataset->source_uri);
            } catch (RequestException $exception) {
                throw new RuntimeException($this->formatDownloadError($exception), 0, $exception);
            }

            if (! $response->successful()) {
                throw new RuntimeException(sprintf('Unable to download dataset (HTTP %d).', $response->status()));
            }

            if (! $disk->exists($temporaryPath)) {
                throw new RuntimeException('Dataset download did not create a local file.');
            }

            if ($disk->size($temporaryPath) === 0) {
                throw new RuntimeException('Dataset download returned an empty response.');
            }

            $mimeType = $this->normaliseMimeType($response->header('Content-Type'));
            $extension = $this->resolveExtension($dataset->source_uri, $mimeType);
            $fileName = $extension !== ''
                ? sprintf('%s.%s', Str::uuid(), $extension)
                : (string) Str::uuid();

            $path = 'datasets/' . $fileName;

            if (! $disk->move($temporaryPath, $path)) {
                throw new RuntimeException('Unable to move downloaded dataset into place.');
            }

            $temporaryPath = null;

            $checksum = hash_file('sha256', $disk->path($path));

            $dataset->file_path = $path;
            $dataset->mime_type = $mimeType;
            $dataset->checksum = $checksum;
            $dataset->save();

            $schemaMapping = is_array($dataset->schema_mapping) ? $dataset->schema_mapping : [];
            $processingService->finalise($dataset, $schemaMapping);
        } catch (Throwable $exception) {
            if ($temporaryPath !== null) {
                $disk->delete($temporaryPath);
            }

            if ($path !== null) {
                $disk->delete($path);
            }

            $dataset->refresh();
            $dataset->file_path = null;
            $dataset->checksum = null;
            $dataset->mime_type = null;
            $dataset->ingested_at = null;
            $dataset->status = DatasetStatus::Failed;
            $dataset->metadata = $processingService->mergeMetadata($dataset->metadata, [
                'ingest_error' => $exception->getMessage(),
            ]);
            $dataset->save();

            event(new DatasetIngestionFailed($dataset, $exception->getMessage()));

            throw $exception;
        } finally {
            if ($temporaryPath !== null && $disk->exists($temporaryPath)) {
                $disk->delete($temporaryPath);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Remote dataset ingestion failed', [
            'dataset_id' => $this->datasetId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function formatDownloadError(RequestException $exception): string
    {
        $status = $exception->response?->status();

        if ($status !== null) {
            return sprintf('Unable to download dataset (HTTP %d).', $status);
        }

        $message = trim($exception->getMessage());

        return $message !== ''
            ? sprintf('Unable to download dataset: %s', $message)
            : 'Unable to download dataset due to an unexpected network error.';
    }

    private function resolveExtension(string $uri, ?string $mimeType): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (is_string($path)) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            if (is_string($extension) && $extension !== '') {
                return strtolower($extension);
            }
        }

        if ($mimeType !== null) {
            $map = [
                'text/csv' => 'csv',
                'application/csv' => 'csv',
                'application/json' => 'json',
                'application/geo+json' => 'geojson',
            ];

            $normalised = strtolower($mimeType);

            if (array_key_exists($normalised, $map)) {
                return $map[$normalised];
            }
        }

        return '';
    }

    private function normaliseMimeType(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $parts = explode(';', $raw);
        $mime = trim($parts[0]);

        return $mime !== '' ? strtolower($mime) : null;
    }
}
