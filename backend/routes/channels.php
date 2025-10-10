<?php

use App\Enums\Role;
use App\Models\Dataset;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Broadcast::channel('models.{modelId}.status', function ($user, string $modelId): bool {
    $isAuthorized = Cache::remember(
        "auth.model.{$modelId}.user.{$user->id}",
        300,
        static function () use ($user, $modelId): bool {
            $model = PredictiveModel::find($modelId);

            if (! $model instanceof PredictiveModel) {
                return false;
            }

            return $user->can('view', $model);
        }
    );

    usleep(random_int(1000, 5000));

    return $isAuthorized;
});

Broadcast::channel('datasets.{datasetId}.status', function ($user, string $datasetId): bool {
    $dataset = Dataset::find($datasetId);

    if (! $dataset instanceof Dataset) {
        return false;
    }

    return $user->can('view', $dataset);
});

Broadcast::channel('predictions.{predictionId}.status', function ($user, string $predictionId): bool {
    $prediction = Prediction::find($predictionId);

    if (! $prediction instanceof Prediction) {
        return false;
    }

    return $user->can('view', $prediction);
});

Broadcast::channel('dataset.ingestion.runs', function ($user): bool {
    $role = method_exists($user, 'role') ? $user->role() : null;

    if ($role instanceof Role) {
        return $role === Role::Admin;
    }

    return (string) $role === Role::Admin->value;
});
