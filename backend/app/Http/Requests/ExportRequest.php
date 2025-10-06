<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bbox' => ['sometimes', 'string'],
            'resolution' => ['sometimes', 'integer', 'min:0'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'dataset_type' => ['sometimes', 'string'],
            'severity' => ['sometimes', 'string'],
            'time_of_day_start' => ['sometimes', 'integer', 'between:0,23'],
            'time_of_day_end' => ['sometimes', 'integer', 'between:0,23'],
            'confidence_level' => ['sometimes', 'numeric', 'gt:0', 'lt:1'],
            'format' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string'],
        ];
    }

    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);

        if (array_key_exists('resolution', $validated)) {
            $validated['resolution'] = $validated['resolution'] !== null
                ? (int) $validated['resolution']
                : null;
        }

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
