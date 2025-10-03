<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HexAggregationRequest extends FormRequest
{
    private const BBOX_PATTERN = '/^\s*[-+]?\d+(?:\.\d+)?\s*,\s*[-+]?\d+(?:\.\d+)?\s*,\s*[-+]?\d+(?:\.\d+)?\s*,\s*[-+]?\d+(?:\.\d+)?\s*$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bbox' => ['required', 'string', 'regex:' . self::BBOX_PATTERN],
            'resolution' => ['sometimes', 'integer', Rule::in([6, 7, 8])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'crime_type' => ['nullable', 'string'],
        ];
    }

    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);

        $validated['resolution'] = (int) ($validated['resolution'] ?? 7);

        return $validated;
    }

}
