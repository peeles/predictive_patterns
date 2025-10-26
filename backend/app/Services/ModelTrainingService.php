<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\Dataset\ColumnMapper;
use App\Services\MachineLearning\ClassifierFactory;
use App\Services\MachineLearning\DataPreprocessor;
use App\Services\MachineLearning\FeatureImportanceCalculator;
use App\Services\MachineLearning\GridSearchService;
use App\Services\MachineLearning\HyperparameterResolver;
use App\Services\MachineLearning\ImputerResolver;
use App\Services\MachineLearning\MetricsFormatter;
use App\Services\MachineLearning\NormalizerResolver;
use App\Support\DatasetRowBuffer;
use App\Support\DatasetRowPreprocessor;
use App\Support\Metrics\ClassificationReportGenerator;
use App\Support\Phpml\ImputerFactory;
use App\Support\ProbabilityScoreExtractor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Phpml\Classification\Linear\LogisticRegression as PhpmlLogisticRegression;
use Phpml\Exception\FileException;
use Phpml\Exception\InvalidOperationException;
use Phpml\Exception\LibsvmCommandException;
use Phpml\Exception\NormalizerException;
use Phpml\Exception\SerializeException;
use Phpml\ModelManager;
use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Normalizer;
use ReflectionClass;
use RuntimeException;

class ModelTrainingService
{
    private const MIN_FEATURE_VARIANCE = 1e-9;
    private const MAX_FEATURE_MAGNITUDE = 1_000_000.0;

    public function __construct(
        private readonly ColumnMapper $columnMapper = new ColumnMapper(),
        private readonly NormalizerResolver $normalizerResolver = new NormalizerResolver(),
        private readonly ImputerResolver $imputerResolver = new ImputerResolver(),
        private readonly HyperparameterResolver $hyperparameterResolver = new HyperparameterResolver(),
        private readonly ClassifierFactory $classifierFactory = new ClassifierFactory(),
        private readonly DataPreprocessor $dataPreprocessor = new DataPreprocessor(),
        private readonly GridSearchService $gridSearchService = new GridSearchService(),
        private readonly MetricsFormatter $metricsFormatter = new MetricsFormatter(),
        private readonly FeatureImportanceCalculator $featureImportanceCalculator = new FeatureImportanceCalculator(),
    ) {
    }

