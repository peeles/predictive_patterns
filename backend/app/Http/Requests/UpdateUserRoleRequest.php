<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Support\ResolvesRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends FormRequest
{
    use ResolvesRoles;

    public function authorize(): bool
    {
        return $this->resolveRole($this->user()) === Role::Admin;
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }
}
