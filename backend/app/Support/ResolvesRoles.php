<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

trait ResolvesRoles
{
    private function resolveRole(mixed $user): Role
    {
        if ($user instanceof Role) {
            return $user;
        }

        if ($user instanceof User) {
            return $user->role();
        }

        if (is_object($user)) {
            if (method_exists($user, 'role')) {
                $role = $user->role();

                if ($role instanceof Role) {
                    return $role;
                }

                if (is_string($role)) {
                    return Role::tryFrom($role) ?? Role::Viewer;
                }
            }

            if (method_exists($user, 'getAttribute')) {
                $attributeRole = $user->getAttribute('role');

                if ($attributeRole instanceof Role) {
                    return $attributeRole;
                }

                if (is_string($attributeRole)) {
                    return Role::tryFrom($attributeRole) ?? Role::Viewer;
                }
            }

            if (property_exists($user, 'role')) {
                $propertyRole = $user->role;

                if ($propertyRole instanceof Role) {
                    return $propertyRole;
                }

                if (is_string($propertyRole)) {
                    return Role::tryFrom($propertyRole) ?? Role::Viewer;
                }
            }
        }

        return Role::Viewer;
    }
}
