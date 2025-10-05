<?php

namespace App\Services;

use App\Events\DatasetStatusUpdated;
use App\Jobs\CompleteDatasetIngestion;
use App\Models\Dataset;
use App\Services\Datasets\DatasetRepository;
use App\Services\Datasets\FeatureGenerator;
use App\Services\Datasets\SchemaMapper;
use App\Support\Broadcasting\BroadcastDispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DatasetProcessingService
{
    public function __construct(
        private readonly DatasetPreviewService $previewService,
        private readonly FeatureGenerator $featureGenerator,
        private readonly SchemaMapper $schemaMapper,
        private readonly DatasetRepository $datasetRepository,
    ) {
    }

    /**
     * Finalise the dataset after ingestion, generating a preview and populating features if a schema mapping is provided.
     *
     * @param Dataset $dataset
     * @param array<string, string> $schemaMapping
     * @param array<string, mixed> $additionalMetadata
     *
     * @return Dataset
     */
    public function finalise(Dataset $dataset, array $schemaMapping = [], array $additionalMetadata = []): Dataset
    {
        if ($additionalMetadata !== []) {
            $dataset->metadata = $this->datasetRepository->mergeMetadata($dataset->metadata, $additionalMetadata);
        }

        $preview = $this->generatePreview($dataset);

        if ($preview !== null) {
            $metadata = array_filter([
                'row_count' => $preview['row_count'] ?? 0,
                'preview_rows' => $preview['preview_rows'] ?? [],
                'headers' => $preview['headers'] ?? [],
            ], static function ($value) {
                if (is_array($value)) {
                    return $value !== [];
                }

                return $value !== null;
            });

            if ($schemaMapping !== []) {
                $metadata['schema_mapping'] = $schemaMapping;
                $metadata['derived_features'] = $this->schemaMapper->summariseDerivedFeatures(
                    $schemaMapping,
                    $metadata['headers'] ?? [],
                    $metadata['preview_rows'] ?? []
                );
            }

            $dataset->metadata = $this->datasetRepository->mergeMetadata($dataset->metadata, $metadata);
        } elseif ($schemaMapping !== []) {
            $dataset->metadata = $this->datasetRepository->mergeMetadata($dataset->metadata, [
                'schema_mapping' => $schemaMapping,
                'derived_features' => $this->schemaMapper->summariseDerivedFeatures($schemaMapping, [], []),
            ]);
        }

        $this->datasetRepository->markAsReady($dataset);

        if ($schemaMapping !== [] && $dataset->file_path !== null && $this->datasetRepository->featuresTableExists()) {
            $dataset->refresh();
            $this->featureGenerator->populateFromMapping($dataset, $schemaMapping);
        }

        $this->datasetRepository->refreshFeatureCount($dataset);

        $event = DatasetStatusUpdated::fromDataset($dataset, 1.0);
        BroadcastDispatcher::dispatch($event, [
            'dataset_id' => $event->datasetId,
            'status' => $event->status,
        ]);

        return $dataset;
    }

    /**
     * Dispatch an asynchronous job to finalise the dataset after the HTTP request completes.
     *
     * @param Dataset $dataset
     * @param array<string, string> $schemaMapping
     * @param array<string, mixed> $additionalMetadata
     *
     * @return Dataset
     */
    public function queueFinalise(Dataset $dataset, array $schemaMapping = [], array $additionalMetadata = []): Dataset
    {
        if ($additionalMetadata !== []) {
            $dataset->metadata = $this->datasetRepository->mergeMetadata($dataset->metadata, $additionalMetadata);
            $dataset->save();
        }

        CompleteDatasetIngestion::dispatch(
            $dataset->getKey(),
            $schemaMapping,
            $additionalMetadata
        );

        $event = DatasetStatusUpdated::fromDataset($dataset, 0.0);
        BroadcastDispatcher::dispatch($event, [
            'dataset_id' => $event->datasetId,
            'status' => $event->status,
        ]);

        return $dataset;
    }

    /**
     * Mark the dataset as failed and broadcast the failure event.
     */
    public function markAsFailed(Dataset $dataset, ?Throwable $exception = null): void
    {
        $message = $exception?->getMessage() ?: 'Dataset processing failed.';

        $this->datasetRepository->markAsFailed($dataset, $message);
    }

    /**
     * Merge existing metadata with additional metadata, overriding existing keys with new values.
     *
     * @param mixed $existing
     * @param array $additional
     *
     * @return array
     */
    public function mergeMetadata(mixed $existing, array $additional): array
    {
        return $this->datasetRepository->mergeMetadata($existing, $additional);
    }

    /**
     * Generate a preview for the dataset using the DatasetPreviewService.
     *
     * @param Dataset $dataset
     *
     * @return array|null
     */
    private function generatePreview(Dataset $dataset): ?array
    {
        $path = $dataset->file_path;

        if ($path === null) {
            return null;
        }

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        try {
            return $this->previewService->summarise(
                Storage::disk('local')->path($path),
                $dataset->mime_type
            );
        } catch (Throwable $exception) {
            Log::warning('Failed to generate dataset preview', [
                'dataset_id' => $dataset->id,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }
}
