<?php

namespace App\Repositories;

use App\Models\PredictiveModel;
use Illuminate\Database\Eloquent\Builder;

interface PredictiveModelRepositoryInterface
{
    public function query(): Builder;

    public function find(string $id): ?PredictiveModel;

    public function findOrFail(string $id): PredictiveModel;
}
