<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\CreateModelRequest;
use App\Http\Requests\EvaluateModelRequest;
use App\Http\Requests\ModelIndexRequest;
use App\Http\Requests\ModelStatusRequest;
use App\Http\Requests\RollbackModelRequest;
use App\Http\Requests\TrainModelRequest;
use App\Enums\TrainingStatus;
use App\Http\Resources\ModelCollection;
use App\Http\Resources\ModelResource;
use App\Jobs\EvaluateModelJob;
use App\Jobs\TrainModelJob;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Models\User;
use App\Services\IdempotencyService;
use App\Services\ModelStatusService;
use App\Services\ModelRegistry;
use App\Support\InteractsWithPagination;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ModelController extends BaseController
{
    use InteractsWithPagination;

    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    /**
     * Returns a collection of predictive models.
     *
     * @param ModelIndexRequest $request
     *
     * @return JsonResponse
     */
    public function index(ModelIndexRequest $request): JsonResponse
    {
        $query = PredictiveModel::query()->with(['trainingRuns' => function ($query): void {
            $query->latest('created_at')->limit(3);
        }]);

        $filters = $request->input('filter', []);

        if (is_array($filters)) {
            if (array_key_exists('tag', $filters) && filled($filters['tag'])) {
                $query->where('tag', $filters['tag']);
            }

            if (array_key_exists('area', $filters) && filled($filters['area'])) {
                $query->where('area', $filters['area']);
            }

            if (array_key_exists('status', $filters) && filled($filters['status'])) {
                $query->where('status', $filters['status']);
            }
        }

        $perPage = $this->resolvePerPage($request, 15);

        [$sortColumn, $sortDirection] = $this->resolveSort(
            $request,
            [
                'name' => 'name',
                'status' => 'status',
                'trained_at' => 'trained_at',
                'updated_at' => 'updated_at',
                'created_at' => 'created_at',
            ],
            'updated_at',
            'desc'
        );

        $query->orderBy($sortColumn, $sortDirection);

        if ($sortColumn !== 'updated_at') {
            $query->orderByDesc('updated_at');
        }

        $models = $query
            ->paginate($perPage)
            ->appends($request->query());

        return $this->successResponse(
            new ModelCollection($models)
        );
    }

    /**
     * Creates a new predictive model.
     *
     * @param CreateModelRequest $request
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function store(CreateModelRequest $request): JsonResponse
    {
        $this->authorize('create', PredictiveModel::class);

        $validated = $request->validated();

        $model = new PredictiveModel();
        $model->id = (string) Str::uuid();
        $model->name = $validated['name'];
        $model->dataset_id = $validated['dataset_id'] ?? null;
        $model->version = $validated['version'] ?? '1.0.0';
        $model->tag = $validated['tag'] ?? null;
        $model->area = $validated['area'] ?? null;
        $model->hyperparameters = $validated['hyperparameters'] ?? null;
        $model->metadata = $validated['metadata'] ?? null;

        $user = $request->user();

        if ($user instanceof User) {
            $model->created_by = $user->getKey();
        }

        $model->save();

        $model = $model->fresh(['trainingRuns']);

        return $this->successResponse(
            new ModelResource($model),
            JsonResponse::HTTP_CREATED
        );
    }

    /**
     * Display the specified predictive model.
     *
     * @param string $id
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function show(string $id): JsonResponse
    {
        $model = PredictiveModel::query()
            ->with(['trainingRuns' => fn ($query) => $query->orderByDesc('created_at')->limit(5)])
            ->findOrFail($id);

        $this->authorize('view', $model);

        return $this->successResponse(new ModelResource($model));
    }

    /**
     * List available artifacts for the specified predictive model.
     *
     * @param string $id
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function artifacts(string $id): JsonResponse
    {
        $model = PredictiveModel::query()->findOrFail($id);

        $this->authorize('view', $model);

        $disk = Storage::disk('local');
        $directory = sprintf('models/%s', $model->getKey());

        $files = $disk->directoryExists($directory)
            ? array_filter($disk->files($directory), static fn ($path) => str_ends_with($path, '.json'))
            : [];

        $artifacts = [];

        foreach ($files as $file) {
            try {
                $payload = json_decode($disk->get($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            if (! is_array($payload)) {
                continue;
            }

            $artifacts[] = [
                'version' => pathinfo($file, PATHINFO_FILENAME),
                'trained_at' => $payload['trained_at'] ?? null,
                'model_type' => $payload['model_type'] ?? null,
                'metrics' => $payload['metrics'] ?? null,
                'hyperparameters' => $payload['hyperparameters'] ?? null,
                'path' => $file,
            ];
        }

        usort($artifacts, static fn ($a, $b) => ($b['version'] ?? '') <=> ($a['version'] ?? ''));

        return $this->successResponse(['artifacts' => $artifacts]);
    }

    /**
     * Display training metrics and evaluations for the specified predictive model.
     *
     * @param string $id
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function metrics(string $id): JsonResponse
    {
        $model = PredictiveModel::query()
            ->with(['trainingRuns' => fn ($query) => $query->orderByDesc('created_at')->limit(10)])
            ->findOrFail($id);

        $this->authorize('view', $model);

        $evaluations = [];
        $metadata = $model->metadata;

        if (is_array($metadata) && isset($metadata['evaluations']) && is_array($metadata['evaluations'])) {
            $evaluations = array_values(array_filter($metadata['evaluations'], static fn ($entry) => is_array($entry)));
        }

        $trainingRuns = $model->trainingRuns->map(static function (TrainingRun $run): array {
            return [
                'id' => $run->id,
                'status' => $run->status->value,
                'metrics' => $run->metrics,
                'hyperparameters' => $run->hyperparameters,
                'created_at' => $run->created_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ];
        });

        return $this->successResponse([
            'model_id' => $model->id,
            'current_metrics' => $model->metrics,
            'current_hyperparameters' => $model->hyperparameters,
            'evaluations' => $evaluations,
            'training_runs' => $trainingRuns,
        ]);
    }

    /**
     * Rollbacks the model to a previous version.
     *
     * @param string $id
     * @param RollbackModelRequest $request
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function rollback(string $id, RollbackModelRequest $request): JsonResponse
    {
        $model = PredictiveModel::query()->findOrFail($id);

        $this->authorize('train', $model);

        $validated = $request->validated();
        $version = $validated['version'];

        $artifactPath = sprintf('models/%s/%s.json', $model->getKey(), $version);
        $disk = Storage::disk('local');

        if (! $disk->exists($artifactPath)) {
            throw new RuntimeException(sprintf('Artifact "%s" could not be found.', $artifactPath));
        }

        try {
            $payload = json_decode($disk->get($artifactPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Failed to decode artifact payload.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Artifact payload is invalid.');
        }

        $metadata = $model->metadata ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $metadata['artifact_path'] = $artifactPath;

        $model->fill([
            'version' => $version,
            'metrics' => $payload['metrics'] ?? null,
            'hyperparameters' => $payload['hyperparameters'] ?? null,
            'metadata' => $metadata,
        ])->save();

        return $this->successResponse([
            'message' => 'Model rolled back successfully',
            'model' => new ModelResource($model->fresh(['trainingRuns'])),
        ]);
    }

    /**
     * Initiates training for the specified predictive model.
     *
     * @param TrainModelRequest $request
     * @param ModelStatusService $statusService
     * @param IdempotencyService $idempotencyService
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function train(
        TrainModelRequest $request,
        ModelStatusService $statusService,
        IdempotencyService $idempotencyService,
    ): JsonResponse {
        $validated = $request->validated();
        $model = PredictiveModel::query()->findOrFail($validated['model_id']);

        $this->authorize('train', $model);

        $cachedResponse = $idempotencyService->getCachedResponse($request, 'models.train', $model->id);

        if ($cachedResponse !== null) {
            return $this->successResponse(
                $cachedResponse,
                JsonResponse::HTTP_ACCEPTED
            );
        }

        $user = $request->user();
        $initiatedBy = $user instanceof User ? $user->getKey() : null;
        $hyperparameters = $validated['hyperparameters'] ?? [];

        $run = new TrainingRun([
            'id' => (string) Str::uuid(),
            'status' => TrainingStatus::Queued,
            'hyperparameters' => $hyperparameters,
            'queued_at' => now(),
            'initiated_by' => $initiatedBy,
        ]);

        $run->model()->associate($model);
        $run->save();

        $statusService->markQueued($model->id, 'training');

        $dispatch = TrainModelJob::dispatch($run->id, $hyperparameters ?: null);

        $responsePayload = [
            'message' => 'Training job queued',
            'training_run_id' => $run->id,
            'job_id' => $dispatch?->id ?? $run->id,
        ];

        $idempotencyService->storeResponse($request, 'models.train', $responsePayload, $model->id);

        return $this->successResponse(
            $responsePayload,
            JsonResponse::HTTP_ACCEPTED
        );
    }

    /**
     * Evaluates the specified predictive model.
     *
     * @param string $id
     * @param EvaluateModelRequest $request
     * @param ModelStatusService $statusService
     * @param IdempotencyService $idempotencyService
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function evaluate(
        string $id,
        EvaluateModelRequest $request,
        ModelStatusService $statusService,
        IdempotencyService $idempotencyService,
    ): JsonResponse {
        $model = PredictiveModel::query()->findOrFail($id);

        $this->authorize('evaluate', $model);

        $validated = $request->validated();
        $metrics = $validated['metrics'] ?? [];

        $cachedResponse = $idempotencyService->getCachedResponse($request, 'models.evaluate', $model->id);

        if ($cachedResponse !== null) {
            return $this->successResponse(
                $cachedResponse,
                JsonResponse::HTTP_ACCEPTED
            );
        }

        $statusService->markQueued($model->id, 'evaluating');

        $dispatch = EvaluateModelJob::dispatch(
            $model->id,
            $validated['dataset_id'] ?? null,
            $metrics === [] ? null : $metrics,
            $validated['notes'] ?? null,
        );

        $responsePayload = [
            'message' => 'Evaluation queued',
            'model_id' => $model->id,
            'job_id' => $dispatch?->id ?? (string) Str::uuid(),
        ];

        $idempotencyService->storeResponse($request, 'models.evaluate', $responsePayload, $model->id);

        return $this->successResponse(
            $responsePayload,
            JsonResponse::HTTP_ACCEPTED
        );
    }

    /**
     * Activates the specified predictive model.
     *
     * @throws AuthorizationException
     */
    public function activate(string $id, ModelRegistry $registry): JsonResponse
    {
        $model = PredictiveModel::query()->findOrFail($id);

        $this->authorize('activate', $model);

        $registry->activate($model);

        $model = $this->freshModelForResponse($model);

        return $this->successResponse(new ModelResource($model));
    }

    /**
     * Deactivates the specified predictive model.
     *
     * @throws AuthorizationException
     */
    public function deactivate(string $id, ModelRegistry $registry): JsonResponse
    {
        $model = PredictiveModel::query()->findOrFail($id);

        $this->authorize('deactivate', $model);

        $registry->deactivate($model);

        $model = $this->freshModelForResponse($model);

        return $this->successResponse(new ModelResource($model));
    }

    /**
     * Check the status of model training or evaluation.
     *
     * @param ModelStatusService $statusService
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function status(ModelStatusRequest $request, ModelStatusService $statusService): JsonResponse
    {
        $model = $request->model();

        $this->authorize('view', $model);

        $status = $statusService->getStatus($model);

        return $this->successResponse([
            'state' => $status['state'],
            'progress' => $status['progress'],
            'updated_at' => $status['updated_at'],
            'message' => $status['message'] ?? null,
        ]);
    }

    /**
     * Returns a fresh instance of the model with related data for response.
     *
     * @param PredictiveModel $model
     *
     * @return PredictiveModel
     */
    private function freshModelForResponse(PredictiveModel $model): PredictiveModel
    {
        return $model->fresh([
            'trainingRuns' => fn ($query) => $query->latest('created_at')->limit(3),
        ]) ?? $model;
    }
}
