<?php

namespace App\Services;

use App\Enums\ModelStatus;
use App\Models\PredictiveModel;
use App\Repositories\PredictiveModelRepositoryInterface;
use App\Support\DatabaseTransactionHelper;

class ModelRegistry
{
    public function __construct(private readonly PredictiveModelRepositoryInterface $models)
    {
    }

    public function findActive(string $tag, ?string $area = null): ?PredictiveModel
    {
        return $this->models->query()
            ->where('tag', $tag)
            ->when($area !== null, fn ($query) => $query->where('area', $area))
            ->where('status', ModelStatus::Active->value)
            ->orderByDesc('trained_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    public function activate(PredictiveModel $model): void
    {
        DatabaseTransactionHelper::runWithoutNestedTransaction(function () use ($model): void {
            $this->models->query()
                ->where('tag', $model->tag)
                ->when($model->area !== null, fn ($query) => $query->where('area', $model->area))
                ->where('id', '!=', $model->getKey())
                ->update(['status' => ModelStatus::Inactive->value]);

            $model->status = ModelStatus::Active;
            $model->save();
        });
    }

    public function deactivate(PredictiveModel $model): void
    {
        $model->status = ModelStatus::Inactive;
        $model->save();
    }
}
