<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Prediction;
use App\Support\ResolvesRoles;

class PredictionPolicy
{
    use ResolvesRoles;

    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, Prediction $prediction): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        return in_array($this->resolveRole($user), [Role::Admin, Role::Analyst], true);
    }
}
