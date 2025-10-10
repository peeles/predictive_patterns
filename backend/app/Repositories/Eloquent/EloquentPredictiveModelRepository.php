<?php

namespace App\Repositories\Eloquent;

use App\Models\PredictiveModel;
use App\Repositories\PredictiveModelRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class EloquentPredictiveModelRepository implements PredictiveModelRepositoryInterface
{
    /**
     * @var list<string>
     */
    private const DEFAULT_RELATIONS = [
        'dataset',
        'creator',
    ];

    public function query(): Builder
    {
        return PredictiveModel::query()->with(self::DEFAULT_RELATIONS);
    }

    public function find(string $id): ?PredictiveModel
    {
        return $this->query()->find($id);
    }

    public function findOrFail(string $id): PredictiveModel
    {
        return $this->query()->findOrFail($id);
    }
}
