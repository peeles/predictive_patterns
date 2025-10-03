<?php

namespace App\Http\Requests;

use App\Support\ResolvesRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateModelRequest extends FormRequest
{
    use ResolvesRoles;

    public function authorize(): bool
    {
        $role = $this->resolveRole($this->user());

        return $role->canManageModels();
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'dataset_id' => ['required', 'uuid', Rule::exists('datasets', 'id')],
            'version' => ['nullable', 'string', 'max:50'],
            'tag' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:255'],
            'hyperparameters' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'dataset_id' => $this->nullifyEmptyString('dataset_id'),
            'version' => $this->nullifyEmptyString('version'),
            'tag' => $this->nullifyEmptyString('tag'),
            'area' => $this->nullifyEmptyString('area'),
        ]);
    }

    private function nullifyEmptyString(string $key): ?string
    {
        $value = $this->input($key);

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }

            return $value;
        }

        return $value;
    }
}
