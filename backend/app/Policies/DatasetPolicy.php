<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Dataset;
use App\Support\ResolvesRoles;

class DatasetPolicy
{
    use ResolvesRoles;

    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, Dataset $dataset): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        return $this->resolveRole($user) === Role::Admin;
    }

    public function update(mixed $user, Dataset $dataset): bool
    {
        return $this->resolveRole($user) === Role::Admin;
    }

    public function delete(mixed $user, Dataset $dataset): bool
    {
        return $this->resolveRole($user) === Role::Admin;
    }
}
