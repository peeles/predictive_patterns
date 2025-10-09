<?php

namespace App\Repositories\Eloquent;

use App\Models\Dataset;
use App\Repositories\DatasetRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class EloquentDatasetRepository implements DatasetRepositoryInterface
{
    public function query(): Builder
    {
        return Dataset::query();
    }

    public function find(string $id): ?Dataset
    {
        return $this->query()->find($id);
    }

    public function findOrFail(string $id): Dataset
    {
        return $this->query()->findOrFail($id);
    }
}
