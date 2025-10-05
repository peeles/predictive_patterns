<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\DatasetStatus;
use App\Events\DatasetStatusUpdated;
use App\Http\Requests\DatasetIndexRequest;
use App\Http\Requests\DatasetIngestRequest;
use App\Http\Requests\DatasetRunIndexRequest;
use App\Http\Resources\DataIngestionCollection;
use App\Http\Resources\DatasetCollection;
use App\Http\Resources\DatasetResource;
use App\Models\CrimeIngestionRun;
use App\Models\Dataset;
use App\Models\User;
use App\Jobs\IngestRemoteDataset;
use App\Services\DatasetAnalysisService;
use App\Services\DatasetProcessingService;
use App\Support\Broadcasting\BroadcastDispatcher;
use App\Support\InteractsWithPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use RuntimeException;

class DatasetController extends BaseController
{
    use InteractsWithPagination;

    public function __construct(
        private readonly DatasetProcessingService $processingService,
        private readonly DatasetAnalysisService $analysisService,
    )
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    /**
     * Returns a collection of datasets.
     *
     * @param DatasetIndexRequest $request
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function index(DatasetIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Dataset::class);

        $perPage = $this->resolvePerPage($request, 25);

        [$sortColumn, $sortDirection] = $this->resolveSort(
            $request,
            [
                'name' => 'name',
                'status' => 'status',
                'source_type' => 'source_type',
                'features_count' => 'features_count',
                'ingested_at' => 'ingested_at',
                'created_at' => 'created_at',
            ],
            'created_at',
            'desc'
        );

        $query = Dataset::query();

        if (DatasetResource::featuresTableExists()) {
            $query->withCount('features');
        }

        $filters = $request->input('filter', []);

        if (is_array($filters)) {
            if (array_key_exists('status', $filters) && filled($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (array_key_exists('source_type', $filters) && filled($filters['source_type'])) {
                $query->where('source_type', $filters['source_type']);
            }

            if (array_key_exists('search', $filters) && filled($filters['search'])) {
                $query->where(function (Builder $builder) use ($filters): void {
                    $term = '%' . $filters['search'] . '%';
                    $builder
                        ->where('name', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            }
        }

        $query->orderBy($sortColumn, $sortDirection);

        if ($sortColumn !== 'created_at') {
            $query->orderByDesc('created_at');
        }

        $datasets = $query
            ->paginate($perPage)
            ->appends($request->query());

        return $this->successResponse(
            new DatasetCollection($datasets)
        );
    }

    /**
     * Ingests a new dataset.
     *
     * @param DatasetIngestRequest $request
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function ingest(DatasetIngestRequest $request): JsonResponse
    {
        $this->authorize('create', Dataset::class);

        $validated = $request->validated();
        $user = $request->user();
        $createdBy = $user instanceof User ? $user->getKey() : null;
        $schemaMapping = $this->normaliseSchemaMapping($request->input('schema'));

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

        $path = null;

        $uploadedFiles = $this->collectUploadedFiles($request);

        if ($uploadedFiles !== []) {
            if (count($uploadedFiles) === 1) {
                $file = $uploadedFiles[0];
                $fileName = sprintf('%s.%s', Str::uuid(), $file->getClientOriginalExtension());
                $path = $file->storeAs('datasets', $fileName, 'local');
                $dataset->file_path = $path;
                $dataset->mime_type = $file->getMimeType();
                $dataset->checksum = hash_file('sha256', $file->getRealPath());
            } else {
                [$path, $mimeType] = $this->storeCombinedCsv($uploadedFiles);
                $dataset->file_path = $path;
                $dataset->mime_type = $mimeType;
                $dataset->checksum = hash_file('sha256', Storage::disk('local')->path($path));
                $dataset->metadata = $this->processingService->mergeMetadata(
                    $dataset->metadata,
                    $this->buildSourceFilesMetadata($uploadedFiles)
                );
            }
        }

        $dataset->save();
        $dataset->refresh();

        $event = DatasetStatusUpdated::fromDataset($dataset);
        BroadcastDispatcher::dispatch($event, [
            'dataset_id' => $event->datasetId,
            'status' => $event->status,
        ]);

        if ($dataset->source_type === 'url') {
            IngestRemoteDataset::dispatch($dataset->id);

            if (DatasetResource::featuresTableExists()) {
                $dataset->loadCount('features');
            }

            return $this->successResponse(
                new DatasetResource($dataset),
                Response::HTTP_CREATED
            );
        }

        $dataset = $this->processingService->queueFinalise($dataset, $schemaMapping);

        $dataset->refresh();

        if (DatasetResource::featuresTableExists()) {
            $dataset->loadCount('features');
        }

        return $this->successResponse(
            new DatasetResource($dataset),
            Response::HTTP_CREATED
        );
    }

    /**
     * Display a single dataset record.
     *
     * @throws AuthorizationException
     */
    public function show(Dataset $dataset): JsonResponse
    {
        $this->authorize('view', $dataset);

        if (DatasetResource::featuresTableExists()) {
            $dataset->loadCount('features');
        }

        return $this->successResponse(
            new DatasetResource($dataset)
        );
    }

