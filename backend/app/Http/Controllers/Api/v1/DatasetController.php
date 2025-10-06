<?php

namespace App\Http\Controllers\Api\v1;

use App\Actions\DatasetIngestionAction;
use App\Http\Requests\DatasetIndexRequest;
use App\Http\Requests\DatasetIngestRequest;
use App\Http\Requests\DatasetRunIndexRequest;
use App\Http\Resources\DataIngestionCollection;
use App\Http\Resources\DatasetCollection;
use App\Http\Resources\DatasetResource;
use App\Models\DatasetRecordIngestionRun;
use App\Models\Dataset;
use App\Services\DatasetAnalysisService;
use App\Support\InteractsWithPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class DatasetController extends BaseController
{
    use InteractsWithPagination;

    public function __construct(
        private readonly DatasetIngestionAction $ingestionAction,
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

        $dataset = $this->ingestionAction->execute($request);

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

        $query = DatasetRecordIngestionRun::query();

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


}
