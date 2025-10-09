<?php

namespace App\Repositories;

use App\Models\Dataset;
use Illuminate\Database\Eloquent\Builder;

interface DatasetRepositoryInterface
{
    public function query(): Builder;

    public function find(string $id): ?Dataset;

    public function findOrFail(string $id): Dataset;
}
