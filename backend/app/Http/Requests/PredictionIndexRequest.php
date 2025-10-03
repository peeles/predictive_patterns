<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PredictionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string'],
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['nullable'],
            'filter.model_id' => ['nullable', 'string'],
            'filter.from' => ['nullable', 'date'],
            'filter.to' => ['nullable', 'date'],
        ];
    }
}
