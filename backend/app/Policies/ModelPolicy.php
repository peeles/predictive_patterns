<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\PredictiveModel;
use App\Support\ResolvesRoles;

class ModelPolicy
{
    use ResolvesRoles;

    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, PredictiveModel $model): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        return $this->resolveRole($user) === Role::Admin;
    }

    public function update(mixed $user, PredictiveModel $model): bool
    {
        return $this->resolveRole($user) === Role::Admin;
    }

    public function delete(mixed $user, PredictiveModel $model): bool
    {
        return $this->resolveRole($user) === Role::Admin;
    }

    public function train(mixed $user, PredictiveModel $model): bool
    {
        return in_array($this->resolveRole($user), [Role::Admin, Role::Analyst], true);
    }

    public function evaluate(mixed $user, PredictiveModel $model): bool
    {
        return in_array($this->resolveRole($user), [Role::Admin, Role::Analyst], true);
    }

    public function activate(mixed $user, PredictiveModel $model): bool
    {
        return $this->resolveRole($user) === Role::Admin;
    }

    public function deactivate(mixed $user, PredictiveModel $model): bool
    {
        return $this->resolveRole($user) === Role::Admin;
    }
}
