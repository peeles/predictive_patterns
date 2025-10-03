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
            'crime_type' => ['sometimes', 'string'],
            'format' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string'],
        ];
    }
}
