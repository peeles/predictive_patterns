<?php

use App\Models\PredictiveModel;
use App\Models\Dataset;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('models.{modelId}.status', function ($user, string $modelId): bool {
    $model = PredictiveModel::find($modelId);

    return $model !== null && $user->can('view', $model);
});

Broadcast::channel('datasets.{datasetId}.status', function ($user, string $datasetId): bool {
    $dataset = Dataset::find($datasetId);

    return $dataset !== null && $user->can('view', $dataset);
});
