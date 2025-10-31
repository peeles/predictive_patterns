<?php

declare(strict_types=1);

namespace App\Services\MachineLearning;

use App\Models\Dataset;
use App\Services\Dataset\ColumnMapper;
use App\Support\DatasetRowBuffer;
use App\Support\DatasetRowPreprocessor;
use App\Support\Phpml\ImputerFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Normalizer;
use RuntimeException;

/**
 * Service responsible for preparing and preprocessing training data.
 *
 * Handles dataset loading, validation, splitting, sampling, and transformation
 * into ML-ready format with proper memory management.
 */
class TrainingDataPreparationService
{
    private const MEMORY_THRESHOLD_BYTES = 500 * 1024 * 1024; // 500MB
    private const SAMPLING_RATIO = 0.5;

    public function __construct(
        private readonly ColumnMapper $columnMapper,
        private readonly DataPreprocessor $dataPreprocessor,
    ) {
    }

    /**
     * Prepare dataset for training with full preprocessing pipeline.
     *
     * @param Dataset $dataset Dataset to prepare
     * @param array<string, mixed> $hyperparameters Resolved hyperparameters
     *
     * @return array{
     *     train_samples: list<list<float>>,
     *     train_labels: list<int>,
     *     validation_samples: list<list<float>>,
     *     validation_labels: list<int>,
     *     feature_names: list<string>,
     *     feature_means: list<float>,
     *     feature_std_devs: list<float>,
     *     categories: list<string>,
     *     category_overflowed: bool,
     *     imputer: Imputer,
     *     normalizer: Normalizer
     * }
     *
     * @throws RuntimeException
     */
    public function prepare(Dataset $dataset, array $hyperparameters): array
    {
        if ($dataset->file_path === null) {
            throw new RuntimeException('Dataset is missing a file path.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($dataset->file_path)) {
            throw new RuntimeException(sprintf('Dataset file "%s" was not found.', $dataset->file_path));
        }

        $path = $disk->path($dataset->file_path);

        // Log dataset size for monitoring
        $fileSize = filesize($path);
        Log::info('Loading dataset for training', [
            'dataset_id' => $dataset->id,
            'file_size' => $fileSize,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2),
        ]);

        $columnMap = $this->columnMapper->resolveColumnMap($dataset);
        $prepared = DatasetRowPreprocessor::prepareTrainingData($path, $columnMap);
        $buffer = $prepared['buffer'];

        if (! $buffer instanceof DatasetRowBuffer || $buffer->count() === 0) {
            throw new RuntimeException('Dataset file does not contain any rows.');
        }

        $validationSplit = (float) $hyperparameters['validation_split'];
        $splits = $this->dataPreprocessor->splitDataset($buffer, $validationSplit);

        // Free the original buffer after splitting
        unset($buffer, $prepared['buffer']);

        $trainRaw = $this->dataPreprocessor->bufferToSamples($splits['train_buffer']);
        unset($splits['train_buffer']);

        // Sample if memory usage is high
        if (memory_get_usage(true) > self::MEMORY_THRESHOLD_BYTES) {
            $trainRaw = $this->sampleDataset($trainRaw);
        }

        $validationRaw = $this->dataPreprocessor->bufferToSamples($splits['validation_buffer']);
        unset($splits['validation_buffer']);
        gc_collect_cycles();

        if ($trainRaw['samples'] === [] || $trainRaw['labels'] === []) {
            throw new RuntimeException('Cannot train a model without features.');
        }

        // Apply preprocessing transformations
        $imputer = ImputerFactory::create($hyperparameters['imputation_strategy']);
        $imputer->fit($trainRaw['samples']);
        $imputer->transform($trainRaw['samples']);
        $imputer->transform($validationRaw['samples']);

        $trainSamples = $this->dataPreprocessor->applyStandardisation(
            $trainRaw['samples'],
            $splits['means'],
            $splits['std_devs']
        );

        $validationSamples = $this->dataPreprocessor->applyStandardisation(
            $validationRaw['samples'],
            $splits['means'],
            $splits['std_devs']
        );

        $normalizer = new Normalizer($hyperparameters['normalization']);
        $this->dataPreprocessor->normaliseSamplesSafely($normalizer, $trainSamples);
        $this->dataPreprocessor->normaliseSamplesSafely($normalizer, $validationSamples);

        gc_collect_cycles();

        return [
            'train_samples' => $trainSamples,
            'train_labels' => $trainRaw['labels'],
            'validation_samples' => $validationSamples,
            'validation_labels' => $validationRaw['labels'],
            'feature_names' => $prepared['feature_names'],
            'feature_means' => $splits['means'],
            'feature_std_devs' => $splits['std_devs'],
            'categories' => $prepared['categories'],
            'category_overflowed' => $prepared['category_overflowed'],
            'imputer' => $imputer,
            'normalizer' => $normalizer,
        ];
    }

    /**
     * Sample dataset when memory usage is high.
     *
     * @param array{samples: list<list<float>>, labels: list<int>} $data
     *
     * @return array{samples: list<list<float>>, labels: list<int>}
     */
    private function sampleDataset(array $data): array
    {
        $memoryBefore = memory_get_usage(true);

        Log::info('Dataset too large, sampling to 50%', [
            'current_memory' => $memoryBefore,
            'sample_count' => count($data['samples']),
        ]);

        $sampled = $this->dataPreprocessor->sampleDataset(
            $data['samples'],
            $data['labels'],
            self::SAMPLING_RATIO
        );

        // Explicitly free memory from original large dataset
        unset($data);
        gc_collect_cycles();

        Log::info('Memory freed after sampling', [
            'memory_before' => $memoryBefore,
            'memory_after' => memory_get_usage(true),
            'memory_freed' => $memoryBefore - memory_get_usage(true),
        ]);

        return $sampled;
    }
}