    /**
     * Train a predictive model using the specified dataset and hyperparameters.
     *
     * @param TrainingRun $run
     * @param PredictiveModel $model
     * @param array<string, mixed> $hyperparameters
     * @param callable|null $progressCallback Callback signature: function (float $progress, ?string $message = null): void
     *
     * @return array{
     *     metrics: array<string, mixed>,
     *     artifact_path: string,
     *     version: string,
     *     metadata: array<string, mixed>,
     *     hyperparameters: array<string, mixed>
     * }
     *
     * @throws RuntimeException
     * @throws FileException
     * @throws SerializeException|NormalizerException
     * @throws InvalidOperationException
     * @throws LibsvmCommandException
     */
    public function train(
        TrainingRun $run,
        PredictiveModel $model,
        array $hyperparameters = [],
        ?callable $progressCallback = null,
    ): array {
        $dataset = $model->dataset;

        if (! $dataset instanceof Dataset) {
            throw new RuntimeException('Predictive model does not have an associated dataset.');
        }

        if ($dataset->file_path === null) {
            throw new RuntimeException('Dataset is missing a file path.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($dataset->file_path)) {
            throw new RuntimeException(sprintf('Dataset file "%s" was not found.', $dataset->file_path));
        }

        $path = $disk->path($dataset->file_path);

        $this->notifyProgress($progressCallback, 10.0, 'Analyzing dataset schema');

        $columnMap = $this->columnMapper->resolveColumnMap($dataset);

        // Log dataset size for monitoring
        $fileSize = filesize($path);
        Log::info('Loading dataset for training', [
            'dataset_id' => $dataset->id,
            'file_size' => $fileSize,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2),
        ]);

        $prepared = DatasetRowPreprocessor::prepareTrainingData($path, $columnMap);
        $buffer = $prepared['buffer'];

        if (! $buffer instanceof DatasetRowBuffer || $buffer->count() === 0) {
            throw new RuntimeException('Dataset file does not contain any rows.');
        }

        $resolvedHyperparameters = $this->hyperparameterResolver->resolve($hyperparameters);

        $this->notifyProgress($progressCallback, 30.0, 'Buffered dataset rows for streaming');

        $splits = $this->dataPreprocessor->splitDataset($buffer, $resolvedHyperparameters['validation_split']);

        // Free the original buffer after splitting
        unset($buffer, $prepared['buffer']);

        $this->notifyProgress($progressCallback, 40.0, 'Computed training splits and statistics');

        $trainRaw = $this->dataPreprocessor->bufferToSamples($splits['train_buffer']);

        // Free train buffer memory immediately
        unset($splits['train_buffer']);

        if (memory_get_usage(true) > 500 * 1024 * 1024) { // If using > 500MB
            $memoryBefore = memory_get_usage(true);

            Log::info('Dataset too large, sampling to 50%', [
                'current_memory' => $memoryBefore,
                'sample_count' => count($trainRaw['samples']),
            ]);

            // Keep references to originals for cleanup
            $originalSamples = $trainRaw['samples'];
            $originalLabels = $trainRaw['labels'];

            $sampled = $this->dataPreprocessor->sampleDataset(
                $originalSamples,
                $originalLabels,
                0.5
            );

            $trainRaw = $sampled;

            // Explicitly free memory from original large dataset
            unset($originalSamples, $originalLabels, $sampled);
            gc_collect_cycles();

            Log::info('Memory freed after sampling', [
                'memory_before' => $memoryBefore,
                'memory_after' => memory_get_usage(true),
                'memory_freed' => $memoryBefore - memory_get_usage(true),
            ]);
        }

        $validationRaw = $this->dataPreprocessor->bufferToSamples($splits['validation_buffer']);

        // Free validation buffer memory
        unset($splits['validation_buffer']);
        gc_collect_cycles();

        if ($trainRaw['samples'] === [] || $trainRaw['labels'] === []) {
            throw new RuntimeException('Cannot train a model without features.');
        }

        $progressNotifier = $this->buildProgressNotifier($progressCallback, $resolvedHyperparameters);

        $this->notifyProgress($progressCallback, 50.0, 'Running cross validation grid search');

        $gridSearch = $this->gridSearchService->search(
            $trainRaw['samples'],
            $trainRaw['labels'],
            $resolvedHyperparameters,
            $progressNotifier
        );

        // Free memory from grid search classifiers
        gc_collect_cycles();

        $bestParams = $gridSearch['best_hyperparameters'];
        $cvMetrics = $gridSearch['metrics'];
        $finalHyperparameters = array_merge($resolvedHyperparameters, $bestParams);
        unset($finalHyperparameters['search_grid']);

        $trainSamples = $trainRaw['samples'];
        $trainLabels = $trainRaw['labels'];
        $validationSamples = $validationRaw['samples'];
        $validationLabels = $validationRaw['labels'];

        $imputer = ImputerFactory::create($resolvedHyperparameters['imputation_strategy']);
        $imputer->fit($trainSamples);
        $imputer->transform($trainSamples);
        $imputer->transform($validationSamples);

        $trainSamples = $this->dataPreprocessor->applyStandardisation($trainSamples, $splits['means'], $splits['std_devs']);
        $validationSamples = $this->dataPreprocessor->applyStandardisation($validationSamples, $splits['means'], $splits['std_devs']);

        $normalizer = new Normalizer($resolvedHyperparameters['normalization']);
        $this->dataPreprocessor->normaliseSamplesSafely($normalizer, $trainSamples);
        $this->dataPreprocessor->normaliseSamplesSafely($normalizer, $validationSamples);

        // Free memory after preprocessing
        gc_collect_cycles();

        $classifier = $this->classifierFactory->create(
            $resolvedHyperparameters['model_type'],
            $bestParams,
            $resolvedHyperparameters,
            $progressNotifier
        );

        $this->notifyProgress($progressCallback, 62.0, 'Training selected algorithm');

        $classifier->train($trainSamples, $trainLabels);

        // Free memory after classifier training
        gc_collect_cycles();

        $this->notifyProgress($progressCallback, 75.0, 'Evaluating validation dataset');

        $predicted = $classifier->predict($validationSamples);
        $rawProbabilities = method_exists($classifier, 'predictProbabilities')
            ? (array) $classifier->predictProbabilities($validationSamples)
            : array_map(static fn (int $value): float => $value >= 1 ? 1.0 : 0.0, $predicted);
        $probabilities = ProbabilityScoreExtractor::extractList($rawProbabilities);

        $classification = ClassificationReportGenerator::generate($validationLabels, $predicted);
        $report = $classification['report'];
        $confusion = $classification['confusion'];
        $metrics = $this->metricsFormatter->format($report, $confusion, $probabilities, $validationLabels);

        // Free memory from validation predictions
        unset($predicted, $rawProbabilities, $probabilities, $classification, $report, $confusion);
        gc_collect_cycles();

        $this->notifyProgress($progressCallback, 82.0, 'Computing feature importances');

        $featureImportances = $this->featureImportanceCalculator->calculate($trainSamples, $trainLabels, $prepared['feature_names']);

        // Free memory from training samples after feature importance calculation
        unset($trainSamples, $trainLabels, $validationSamples, $validationLabels);
        gc_collect_cycles();

        $this->notifyProgress($progressCallback, 87.0, 'Persisting trained model');

        $trainedAt = now();
        $version = $trainedAt->format('YmdHis');
        $artifactDirectory = sprintf('models/%s', $model->getKey());
        $artifactPath = sprintf('%s/%s.json', $artifactDirectory, $version);
        $modelFilePath = sprintf('%s/%s.model', $artifactDirectory, $version);

        $disk->makeDirectory($artifactDirectory);

        $artifact = [
            'model_id' => $model->getKey(),
            'training_run_id' => $run->getKey(),
            'trained_at' => $trainedAt->toIso8601String(),
            'model_type' => $resolvedHyperparameters['model_type'],
            'feature_names' => $prepared['feature_names'],
            'feature_means' => $splits['means'],
            'feature_std_devs' => $splits['std_devs'],
            'imputer' => [
                'strategy' => $this->imputerResolver->describeStrategy($resolvedHyperparameters['imputation_strategy']),
                'statistics' => $this->extractImputerStatistics($imputer),
            ],
            'categories' => $prepared['categories'],
            'category_overflowed' => $prepared['category_overflowed'],
            'hyperparameters' => $finalHyperparameters,
            'metrics' => $metrics,
            'grid_search' => $cvMetrics,
            'normalization' => [
                'type' => $this->normalizerResolver->describeType($resolvedHyperparameters['normalization']),
            ],
            'feature_importances' => $featureImportances,
            'model_file' => $modelFilePath,
        ];

        $disk->put($artifactPath, json_encode($artifact, JSON_PRETTY_PRINT));

        if ($classifier instanceof PhpmlLogisticRegression && method_exists($classifier, 'setProgressCallback')) {
            $classifier->setProgressCallback(null);
        }

        $modelManager = new ModelManager();
        $modelManager->saveToFile($classifier, $disk->path($modelFilePath));

        // Final cleanup after model persistence
        unset($classifier, $imputer, $normalizer, $artifact);
        gc_collect_cycles();

        $this->notifyProgress($progressCallback, 92.0, 'Recording training metadata');

        return [
            'metrics' => $metrics,
            'artifact_path' => $artifactPath,
            'version' => $version,
            'metadata' => ['artifact_path' => $artifactPath],
            'hyperparameters' => $finalHyperparameters,
        ];
    }

