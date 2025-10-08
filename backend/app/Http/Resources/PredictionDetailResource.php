<?php

namespace App\Http\Resources;

use App\Enums\PredictionOutputFormat;
use App\Models\PredictionOutput;
use App\Models\ShapValue;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PredictionDetailResource extends PredictionResource
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        $prediction = $this->resource;

        return array_merge(parent::toArray($request), [
            'outputs' => Collection::make($prediction->outputs)
                ->values()
                ->map(static fn (PredictionOutput $output) => [
                    'id' => $output->id,
                    'format' => $output->format instanceof PredictionOutputFormat
                        ? $output->format->value
                        : (string) $output->format,
                    'payload' => $output->payload,
                    'tileset_path' => $output->tileset_path,
                    'created_at' => optional($output->created_at)->toIso8601String(),
                ])->all(),
            'shap_values' => Collection::make($prediction->shapValues)
                ->sortByDesc(static fn (ShapValue $value) => abs((float) $value->value))
                ->values()
                ->map(static fn (ShapValue $value) => [
                    'id' => $value->id,
                    'feature_name' => $value->feature_name,
                    'name' => $value->feature_name,
                    'contribution' => (float) $value->value,
                    'details' => $value->details,
                    'created_at' => optional($value->created_at)->toIso8601String(),
                ])->all(),
        ]);
    }
}
