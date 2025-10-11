<?php

namespace App\Actions;

use App\Enums\DatasetStatus;
use App\Exceptions\QueueConnectionException;
use App\Events\DatasetStatusChanged;
use App\Http\Requests\DatasetIngestRequest;
use App\Jobs\IngestRemoteDataset;
use App\Models\Dataset;
use App\Models\User;
use App\Services\DatasetProcessingService;
use App\Services\Datasets\SchemaMapper;
use App\Support\Filesystem\CsvCombiner;
use App\Support\Queue\QueueConnectionDiagnostics;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DatasetIngestionAction
{
    public function __construct(
        private readonly DatasetProcessingService $processingService,
        private readonly SchemaMapper $schemaMapper,
        private readonly CsvCombiner $csvCombiner,
    ) {
    }

    public function execute(DatasetIngestRequest $request): Dataset
    {
        $validated = $request->validated();
        $user = $request->user();
        $createdBy = $user instanceof User ? $user->getKey() : null;
        $schemaMapping = $this->schemaMapper->normalise($request->input('schema'));

        $status = $validated['source_type'] === 'url'
            ? DatasetStatus::Pending
            : DatasetStatus::Processing;

        $dataset = new Dataset([
            'id' => (string) Str::uuid(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'source_type' => $validated['source_type'],
            'source_uri' => $validated['source_uri'] ?? null,
            'metadata' => $validated['metadata'] ?? [],
            'status' => $status,
            'created_by' => $createdBy,
        ]);

        if ($schemaMapping !== []) {
            $dataset->schema_mapping = $schemaMapping;
        }

        $uploadedFiles = $this->collectUploadedFiles($request);

        if ($uploadedFiles !== []) {
            $this->storeUploadedFiles($dataset, $uploadedFiles);
        }

        $dataset->save();
        $dataset->refresh();

        if ($dataset->status === DatasetStatus::Processing) {
            event(DatasetStatusChanged::fromDataset($dataset, 0.0));
        }

        if ($dataset->source_type === 'url') {
            $this->dispatchRemoteIngestion($dataset);

            return $dataset;
        }

        $additionalMetadata = [];

        if ($uploadedFiles !== []) {
            $additionalMetadata = $this->buildSourceFilesMetadata($uploadedFiles);
            $dataset->metadata = $this->processingService->mergeMetadata(
                $dataset->metadata,
                $additionalMetadata
            );
            $dataset->save();
        }

        $dataset = $this->processingService->queueFinalise($dataset, $schemaMapping, $additionalMetadata, true);

        if ($this->shouldRefreshAfterQueue()) {
            return $dataset->refresh();
        }

        return $dataset;
    }

    private function dispatchRemoteIngestion(Dataset $dataset): void
    {
        $connection = (string) config('queue.default', 'sync');

        try {
            IngestRemoteDataset::dispatch($dataset->id);
        } catch (Throwable $exception) {
            if (! $this->isQueueConnectionIssue($exception)) {
                throw $exception;
            }

            $diagnostics = QueueConnectionDiagnostics::describe($connection);

            Log::error('Failed to queue remote dataset ingestion due to queue connection failure.', array_merge(
                ['dataset_id' => $dataset->id],
                $diagnostics,
                ['exception' => $exception]
            ));

            throw QueueConnectionException::forConnection($connection, $exception);
        }
    }

    private function isQueueConnectionIssue(Throwable $exception): bool
    {
        if (class_exists(\RedisException::class) && $exception instanceof \RedisException) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        if ($message !== '' && str_contains($message, 'connection refused')) {
            return true;
        }

        $previous = $exception->getPrevious();

        return $previous instanceof Throwable
            ? $this->isQueueConnectionIssue($previous)
            : false;
    }

    /**
     * @param array<int, UploadedFile> $files
     */
    private function buildSourceFilesMetadata(array $files): array
    {
        $names = [];

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();

            if (! is_string($name) || trim($name) === '') {
                $name = $file->getFilename();
            }

            $names[] = (string) $name;
        }

        return [
            'source_files' => $names,
            'source_file_count' => count($names),
        ];
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function collectUploadedFiles(DatasetIngestRequest $request): array
    {
        $files = Arr::wrap($request->file('files'));
        $files = array_filter($files, static fn ($file) => $file instanceof UploadedFile);

        if ($files === []) {
            $single = $request->file('file');

            if ($single instanceof UploadedFile) {
                $files = [$single];
            }
        }

        return array_values($files);
    }

    /**
     * @param array<int, UploadedFile> $files
     */
    private function storeUploadedFiles(Dataset $dataset, array $files): void
    {
        if (count($files) === 1) {
            $file = $files[0];
            $fileName = sprintf('%s.%s', Str::uuid(), $file->getClientOriginalExtension());
            $path = $file->storeAs('datasets', $fileName, 'local');

            if ($path === false) {
                throw new RuntimeException('Unable to store uploaded dataset file.');
            }

            $dataset->file_path = $path;
            $dataset->mime_type = $file->getMimeType();
            $dataset->checksum = hash_file('sha256', $file->getRealPath());

            return;
        }

        [$path, $mimeType] = $this->csvCombiner->combine($files);
        $dataset->file_path = $path;
        $dataset->mime_type = $mimeType;
        $dataset->checksum = hash_file('sha256', Storage::disk('local')->path($path));
    }

    private function shouldRefreshAfterQueue(): bool
    {
        $connection = (string) config('queue.default', 'sync');
        $driver = config(sprintf('queue.connections.%s.driver', $connection));

        if ($driver === null && $connection !== '') {
            $driver = config(sprintf('queue.connections.%s.driver', config('queue.default')));
        }

        return $driver === 'null';
    }
}
