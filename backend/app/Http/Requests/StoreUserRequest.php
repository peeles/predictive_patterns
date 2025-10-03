<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Support\ResolvesRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);

        if (array_key_exists('password', $validated)) {
            $validated['password'] = Hash::make($validated['password']);
        }

        return $validated;
    }
}
