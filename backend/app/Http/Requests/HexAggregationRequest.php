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
            'dataset_type' => ['nullable', 'string'],
            'severity' => ['nullable', 'string'],
            'time_of_day_start' => ['nullable', 'integer', 'between:0,23'],
            'time_of_day_end' => ['nullable', 'integer', 'between:0,23'],
            'confidence_level' => ['nullable', 'numeric', 'gt:0', 'lt:1'],
        ];
    }

    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);

        $validated['resolution'] = (int) ($validated['resolution'] ?? 7);

        foreach (['time_of_day_start', 'time_of_day_end'] as $timeKey) {
            if (array_key_exists($timeKey, $validated)) {
                $validated[$timeKey] = $validated[$timeKey] !== null
                    ? (int) $validated[$timeKey]
                    : null;
            }
        }

        if (array_key_exists('confidence_level', $validated)) {
            $validated['confidence_level'] = $validated['confidence_level'] !== null
                ? (float) $validated['confidence_level']
                : null;
        }

        return $validated;
    }

}
