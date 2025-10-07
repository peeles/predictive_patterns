<?php

namespace App\Http\Resources;

use App\Enums\PredictionStatus;
use App\Models\Prediction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PredictionResource extends JsonResource
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Prediction $prediction */
        $prediction = $this->resource;

        return [
            'id' => $prediction->id,
            'model_id' => $prediction->model_id,
            'dataset_id' => $prediction->dataset_id,
            'status' => $prediction->status instanceof PredictionStatus
                ? $prediction->status->value
                : (string) $prediction->status,
            'parameters' => $prediction->parameters,
            'metadata' => $prediction->metadata,
            'error_message' => $prediction->error_message,
            'queued_at' => optional($prediction->queued_at)->toIso8601String(),
            'started_at' => optional($prediction->started_at)->toIso8601String(),
            'finished_at' => optional($prediction->finished_at)->toIso8601String(),
            'created_at' => optional($prediction->created_at)->toIso8601String(),
            'model' => $prediction->relationLoaded('model') && $prediction->model ? [
                'id' => $prediction->model->id,
                'name' => $prediction->model->name,
                'version' => $prediction->model->version,
            ] : null,
        ];
    }
}
