<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\PredictionStatus;
use App\Http\Requests\PredictRequest;
use App\Http\Requests\PredictionIndexRequest;
use App\Http\Resources\PredictionCollection;
use App\Http\Resources\PredictionDetailResource;
use App\Models\Dataset;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use App\Services\PredictionService;
use App\Support\InteractsWithPagination;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PredictionController extends BaseController
{
    use InteractsWithPagination;

    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    /**
     * List prediction jobs with pagination.
     *
     * @param PredictionIndexRequest $request
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function index(PredictionIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Prediction::class);

        $perPage = $this->resolvePerPage($request, 10);

        [$sortColumn, $sortDirection] = $this->resolveSort(
            $request,
            [
                'status' => 'status',
                'queued_at' => ['column' => 'queued_at', 'direction' => 'desc'],
                'started_at' => ['column' => 'started_at', 'direction' => 'desc'],
                'finished_at' => ['column' => 'finished_at', 'direction' => 'desc'],
                'created_at' => ['column' => 'created_at', 'direction' => 'desc'],
            ],
            'created_at',
            'desc'
        );

        $query = Prediction::query()
            ->with(['model'])
            ->orderBy($sortColumn, $sortDirection);

        if ($sortColumn !== 'created_at') {
            $query->orderByDesc('created_at');
        }

        $filters = $request->input('filter', []);

        if (is_array($filters)) {
            $this->applyStatusFilter($query, Arr::get($filters, 'status'));
            $this->applyModelFilter($query, Arr::get($filters, 'model_id'));
            $this->applyTimeframeFilter($query, Arr::get($filters, 'from'), Arr::get($filters, 'to'));
        }

        $predictions = $query
            ->paginate($perPage)
            ->appends($request->query());

        return $this->successResponse(
            new PredictionCollection($predictions),
        );
    }

    /**
     * Create a new prediction job
     *
     * @param PredictRequest $request
     * @param PredictionService $service
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function store(PredictRequest $request, PredictionService $service): JsonResponse
    {
        $this->authorize('create', Prediction::class);

        $validated = $request->validated();
        $model = PredictiveModel::query()->findOrFail($validated['model_id']);
        $datasetId = $validated['dataset_id'] ?? null;
        $dataset = $datasetId !== null ? Dataset::query()->findOrFail($datasetId) : null;

        $prediction = $service->queuePrediction(
            $model,
            $dataset,
            $validated['parameters'] ?? [],
            $request->generateTiles(),
            $request->user(),
            $validated['metadata'] ?? [],
        )->load(['outputs', 'model', 'shapValues']);

        return $this->successResponse(
            new PredictionDetailResource($prediction),
            Response::HTTP_ACCEPTED
        );
    }

    /**
     * Get the status and results of a prediction job
     *
     * @param string $id
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function show(string $id): JsonResponse
    {
        $prediction = Prediction::query()->with(['outputs', 'model', 'shapValues'])->findOrFail($id);

        $this->authorize('view', $prediction);

        return $this->successResponse(
            new PredictionDetailResource($prediction)
        );
    }

    /**
     * Apply status filtering to the query when provided.
     *
     * @param Builder $query
     * @param mixed $status
     *
     * @return void
     */
    private function applyStatusFilter(Builder $query, mixed $status): void
    {
        if ($status === null || $status === '') {
            return;
        }

        $statuses = array_unique(array_filter((array) $status, function (mixed $value): bool {
            if (!is_string($value) || $value === '') {
                return false;
            }

            return in_array($value, array_map(
                static fn (PredictionStatus $case): string => $case->value,
                PredictionStatus::cases()
            ), true);
        }));

        if ($statuses === []) {
            return;
        }

        $query->whereIn('status', $statuses);
    }

    /**
     * Apply model filter when provided.
     *
     * @param Builder $query
     * @param mixed $modelId
     *
     * @return void
     */
    private function applyModelFilter(Builder $query, mixed $modelId): void
    {
        if (!is_string($modelId) || $modelId === '') {
            return;
        }

        $query->where('model_id', $modelId);
    }

    /**
     * Apply timeframe filtering based on creation timestamps.
     *
     * @param Builder $query
     * @param mixed $from
     * @param mixed $to
     *
     * @return void
     */
    private function applyTimeframeFilter(Builder $query, mixed $from, mixed $to): void
    {
        $fromDate = $this->parseDate($from);
        $toDate = $this->parseDate($to);

        if ($fromDate !== null) {
            $query->where('created_at', '>=', $fromDate);
        }

        if ($toDate !== null) {
            $query->where('created_at', '<=', $toDate);
        }
    }

    /**
     * Parse a date from a string, returning null if invalid.
     *
     * @param mixed $value
     *
     * @return Carbon|null
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

}
