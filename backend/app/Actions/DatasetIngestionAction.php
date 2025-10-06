<?php

namespace App\Actions;

use App\Enums\DatasetStatus;
use App\Events\DatasetStatusUpdated;
use App\Http\Requests\DatasetIngestRequest;
use App\Jobs\IngestRemoteDataset;
use App\Models\Dataset;
use App\Models\User;
use App\Services\DatasetProcessingService;
use App\Services\Datasets\SchemaMapper;
use App\Support\Broadcasting\BroadcastDispatcher;
use App\Support\Filesystem\CsvCombiner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

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

        $event = DatasetStatusUpdated::fromDataset($dataset);

        BroadcastDispatcher::dispatch($event, [
            'dataset_id' => $event->datasetId,
            'status' => $event->status->value,
        ]);

        if ($dataset->source_type === 'url') {
            IngestRemoteDataset::dispatch($dataset->id);

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

        $dataset = $this->processingService->queueFinalise($dataset, $schemaMapping, $additionalMetadata);

        return $dataset->refresh();
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
}
