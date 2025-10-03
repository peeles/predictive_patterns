<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $role = method_exists($user, 'role') ? $user->role() : null;

        return $role instanceof Role ? $role === Role::Admin : (string) $role === Role::Admin->value;
    }

    public function rules(): array
    {
        return [];
    }
}