    /**
     * Extract statistics calculated by the imputer if available.
     *
     * @return list<float>
     */
    private function extractImputerStatistics(Imputer $imputer): array
    {
        $statistics = null;

        if (method_exists($imputer, 'getStatistics')) {
            $statistics = $imputer->getStatistics();
        }

        if ($statistics === null) {
            $statistics = $this->readObjectProperty($imputer);
        }

        if (! is_array($statistics)) {
            return [];
        }

        return array_values(array_map('floatval', $statistics));
    }

    /**
     * Attempt to read a property from an object, even if it is not publicly accessible.
     */
    private function readObjectProperty(object $object): mixed
    {
        $reflection = new ReflectionClass($object);

        if (! $reflection->hasProperty('statistics')) {
            return null;
        }

        $refProperty = $reflection->getProperty('statistics');

        return $refProperty->getValue($object);
    }

    /**
     * Notify progress via callback if provided.
     *
     * @param callable|null $callback
     * @param float $progress
     * @param string $message
     *
     * @return void
     */
    private function notifyProgress(?callable $callback, float $progress, string $message): void
    {
        if ($callback === null) {
            return;
        }

        $callback($progress, $message);
    }

    /**
     * Build a progress notifier callback for training iterations.
     *
     * @param callable|null $progressCallback
     * @param array $hyperparameters
     *
     * @return callable|null
     */
    private function buildProgressNotifier(?callable $progressCallback, array $hyperparameters): ?callable
    {
        if ($progressCallback === null) {
            return null;
        }

        $totalIterations = (int) $hyperparameters['iterations'];

        return function (int $iteration, int $total, ?float $loss = null) use ($progressCallback, $totalIterations): void {
            $total = $total > 0 ? $total : max(1, $totalIterations);
            $ratio = min(1.0, max(0.0, $iteration / $total));
            $progress = 55.0 + (20.0 * $ratio);
            $message = sprintf('Training model (%d of %d iterations)', $iteration, $total);

            if ($loss !== null) {
                $message .= sprintf(' | loss: %.6f', $loss);
            }

            $progressCallback($progress, $message);
        };
    }
}
