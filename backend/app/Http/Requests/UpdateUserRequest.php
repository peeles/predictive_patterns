<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Support\ResolvesRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');
        $userId = is_object($user) && method_exists($user, 'getKey') ? $user->getKey() : $user;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
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