    /**
     * Returns an analysis summary for a dataset.
     *
     * @throws AuthorizationException
     */
    public function analysis(Dataset $dataset): JsonResponse
    {
        $this->authorize('view', $dataset);

        $summary = $this->analysisService->analyze($dataset);

        return $this->successResponse($summary);
    }

    /**
     * Returns a collection of dataset ingestion runs.
     *
     * @param DatasetRunIndexRequest $request
     *
     * @return JsonResponse
     */
    public function runs(DatasetRunIndexRequest $request): JsonResponse
    {
        $perPage = $this->resolvePerPage($request, 25);

        [$sortColumn, $sortDirection] = $this->resolveSort(
            $request,
            [
                'month' => 'month',
                'status' => 'status',
                'records_expected' => 'records_expected',
                'records_inserted' => 'records_inserted',
                'records_detected' => 'records_detected',
                'records_existing' => 'records_existing',
                'started_at' => 'started_at',
                'finished_at' => 'finished_at',
                'created_at' => 'created_at',
            ],
            'started_at',
            'desc'
        );

        $query = CrimeIngestionRun::query();

        $filters = $request->input('filter', []);

        if (is_array($filters)) {
            if (array_key_exists('status', $filters) && filled($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (array_key_exists('month', $filters) && filled($filters['month'])) {
                $query->where('month', $filters['month']);
            }

            if (array_key_exists('dry_run', $filters) && $filters['dry_run'] !== null && $filters['dry_run'] !== '') {
                $value = filter_var($filters['dry_run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($value !== null) {
                    $query->where('dry_run', $value);
                }
            }
        }

        $query->orderBy($sortColumn, $sortDirection);

        if ($sortColumn !== 'created_at') {
            $query->orderByDesc('created_at');
        }

        $runs = $query
            ->paginate($perPage)
            ->appends($request->query());

        return $this->successResponse(
            new DataIngestionCollection($runs)
        );
    }


    /**
     * Builds metadata about the source files.
     *
     * @param array<int, UploadedFile> $files
     *
     * @return array{source_files: list<string>, source_file_count: int}
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
     * Normalises and validates the schema mapping input.
     *
     * @param mixed $schema
     *
     * @return array
     */
    private function normaliseSchemaMapping(mixed $schema): array
    {
        if (! is_array($schema)) {
            return [];
        }

        $allowed = ['timestamp', 'latitude', 'longitude', 'category', 'risk', 'label'];
        $normalised = [];

        foreach ($allowed as $key) {
            $value = $schema[$key] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $normalised[$key] = $value;
        }

        foreach (['timestamp', 'latitude', 'longitude', 'category'] as $required) {
            if (! array_key_exists($required, $normalised)) {
                return [];
            }
        }

        return $normalised;
    }

    /**
     * Stores multiple uploaded CSV files as a single combined CSV file
     *
     * @param array<int, UploadedFile> $files
     *
     * @return array{0: string, 1: string}
     */
    private function storeCombinedCsv(array $files): array
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'dataset-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create temporary dataset file.');
        }

        $combinedHandle = fopen($temporaryPath, 'w+b');

        if ($combinedHandle === false) {
            throw new RuntimeException(sprintf('Unable to open temporary dataset file "%s" for writing.', $temporaryPath));
        }

        try {
            foreach ($files as $index => $file) {
                $handle = fopen($file->getRealPath(), 'rb');

                if ($handle === false) {
                    continue;
                }

                try {
                    if ($index > 0) {
                        $this->ensureTrailingNewline($combinedHandle);
                        $this->discardFirstLine($handle);
                    }

                    stream_copy_to_stream($handle, $combinedHandle);
                } finally {
                    fclose($handle);
                }
            }
        } finally {
            fclose($combinedHandle);
        }

        $fileName = sprintf('%s.csv', Str::uuid());
        $storagePath = 'datasets/' . $fileName;

        $stream = fopen($temporaryPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException(sprintf('Unable to read combined dataset file "%s".', $temporaryPath));
        }

        Storage::disk('local')->put($storagePath, $stream);
        fclose($stream);

        @unlink($temporaryPath);

        return [$storagePath, 'text/csv'];
    }

    /**
     * Collects uploaded files from the request.
     *
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
     * Discards the first line from a file handle.
     *
     * @param resource $handle
     */
    private function discardFirstLine($handle): void
    {
        while (! feof($handle)) {
            $character = fgetc($handle);

            if ($character === false) {
                break;
            }

            if ($character === "\n") {
                break;
            }

            if ($character === "\r") {
                $next = fgetc($handle);

                if ($next !== "\n" && $next !== false) {
                    fseek($handle, -1, SEEK_CUR);
                }

                break;
            }
        }
    }

    /**
     * Ensures that a file handle ends with a newline character.
     *
     * @param resource $handle
     */
    private function ensureTrailingNewline($handle): void
    {
        fflush($handle);
        $currentPosition = ftell($handle);

        if ($currentPosition === false || $currentPosition === 0) {
            return;
        }

        if (fseek($handle, -1, SEEK_END) !== 0) {
            fseek($handle, 0, SEEK_END);

            return;
        }

        $lastCharacter = fgetc($handle);

        if ($lastCharacter !== "\n" && $lastCharacter !== "\r") {
            fwrite($handle, PHP_EOL);
        }

        fseek($handle, 0, SEEK_END);
    }
}
