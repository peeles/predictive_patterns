<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DatasetIndexRequest extends FormRequest
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
            'filter.status' => ['nullable', 'string'],
            'filter.source_type' => ['nullable', 'string'],
            'filter.search' => ['nullable', 'string'],
        ];
    }
}
