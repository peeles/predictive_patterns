<?php

namespace App\Http\Requests;

use App\Models\PredictiveModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ModelStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function model(): PredictiveModel
    {
        $modelId = (string) $this->route('id');

        $model = PredictiveModel::query()->find($modelId);

        if ($model === null) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Model not found.',
                    'errors' => [
                        'id' => ['The specified model could not be found.'],
                    ],
                ], 404)
            );
        }

        return $model;
    }
}
