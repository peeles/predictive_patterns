<?php

namespace App\Http\Resources;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class DatasetResource extends JsonResource
{
    private static ?bool $featuresTableExists = null;

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
            'description' => $this->description,
            'source_type' => $this->source_type,
            'source_uri' => $this->source_uri,
            'file_path' => $this->file_path,
            'checksum' => $this->checksum,
            'mime_type' => $this->mime_type,
            'schema' => $this->schema_mapping ?? [],
            'metadata' => $this->metadata,
            'features_count' => self::resolveFeaturesCount($this->resource),
            'status' => $this->status instanceof DatasetStatus
                ? $this->status->value
                : (string) $this->status,
            'ingested_at' => optional($this->ingested_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    public static function featuresTableExists(): bool
    {
        if (self::$featuresTableExists !== null) {
            return self::$featuresTableExists;
        }

        return self::$featuresTableExists = Schema::hasTable('features');
    }

    /**
     *
     * @param mixed $resource
     * @return int
     */
    private static function resolveFeaturesCount(mixed $resource): int
    {
        $metadata = $resource instanceof Dataset
            ? ($resource->metadata ?? [])
            : (array) ($resource['metadata'] ?? []);
        $metadataCount = (int) Arr::get($metadata, 'row_count', 0);

        if (! $resource instanceof Dataset) {
            return $metadataCount;
        }

        if (! self::featuresTableExists()) {
            return $metadataCount;
        }

        $count = $resource->getAttribute('features_count');

        if ($count !== null && (int) $count > 0) {
            return (int) $count;
        }

        $relationCount = (int) $resource->features()->count();

        if ($relationCount > 0) {
            return $relationCount;
        }

        return $metadataCount;
    }
}
