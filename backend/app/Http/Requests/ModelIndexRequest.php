<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ModelIndexRequest extends FormRequest
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
            'filter.tag' => ['nullable', 'string'],
            'filter.area' => ['nullable', 'string'],
            'filter.status' => ['nullable', 'string'],
        ];
    }
}
