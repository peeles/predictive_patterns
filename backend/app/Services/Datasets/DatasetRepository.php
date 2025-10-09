<?php

namespace App\Services\Datasets;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use Illuminate\Support\Facades\Schema;

class DatasetRepository
{
    private ?bool $featuresTableExists = null;

    /**
     * Merge existing metadata with additional metadata, overriding existing keys with new values.
     *
     * @param mixed $existing
     * @param array $additional
     *
     * @return array<string, mixed>
     */
    public function mergeMetadata(mixed $existing, array $additional): array
    {
        $metadata = is_array($existing) ? $existing : [];

        foreach ($additional as $key => $value) {
            if (is_array($value) && $value === []) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            $metadata[$key] = $value;
        }

        return $metadata;
    }

    public function markAsReady(Dataset $dataset): void
    {
        $dataset->status = DatasetStatus::Ready;
        $dataset->ingested_at = now();
        $dataset->save();
    }

    public function markAsFailed(Dataset $dataset, string $message): void
    {
        $dataset->status = DatasetStatus::Failed;
        $dataset->metadata = $this->mergeMetadata($dataset->metadata, [
            'ingest_error' => $message,
        ]);
        $dataset->ingested_at = null;
        $dataset->save();

    }

    public function featuresTableExists(): bool
    {
        if ($this->featuresTableExists !== null) {
            return $this->featuresTableExists;
        }

        return $this->featuresTableExists = Schema::hasTable('features');
    }

    public function refreshFeatureCount(Dataset $dataset): void
    {
        if (! $this->featuresTableExists()) {
            return;
        }

        $dataset->loadCount('features');
    }
}
