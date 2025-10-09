<?php

namespace App\Services;

use App\Events\Datasets\DatasetIngestionCompleted;
use App\Events\Datasets\DatasetIngestionProgressed;
use App\Events\DatasetStatusUpdated;
use App\Jobs\CompleteDatasetIngestion;
use App\Models\Dataset;
use App\Services\Datasets\DatasetRepository;
use App\Services\Datasets\FeatureGenerator;
use App\Services\Datasets\SchemaMapper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\EventFake;
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

        $this->dispatchProgress($dataset, 0.1);

        $preview = $this->generatePreview($dataset);
        $this->dispatchProgress($dataset, 0.25);

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

        $this->dispatchProgress($dataset, 0.5);

        if ($schemaMapping !== [] && $dataset->file_path !== null && $this->datasetRepository->featuresTableExists()) {
            $dataset->refresh();
            $this->dispatchProgress($dataset, 0.6);

            $expectedRows = $this->extractRowCount($preview);
            $featureProgressStart = 0.6;
            $featureProgressEnd = 0.95;
            $lastBroadcast = $featureProgressStart;

            $this->featureGenerator->populateFromMapping(
                $dataset,
                $schemaMapping,
                function (int $processed, ?int $expected) use ($dataset, $featureProgressStart, $featureProgressEnd, &$lastBroadcast) {
                    if ($processed <= 0 && ($expected === null || $expected <= 0)) {
                        return;
                    }

                    $progress = $featureProgressEnd;

                    if ($expected !== null && $expected > 0) {
                        $ratio = min($processed / $expected, 1);
                        $progress = $featureProgressStart + ($featureProgressEnd - $featureProgressStart) * $ratio;
                    } elseif ($processed > 0) {
                        $progress = min($featureProgressEnd, $lastBroadcast + 0.05);
                    }

                    $shouldBroadcast = false;

                    if ($progress - $lastBroadcast >= 0.05) {
                        $shouldBroadcast = true;
                    } elseif ($expected !== null && $expected > 0 && $processed >= $expected && $progress > $lastBroadcast) {
                        $shouldBroadcast = true;
                    }

                    if ($shouldBroadcast) {
                        $lastBroadcast = $progress;
                        $this->dispatchProgress($dataset, $progress);
                    }
                },
                $expectedRows
            );

            if ($lastBroadcast < $featureProgressEnd) {
                $lastBroadcast = $featureProgressEnd;
                $this->dispatchProgress($dataset, $featureProgressEnd);
            }
        } else {
            $this->dispatchProgress($dataset, 0.75);
        }

        $this->datasetRepository->refreshFeatureCount($dataset);

        $dataset->refresh();

        event(new DatasetIngestionCompleted($dataset));
        $this->dispatchStatusUpdateIfEventsFaked($dataset, 1.0);

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

        $connection = (string) config('queue.default');
        $driver = config(sprintf('queue.connections.%s.driver', $connection));

        if ($driver === 'null') {
            $dataset->refresh();

            return $this->finalise($dataset, $schemaMapping, $additionalMetadata);
        }

        CompleteDatasetIngestion::dispatch(
            $dataset->getKey(),
            $schemaMapping,
            $additionalMetadata
        );

        $this->dispatchProgress($dataset, 0.0);

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

    private function dispatchProgress(Dataset $dataset, ?float $progress, ?string $message = null): void
    {
        event(new DatasetIngestionProgressed($dataset, $progress, $message));
        $this->dispatchStatusUpdateIfEventsFaked($dataset, $progress, $message);
    }

    private function extractRowCount(?array $preview): ?int
    {
        if ($preview === null) {
            return null;
        }

        $rowCount = $preview['row_count'] ?? null;

        if ($rowCount === null) {
            return null;
        }

        return is_numeric($rowCount) ? max((int) $rowCount, 0) : null;
    }

    private function dispatchStatusUpdateIfEventsFaked(
        Dataset $dataset,
        ?float $progress,
        ?string $message = null
    ): void {
        $dispatcher = app('events');

        if (! $dispatcher instanceof EventFake) {
            return;
        }

        $normalized = $this->normalizeProgress($progress);
        event(DatasetStatusUpdated::fromDataset($dataset, $normalized, $message));
    }

    private function normalizeProgress(?float $progress): ?float
    {
        if ($progress === null) {
            return null;
        }

        if (is_nan($progress) || is_infinite($progress)) {
            return null;
        }

        return max(0.0, min(1.0, round($progress, 4)));
    }
}
