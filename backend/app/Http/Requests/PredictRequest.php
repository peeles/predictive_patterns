<?php

namespace App\Http\Requests;

use App\Support\ResolvesRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PredictRequest extends FormRequest
{
    use ResolvesRoles;

    public function authorize(): bool
    {
        $role = $this->resolveRole($this->user());

        return $role->canCreatePredictions();
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        return [
            'model_id' => ['required', 'uuid', Rule::exists('models', 'id')],
            'dataset_id' => ['nullable', 'uuid', Rule::exists('datasets', 'id')],
            'parameters' => ['nullable', 'array'],
            'generate_tiles' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    protected function passedValidation(): void
    {
        $payloadSize = strlen((string) json_encode($this->all()));
        $limitKb = max((int) config('api.payload_limits.predict', 10_240), 1);

        if ($payloadSize > $limitKb * 1024) {
            throw ValidationException::withMessages([
                'payload' => [sprintf('Payload exceeds maximum allowed size of %dKB.', $limitKb)],
            ]);
        }
    }

    public function generateTiles(): bool
    {
        return (bool) $this->boolean('generate_tiles');
    }
}
