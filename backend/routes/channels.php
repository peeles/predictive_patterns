<?php

use App\Enums\Role;
use App\Models\Dataset;
use App\Models\Prediction;
use App\Models\PredictiveModel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Broadcast::channel('models.{modelId}.status', function ($user, string $modelId): bool {
    $model = PredictiveModel::find($modelId);

    return $model !== null && $user->can('view', $model);
});

Broadcast::channel('datasets.{datasetId}.status', function ($user, string $datasetId): bool {
    $dataset = Dataset::find($datasetId);

    return $dataset !== null && $user->can('view', $dataset);
});

Broadcast::channel('predictions.{predictionId}.status', function ($user, string $predictionId): bool {
    $prediction = Prediction::find($predictionId);

    return $prediction !== null && $user->can('view', $prediction);
});

Broadcast::channel('dataset.ingestion.runs', function ($user): bool {
    $role = method_exists($user, 'role') ? $user->role() : null;

    if ($role instanceof Role) {
        return $role === Role::Admin;
    }

    return (string) $role === Role::Admin->value;
});
