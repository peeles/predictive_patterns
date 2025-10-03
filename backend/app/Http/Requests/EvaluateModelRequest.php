<?php

namespace App\Http\Requests;

use App\Support\ResolvesRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EvaluateModelRequest extends FormRequest
{
    use ResolvesRoles;

    public function authorize(): bool
    {
        $role = $this->resolveRole($this->user());

        return $role->canEvaluateModels();
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        return [
            'dataset_id' => ['nullable', 'uuid', Rule::exists('datasets', 'id')],
            'metrics' => ['nullable', 'array'],
            'metrics.*' => ['numeric'],
            'notes' => ['sometimes', 'string', 'max:2000'],
        ];
    }

    protected function passedValidation(): void
    {
        $payloadSize = strlen((string) json_encode($this->all()));
        $limitKb = max((int) config('api.payload_limits.predict', 10_240), 1);

        if ($payloadSize > $limitKb * 1024) {
            throw ValidationException::withMessages([
                'payload' => sprintf('Payload exceeds maximum allowed size of %dKB.', $limitKb),
            ]);
        }
    }
}
