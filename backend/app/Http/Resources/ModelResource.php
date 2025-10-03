<?php

namespace App\Http\Resources;

use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Models\TrainingRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ModelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'dataset_id' => $this->dataset_id,
            'status' => $this->status instanceof ModelStatus
                ? $this->status->value
                : (string) $this->status,
            'version' => $this->version,
            'tag' => $this->tag,
            'area' => $this->area,
            'hyperparameters' => $this->hyperparameters,
            'metadata' => $this->metadata,
            'metrics' => $this->metrics,
            'trained_at' => optional($this->trained_at)->toIso8601String(),
            'training_runs' => $this->resolveTrainingRuns(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveTrainingRuns(): array
    {
        if (! $this->resource instanceof Model || ! $this->resource->relationLoaded('trainingRuns')) {
            return [];
        }

        $relation = $this->resource->getRelation('trainingRuns');
        $runs = Collection::make($relation);

        return $runs
            ->filter(static fn ($run) => $run instanceof TrainingRun)
            ->map(static fn (TrainingRun $run) => [
                'id' => $run->id,
                'status' => $run->status instanceof TrainingStatus
                    ? $run->status->value
                    : (string) $run->status,
                'queued_at' => optional($run->queued_at)->toIso8601String(),
                'started_at' => optional($run->started_at)->toIso8601String(),
                'finished_at' => optional($run->finished_at)->toIso8601String(),
                'metrics' => $run->metrics,
            ])
            ->values()
            ->all();
    }
}
