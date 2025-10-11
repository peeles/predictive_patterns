<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Support\DatasetRowBuffer;
use App\Support\DatasetRowPreprocessor;
use App\Support\FeatureBuffer;
use App\Support\Metrics\ClassificationReportGenerator;
use App\Support\Phpml\ImputerFactory;
use App\Support\ProbabilityScoreExtractor;
use ArgumentCountError;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Phpml\Classification\DecisionTree;
use Phpml\Classification\KNearestNeighbors;
use Phpml\Classification\Linear\LogisticRegression as PhpmlLogisticRegression;
use Phpml\Classification\SVC;
use Phpml\Classification\MLPClassifier;
use Phpml\Classification\NaiveBayes;
use Phpml\CrossValidation\RandomSplit;
use Phpml\Dataset\ArrayDataset;
use Phpml\Exception\FileException;
use Phpml\Exception\InvalidArgumentException;
use Phpml\Exception\InvalidOperationException;
use Phpml\Exception\LibsvmCommandException;
use Phpml\Exception\NormalizerException;
use Phpml\Exception\SerializeException;
use Phpml\ModelManager;
use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Normalizer;
use Phpml\SupportVectorMachine\Kernel;
use ReflectionClass;
use RuntimeException;
use Throwable;
use TypeError;

class ModelTrainingService
{
    private const MIN_FEATURE_VARIANCE = 1e-9;
    private const MAX_FEATURE_MAGNITUDE = 1_000_000.0;

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
     * @throws InvalidArgumentException
     * @throws InvalidOperationException
     * @throws LibsvmCommandException
     * @throws NormalizerException
     * @throws FileException
     * @throws SerializeException
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

        $columnMap = $this->resolveColumnMap($dataset);

        $chunkedService = new ChunkedModelTrainingService();
        if ($chunkedService->supportsChunkedTraining($dataset)) {
            $chunkSize = $chunkedService->getOptimalChunkSize($dataset);
            $this->notifyProgress($progressCallback, 5.0, "Large dataset detected. Using chunked training (chunk size: {$chunkSize})");

            // Log for monitoring
            Log::info('Using chunked training', [
                'dataset_id' => $dataset->id,
                'chunk_size' => $chunkSize,
                'file_size' => filesize($path),
            ]);
        }

        $prepared = DatasetRowPreprocessor::prepareTrainingData($path, $columnMap);
        $buffer = $prepared['buffer'];

        if (! $buffer instanceof DatasetRowBuffer || $buffer->count() === 0) {
            throw new RuntimeException('Dataset file does not contain any rows.');
        }

        $resolvedHyperparameters = $this->resolveHyperparameters($hyperparameters);

        $this->notifyProgress($progressCallback, 30.0, 'Buffered dataset rows for streaming');

        $splits = $this->splitBufferedEntries($buffer, $resolvedHyperparameters['validation_split']);

        $this->notifyProgress($progressCallback, 40.0, 'Computed training splits and statistics');

        $trainRaw = $this->bufferToSamples($splits['train_buffer']);

        if (memory_get_usage(true) > 500 * 1024 * 1024) { // If using > 500MB
            Log::info('Dataset too large, sampling to 50%', [
                'current_memory' => memory_get_usage(true),
                'sample_count' => count($trainRaw['samples']),
            ]);

            $sampled = $this->sampleDataset(
                $trainRaw['samples'],
                $trainRaw['labels'],
                0.5
            );

            $trainRaw = $sampled;
        }

        $validationRaw = $this->bufferToSamples($splits['validation_buffer']);

        if ($trainRaw['samples'] === [] || $trainRaw['labels'] === []) {
            throw new RuntimeException('Cannot train a model without features.');
        }

        $progressNotifier = $this->buildProgressNotifier($progressCallback, $resolvedHyperparameters);

        $this->notifyProgress($progressCallback, 50.0, 'Running cross validation grid search');

        $gridSearch = $this->performGridSearch(
            $trainRaw['samples'],
            $trainRaw['labels'],
            $resolvedHyperparameters,
            $progressNotifier
        );

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

        $trainSamples = $this->applyStandardization($trainSamples, $splits['means'], $splits['std_devs']);
        $validationSamples = $this->applyStandardization($validationSamples, $splits['means'], $splits['std_devs']);

        $normalizer = new Normalizer($resolvedHyperparameters['normalization']);
        $this->normalizeSamplesSafely($normalizer, $trainSamples);
        $this->normalizeSamplesSafely($normalizer, $validationSamples);

        $classifier = $this->createClassifier(
            $resolvedHyperparameters['model_type'],
            $bestParams,
            $resolvedHyperparameters,
            $progressNotifier
        );

        $this->notifyProgress($progressCallback, 62.0, 'Training selected algorithm');

        $classifier->train($trainSamples, $trainLabels);

        $this->notifyProgress($progressCallback, 75.0, 'Evaluating validation dataset');

        $predicted = $classifier->predict($validationSamples);
        $rawProbabilities = method_exists($classifier, 'predictProbabilities')
            ? (array) $classifier->predictProbabilities($validationSamples)
            : array_map(static fn (int $value): float => $value >= 1 ? 1.0 : 0.0, $predicted);
        $probabilities = ProbabilityScoreExtractor::extractList($rawProbabilities);

        $classification = ClassificationReportGenerator::generate($validationLabels, $predicted);
        $report = $classification['report'];
        $confusion = $classification['confusion'];
        $metrics = $this->formatMetrics($report, $confusion, $probabilities, $validationLabels);

        $this->notifyProgress($progressCallback, 82.0, 'Computing feature importances');

        $featureImportances = $this->computeFeatureImportances($trainSamples, $trainLabels, $prepared['feature_names']);

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
                'strategy' => $this->describeImputerStrategy($resolvedHyperparameters['imputation_strategy']),
                'statistics' => $this->extractImputerStatistics($imputer),
            ],
            'categories' => $prepared['categories'],
            'category_overflowed' => $prepared['category_overflowed'],
            'hyperparameters' => $finalHyperparameters,
            'metrics' => $metrics,
            'grid_search' => $cvMetrics,
            'normalization' => [
                'type' => $this->describeNormalizerType($resolvedHyperparameters['normalization']),
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
     * Notify progress via callback if provided.
     *
     * @return array<string, string>
     */
    private function resolveColumnMap(Dataset $dataset): array
    {
        $mapping = is_array($dataset->schema_mapping) ? $dataset->schema_mapping : [];

        return [
            'timestamp' => $this->resolveMappedColumn($mapping, 'timestamp', 'timestamp'),
            'latitude' => $this->resolveMappedColumn($mapping, 'latitude', 'latitude'),
            'longitude' => $this->resolveMappedColumn($mapping, 'longitude', 'longitude'),
            'category' => $this->resolveMappedColumn($mapping, 'category', 'category'),
            'risk_score' => $this->resolveMappedColumn($mapping, 'risk', 'risk_score'),
            'label' => $this->resolveMappedColumn($mapping, 'label', 'label'),
        ];
    }

    /**
     * Notify progress via callback if provided.
     *
     * @param array<string, mixed> $mapping
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    private function resolveMappedColumn(array $mapping, string $key, string $default): string
    {
        $value = $mapping[$key] ?? $default;

        if (! is_string($value) || trim($value) === '') {
            $value = $default;
        }

        $normalized = $this->normalizeColumnName($value);

        if ($normalized === '') {
            $normalized = $this->normalizeColumnName($default);
        }

        if ($normalized === '') {
            $normalized = $default;
        }

        return $normalized;
    }

    /**
     * Notify progress via callback if provided.
     *
     * @param string $column
     *
     * @return string
     */
    private function normalizeColumnName(string $column): string
    {
        $column = preg_replace('/^\xEF\xBB\xBF/u', '', $column) ?? $column; // Remove UTF-8 BOM.
        $column = trim($column);

        if ($column === '') {
            return '';
        }

        $column = mb_strtolower($column, 'UTF-8');
        $column = str_replace(['-', '/'], ' ', $column);
        $column = preg_replace('/[^a-z0-9]+/u', '_', $column) ?? $column;
        $column = preg_replace('/_+/', '_', $column) ?? $column;

        return trim($column, '_');
    }

    /**
     * Resolve and sanitise hyperparameters with defaults.
     *
     * @param DatasetRowBuffer $buffer
     * @param float $validationSplit
     *
     * @return array{
     *     train_buffer: FeatureBuffer,
     *     validation_buffer: FeatureBuffer,
     *     means: list<float>,
     *     std_devs: list<float> }
     */
    private function splitBufferedEntries(DatasetRowBuffer $buffer, float $validationSplit): array
    {
        $total = $buffer->count();

        if ($total === 0) {
            throw new RuntimeException('Dataset file does not contain any rows.');
        }

        $validationCount = (int) round($total * $validationSplit);
        $validationCount = max(0, min($validationCount, max(0, $total - 1)));
        $trainCount = $total - $validationCount;
        $cloneForValidation = false;

        if ($trainCount < 1) {
            $trainCount = $total;
            $validationCount = 0;
        }

        if ($validationCount === 0) {
            $cloneForValidation = true;
        }

        $trainBuffer = new FeatureBuffer();
        $validationBuffer = new FeatureBuffer();

        $means = [];
        $variances = [];
        $trainSeen = 0;
        $rowIndex = 0;

        foreach ($buffer as $row) {
            $useValidation = ! $cloneForValidation && $rowIndex >= $trainCount;

            if ($rowIndex < $trainCount || $cloneForValidation) {
                $trainBuffer->append($row['features'], $row['label']);
                $this->updateRunningStatistics($means, $variances, $trainSeen, $row['features']);
                $trainSeen++;
            }

            if ($useValidation || $cloneForValidation) {
                $validationBuffer->append($row['features'], $row['label']);
            }

            $rowIndex++;
        }

        if ($trainSeen === 0) {
            throw new RuntimeException('Training split did not produce any rows.');
        }

        $stdDevs = [];

        foreach ($means as $index => $mean) {
            $variance = $variances[$index] ?? 0.0;
            $std = sqrt($variance / max(1, $trainSeen));
            $std = $std > 1e-12 ? $std : 1.0;
            $stdDevs[$index] = $std;
            $means[$index] = $mean;
        }

        return [
            'train_buffer' => $trainBuffer,
            'validation_buffer' => $validationBuffer,
            'means' => array_values($means),
            'std_devs' => array_values($stdDevs),
        ];
    }

    /**
     * Update running means and variances using Welford's method.
     *
     * @param array<int, float> $means
     * @param array<int, float> $variances
     * @param int $count
     * @param list<float> $features
     *
     * @return void
     */
    private function updateRunningStatistics(array &$means, array &$variances, int $count, array $features): void
    {
        if ($count === 0) {
            foreach ($features as $index => $value) {
                $means[$index] = (float) $value;
                $variances[$index] = 0.0;
            }

            return;
        }

        $newCount = $count + 1;

        foreach ($features as $index => $value) {
            $currentMean = $means[$index] ?? 0.0;
            $delta = $value - $currentMean;
            $updatedMean = $currentMean + ($delta / $newCount);
            $means[$index] = $updatedMean;

            $variance = $variances[$index] ?? 0.0;
            $variances[$index] = $variance + $delta * ($value - $updatedMean);
        }
    }

    /**
     * Build a progress notifier closure.
     *
     * @param FeatureBuffer $buffer
     *
     * @return array{samples: list<list<float>>, labels: list<int>}
     */
    private function bufferToSamples(FeatureBuffer $buffer): array
    {
        $samples = [];
        $labels = [];

        foreach ($buffer as $row) {
            $samples[] = array_map('floatval', $row['features']);
            $labels[] = (int) $row['label'];
        }

        return [
            'samples' => $samples,
            'labels' => $labels,
        ];
    }

    /**
     * Apply standardization to the samples using provided means and standard deviations.
     *
     * @param list<list<float>> $samples
     * @param list<float> $means
     * @param list<float> $stdDevs
     *
     * @return list<list<float>>
     */
    private function applyStandardization(array $samples, array $means, array $stdDevs): array
    {
        $standardized = [];

        foreach ($samples as $sample) {
            $standardized[] = array_map(
                static fn ($value, $mean, $std) => ($value - $mean) / ($std > 1e-12 ? $std : 1.0),
                $sample,
                $means,
                $stdDevs
            );
        }

        return $standardized;
    }

    /**
     * Compute means and standard deviations for each feature.
     *
     * @param list<list<float>> $samples
     * @param list<int> $labels
     * @param array<string, mixed> $hyperparameters
     * @param callable|null $progressNotifier
     *
     * @return array{
     *     best_hyperparameters: array<string, mixed>,
     *     metrics: array<string, mixed>
     * }
     *
     * @throws InvalidArgumentException
     * @throws InvalidOperationException
     * @throws LibsvmCommandException
     * @throws NormalizerException
     */
    private function performGridSearch(
        array $samples,
        array $labels,
        array $hyperparameters,
        ?callable $progressNotifier
    ): array {
        $grid = $this->generateHyperparameterGrid($hyperparameters);

        $evaluations = [];
        $bestScore = -INF;
        $bestParams = [];
        $bestMacro = -INF;
        $folds = max(1, (int) $hyperparameters['cv_folds']);
        $validationSplit = max(0.1, min(0.5, (float) $hyperparameters['cv_validation_split']));

        $dataset = new ArrayDataset($samples, $labels);

        foreach ($grid as $index => $params) {
            $scores = [];
            $macroScores = [];

            for ($fold = 0; $fold < $folds; $fold++) {
                $split = new RandomSplit($dataset, $validationSplit);
                $trainSamples = $split->getTrainSamples();
                $trainLabels = $split->getTrainLabels();
                $testSamples = $split->getTestSamples();
                $testLabels = $split->getTestLabels();

                $imputer = ImputerFactory::create($hyperparameters['imputation_strategy']);
                $imputer->fit($trainSamples);
                $imputer->transform($trainSamples);
                $imputer->transform($testSamples);

                $statistics = $this->computeStatistics($trainSamples);
                $trainSamples = $this->applyStandardization($trainSamples, $statistics['means'], $statistics['std_devs']);
                $testSamples = $this->applyStandardization($testSamples, $statistics['means'], $statistics['std_devs']);

                $normalizer = new Normalizer($hyperparameters['normalization']);
                $this->normalizeSamplesSafely($normalizer, $trainSamples);
                $this->normalizeSamplesSafely($normalizer, $testSamples);

                $classifier = $this->createClassifier(
                    $hyperparameters['model_type'],
                    $params,
                    $hyperparameters,
                    null
                );

                try {
                    $classifier->train($trainSamples, $trainLabels);
                } catch (InvalidArgumentException $exception) {
                    if ($this->isInsufficientSampleException($exception)) {
                        continue;
                    }

                    throw $exception;
                }

                try {
                    $predictions = $classifier->predict($testSamples);
                } catch (InvalidArgumentException $exception) {
                    if ($this->isInsufficientSampleException($exception)) {
                        continue;
                    }

                    throw $exception;
                }

                $classification = ClassificationReportGenerator::generate($testLabels, $predictions);
                $report = $classification['report'];
                $scores[] = $report['accuracy'];
                $macroScores[] = $report['macro']['f1'];
            }

            $averageScore = $scores === [] ? 0.0 : array_sum($scores) / count($scores);
            $averageMacro = $macroScores === [] ? 0.0 : array_sum($macroScores) / count($macroScores);

            $evaluations[] = [
                'hyperparameters' => $params,
                'accuracy' => $averageScore,
                'macro_f1' => $averageMacro,
            ];

            if ($averageScore > $bestScore || ($averageScore === $bestScore && $averageMacro > $bestMacro)) {
                $bestScore = $averageScore;
                $bestMacro = $averageMacro;
                $bestParams = $params;
            }
        }

        usort($evaluations, static fn ($a, $b) => $b['accuracy'] <=> $a['accuracy']);

        return [
            'best_hyperparameters' => $bestParams,
            'metrics' => [
                'evaluations' => array_slice($evaluations, 0, 10),
                'best_accuracy' => $bestScore,
                'best_macro_f1' => $bestMacro,
                'best_hyperparameters' => $bestParams,
            ],
        ];
    }

    /**
     * Compute means and standard deviations for each feature.
     *
     * @param array<string, mixed> $hyperparameters
     *
     * @return list<array<string, mixed>>
     */
    private function generateHyperparameterGrid(array $hyperparameters): array
    {
        $modelType = $hyperparameters['model_type'];
        $userGrid = $hyperparameters['search_grid'];

        if ($modelType === 'svc') {
            return $this->generateSvcHyperparameterGrid($hyperparameters, $userGrid);
        }

        $defaultGrid = match ($modelType) {
            'knn' => [
                'k' => [3, 5, max(1, (int) $hyperparameters['k'])],
            ],
            'naive_bayes' => [],
            'decision_tree' => [
                'max_depth' => [3, max(3, (int) $hyperparameters['max_depth'])],
                'min_samples_split' => [2, max(2, (int) $hyperparameters['min_samples_split'])],
            ],
            'mlp' => [
                'hidden_layers' => [$hyperparameters['hidden_layers'], [8], [16, 8]],
                'learning_rate' => [0.05, (float) $hyperparameters['learning_rate']],
                'iterations' => [300, $hyperparameters['iterations']],
            ],
            default => [
                'learning_rate' => [0.1, $hyperparameters['learning_rate']],
                'iterations' => [400, $hyperparameters['iterations']],
                'l2_penalty' => [0.0, $hyperparameters['l2_penalty']],
            ],
        };

        foreach ($userGrid as $key => $values) {
            if (! is_array($values) || $values === []) {
                continue;
            }

            $normalizedValues = [];

            foreach ($values as $value) {
                if (is_array($value)) {
                    $normalizedValues[] = array_values(array_map('intval', $value));
                } elseif (is_numeric($value)) {
                    $normalizedValues[] = $value + 0;
                } else {
                    $normalizedValues[] = $value;
                }
            }

            if ($normalizedValues !== []) {
                $defaultGrid[$key] = array_values(array_unique($normalizedValues, SORT_REGULAR));
            }
        }

        if ($defaultGrid === []) {
            return [['iterations' => $hyperparameters['iterations']]];
        }

        $combinations = [[]];

        foreach ($defaultGrid as $key => $values) {
            $values = array_values($values);
            $next = [];

            foreach ($combinations as $combo) {
                foreach ($values as $value) {
                    $combo[$key] = $value;
                    $next[] = $combo;
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * Generate hyperparameter combinations for the SVC classifier.
     *
     * @param array<string, mixed> $hyperparameters
     * @param array<string, list<mixed>> $userGrid
     *
     * @return list<array<string, mixed>>
     */
    private function generateSvcHyperparameterGrid(array $hyperparameters, array $userGrid): array
    {
        $costValues = $this->mergeGridNumericValues(
            'cost',
            [0.5, 1.0, (float) $hyperparameters['cost']],
            $userGrid,
            0.0001,
            1000.0
        );

        $toleranceValues = $this->mergeGridNumericValues(
            'tolerance',
            [0.0001, (float) $hyperparameters['tolerance'], 0.01],
            $userGrid,
            1.0e-6,
            0.1
        );

        $cacheSizes = $this->mergeGridNumericValues(
            'cache_size',
            [50.0, (float) $hyperparameters['cache_size']],
            $userGrid,
            1.0,
            4096.0
        );

        $shrinkingValues = $this->mergeGridBooleanValues(
            'shrinking',
            [$hyperparameters['shrinking']],
            $userGrid
        );

        $probabilityValues = $this->mergeGridBooleanValues(
            'probability_estimates',
            [$hyperparameters['probability_estimates']],
            $userGrid
        );

        $kernelCombos = $this->mergeSvcKernelGrid($hyperparameters, $userGrid);

        $combinations = [];

        foreach ($costValues as $cost) {
            foreach ($toleranceValues as $tolerance) {
                foreach ($cacheSizes as $cacheSize) {
                    foreach ($shrinkingValues as $shrinking) {
                        foreach ($probabilityValues as $probability) {
                            foreach ($kernelCombos as $kernelCombo) {
                                $combinations[] = array_merge(
                                    [
                                        'cost' => $cost,
                                        'tolerance' => $tolerance,
                                        'cache_size' => $cacheSize,
                                        'shrinking' => $shrinking,
                                        'probability_estimates' => $probability,
                                    ],
                                    $kernelCombo
                                );
                            }
                        }
                    }
                }
            }
        }

        if ($combinations === []) {
            $combinations[] = [
                'cost' => (float) $hyperparameters['cost'],
                'tolerance' => (float) $hyperparameters['tolerance'],
                'cache_size' => (float) $hyperparameters['cache_size'],
                'shrinking' => (bool) $hyperparameters['shrinking'],
                'probability_estimates' => (bool) $hyperparameters['probability_estimates'],
                'kernel' => $hyperparameters['kernel'],
                'kernel_options' => $hyperparameters['kernel_options'],
            ];
        }

        return $combinations;
    }

    /**
     * Merge numeric grid values with user provided overrides.
     *
     * @param string $key
     * @param list<float|int> $defaults
     * @param array<string, list<mixed>> $userGrid
     * @param float $min
     * @param float $max
     *
     * @return list<float>
     */
    private function mergeGridNumericValues(string $key, array $defaults, array $userGrid, float $min, float $max): array
    {
        $values = [];

        foreach ($defaults as $value) {
            if (is_numeric($value)) {
                $values[] = $this->clampFloat((float) $value, $min, $max);
            }
        }

        if (isset($userGrid[$key])) {
            foreach ($userGrid[$key] as $value) {
                if (is_numeric($value)) {
                    $values[] = $this->clampFloat((float) $value, $min, $max);
                }
            }
        }

        if ($values === []) {
            return [$this->clampFloat($defaults[0] ?? $min, $min, $max)];
        }

        $values = array_map(static fn (float $value): float => $value + 0.0, $values);

        return array_values(array_unique($values, SORT_REGULAR));
    }

    /**
     * Merge boolean grid values with user provided overrides.
     *
     * @param string $key
     * @param list<mixed> $defaults
     * @param array<string, list<mixed>> $userGrid
     *
     * @return list<bool>
     */
    private function mergeGridBooleanValues(string $key, array $defaults, array $userGrid): array
    {
        $values = [];

        foreach ($defaults as $value) {
            $values[] = $this->normalizeBoolean($value, true);
        }

        if (isset($userGrid[$key])) {
            foreach ($userGrid[$key] as $value) {
                $values[] = $this->normalizeBoolean($value, true);
            }
        }

        $values = array_values(array_unique($values, SORT_REGULAR));

        return $values === [] ? [true, false] : $values;
    }

    /**
     * Merge kernel definitions from defaults and user overrides.
     *
     * @param array<string, mixed> $hyperparameters
     * @param array<string, list<mixed>> $userGrid
     *
     * @return list<array{kernel: string, kernel_options: array<string, float|int>}>
     */
    private function mergeSvcKernelGrid(array $hyperparameters, array $userGrid): array
    {
        $defaultKernel = is_string($hyperparameters['kernel'] ?? null)
            ? strtolower($hyperparameters['kernel'])
            : 'rbf';

        $kernelValues = [$defaultKernel, 'rbf', 'linear'];

        if (isset($userGrid['kernel'])) {
            foreach ($userGrid['kernel'] as $value) {
                if (is_string($value)) {
                    $kernelValues[] = strtolower($value);
                }
            }
        }

        $optionsByKernel = [];

        if (isset($userGrid['kernel_options'])) {
            foreach ($userGrid['kernel_options'] as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $kernel = $option['kernel'] ?? $option['type'] ?? $defaultKernel;
                $kernel = is_string($kernel) ? strtolower($kernel) : $defaultKernel;

                $optionSet = $option;
                unset($optionSet['kernel'], $optionSet['type']);

                $optionsByKernel[$kernel][] = is_array($optionSet) ? $optionSet : [];
                $kernelValues[] = $kernel;
            }
        }

        $kernelValues = array_values(array_unique(array_filter($kernelValues, static fn ($value) => is_string($value))));

        $combinations = [];
        $seen = [];

        foreach ($kernelValues as $kernel) {
            $optionSets = $optionsByKernel[$kernel] ?? [];

            if ($optionSets === []) {
                $optionSets[] = $kernel === $defaultKernel
                    ? ($hyperparameters['kernel_options'] ?? [])
                    : [];
            }

            foreach ($optionSets as $optionSet) {
                $normalizedOptions = $this->resolveSvcKernelOptions($kernel, is_array($optionSet) ? $optionSet : []);
                $key = $kernel . ':' . serialize($normalizedOptions);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $combinations[] = [
                    'kernel' => $kernel,
                    'kernel_options' => $normalizedOptions,
                ];
            }
        }

        if ($combinations === []) {
            $combinations[] = [
                'kernel' => $defaultKernel,
                'kernel_options' => $this->resolveSvcKernelOptions(
                    $defaultKernel,
                    $hyperparameters['kernel_options'] ?? []
                ),
            ];
        }

        return $combinations;
    }

    /**
     * Resolve SVC kernel configuration.
     *
     * @param string $kernel
     * @param array<string, mixed> $options
     *
     * @return array{type: int, instance: object|null}
     */
    private function resolveSvcKernel(string $kernel, array $options): array
    {
        $kernel = strtolower($kernel);
        $allowed = $this->availableSvcKernelTypes();

        if (! isset($allowed[$kernel])) {
            $kernel = 'rbf';
        }

        $normalizedOptions = $this->resolveSvcKernelOptions($kernel, $options);
        $instance = $this->instantiateSvcKernelObject($kernel, $normalizedOptions);

        return [
            'type' => $allowed[$kernel],
            'options' => $normalizedOptions,
            'instance' => $instance,
        ];
    }

    /**
     * Instantiate an SVC if php-ml is available.
     *
     * @param string $kernel  One of: 'linear', 'polynomial', 'rbf', 'sigmoid'
     * @param array<string,mixed> $options  Supported: c|C, degree, gamma, coef0, tolerance, cacheSize, shrinking, probability
     * @return object|null  SVC instance or null if php-ml isn't present
     */
    private function instantiateSvcKernelObject(string $kernel, array $options): ?object
    {
        if (!class_exists(SVC::class) || !class_exists(Kernel::class)) {
            return null;
        }

        // Map kernel name → php-ml Kernel constant
        $kernelConst = match (strtolower($kernel)) {
            'linear'     => Kernel::LINEAR,
            'polynomial' => Kernel::POLYNOMIAL,
            'sigmoid'    => Kernel::SIGMOID,
            default      => Kernel::RBF,
        };

        // Hyper-params (coerce types; provide sane fallbacks)
        $c        = (float)($options['C'] ?? $options['c'] ?? 1.0);
        $degree   = (int)  ($options['degree'] ?? 3);
        $gamma    = (float)($options['gamma']  ?? 0.0);
        $coef0    = (float)($options['coef0']  ?? 0.0);

        // Trainer/runtime knobs (optional)
        $tolerance   = (float)($options['tolerance']  ?? 1e-3);
        $cacheSize   = (int)  ($options['cacheSize']  ?? 100);
        $shrinking   = (bool) ($options['shrinking']  ?? true);
        $probability = (bool) ($options['probability'] ?? false);

        try {
            // SVC signature (php-ml): (int $kernel, float $cost, int $degree, float $gamma, float $coef0, float $tolerance, int $cacheSize, bool $shrinking, bool $probabilityEstimates)
            return new SVC($kernelConst, $c, $degree, $gamma, $coef0, $tolerance, $cacheSize, $shrinking, $probability);
        } catch (Throwable $e) {
            // In case of edge-version signature differences, fall back to minimal signature.
            try {
                return new SVC($kernelConst, $c, $degree, $gamma, $coef0);
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * Normalise kernel-specific options.
     *
     * @param string $kernel
     * @param array<string, mixed> $options
     *
     * @return array<string, float|int>
     */
    private function resolveSvcKernelOptions(string $kernel, array $options): array
    {
        $kernel = strtolower($kernel);

        return match ($kernel) {
            'polynomial' => [
                'degree' => max(1, min((int) ($options['degree'] ?? 3), 10)),
                'gamma' => $this->clampFloat((float) ($options['gamma'] ?? 1.0), 1.0e-4, 10.0),
                'coef0' => $this->clampFloat((float) ($options['coef0'] ?? 0.0), -10.0, 10.0),
            ],
            'sigmoid' => [
                'gamma' => $this->clampFloat((float) ($options['gamma'] ?? 0.5), 1.0e-4, 10.0),
                'coef0' => $this->clampFloat((float) ($options['coef0'] ?? 0.0), -10.0, 10.0),
            ],
            'rbf' => [
                'gamma' => $this->clampFloat((float) ($options['gamma'] ?? 0.5), 1.0e-4, 10.0),
            ],
            default => [],
        };
    }

    /**
     * Determine the available kernel constants for the installed PHP-ML version.
     *
     * @return array<string, int>
     */
    private function availableSvcKernelTypes(): array
    {
        $kernels = [
            'linear' => Kernel::LINEAR,
            'polynomial' => Kernel::POLYNOMIAL,
            'sigmoid' => Kernel::SIGMOID,
            'rbf' => Kernel::RBF,
        ];

        $precomputed = Kernel::class.'::PRECOMPUTED';

        if (defined($precomputed)) {
            /** @var int $value */
            $value = constant($precomputed);
            $kernels['precomputed'] = $value;
        }

        return $kernels;
    }

    /**
     * Normalize a boolean value with a fallback.
     */
    private function normalizeBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $filtered ?? $default;
    }

    /**
     * Clamp a floating point value between the provided bounds.
     */
    private function clampFloat(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * Compute basic statistics for each feature.
     *
     * @param string $modelType
     * @param array<string, mixed> $params
     * @param array<string, mixed> $defaults
     * @param callable|null $progressNotifier
     *
     * @return object Classifier instance
     * @throws InvalidArgumentException
     */
    private function createClassifier(
        string $modelType,
        array $params,
        array $defaults,
        ?callable $progressNotifier
    ): object {
        return match ($modelType) {
            'svc' => $this->buildSvcClassifier($params, $defaults),
            'knn' => new KNearestNeighbors((int) ($params['k'] ?? $defaults['k'])),
            'naive_bayes' => new NaiveBayes(),
            'decision_tree' => new DecisionTree(
                (int) ($params['max_depth'] ?? $defaults['max_depth']),
                (int) ($params['min_samples_split'] ?? $defaults['min_samples_split'])
            ),
            'mlp' => $this->buildMlpClassifier($params, $defaults),
            default => $this->buildLogisticClassifier($params, $defaults, $progressNotifier),
        };
    }

    /**
     * Build an SVC classifier using cost, tolerance, cache size and kernel configuration.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $defaults
     *
     * @return SVC
     */
    private function buildSvcClassifier(array $params, array $defaults): SVC
    {
        $cost = (float) ($params['cost'] ?? $defaults['cost'] ?? 1.0);
        $tolerance = (float) ($params['tolerance'] ?? $defaults['tolerance'] ?? 0.001);
        $cacheSize = (int) round((float) ($params['cache_size'] ?? $defaults['cache_size'] ?? 100.0));
        $shrinking = $this->normalizeBoolean($params['shrinking'] ?? $defaults['shrinking'] ?? true, true);
        $probabilityEstimates = $this->normalizeBoolean(
            $params['probability_estimates'] ?? $defaults['probability_estimates'] ?? true,
            true
        );
        $kernelType = is_string($params['kernel'] ?? null)
            ? strtolower($params['kernel'])
            : ($defaults['kernel'] ?? 'rbf');

        $kernelOptions = [];

        if (isset($params['kernel_options']) && is_array($params['kernel_options'])) {
            $kernelOptions = $params['kernel_options'];
        } elseif (isset($defaults['kernel_options']) && is_array($defaults['kernel_options'])) {
            $kernelOptions = $defaults['kernel_options'];
        }

        $kernel = $this->resolveSvcKernel((string) $kernelType, $kernelOptions);
        $resolvedKernelOptions = $kernel['options'] ?? [];

        $degree = (int) ($resolvedKernelOptions['degree'] ?? 3);
        $gamma = array_key_exists('gamma', $resolvedKernelOptions)
            ? (float) $resolvedKernelOptions['gamma']
            : null;
        $coef0 = (float) ($resolvedKernelOptions['coef0'] ?? 0.0);

        return new SVC(
            $kernel['type'],
            $cost,
            $degree,
            $gamma,
            $coef0,
            $tolerance,
            $cacheSize,
            $shrinking,
            $probabilityEstimates
        );
    }

    /**
     * Build a logistic regression classifier with flexible constructor arguments.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $defaults
     * @param callable|null $progressNotifier
     *
     * @return PhpmlLogisticRegression
     * @throws InvalidArgumentException
     */
    private function buildLogisticClassifier(
        array $params,
        array $defaults,
        ?callable $progressNotifier
    ): PhpmlLogisticRegression {
        $iterations = (int) ($params['iterations'] ?? $defaults['iterations']);
        $learningRate = (float) ($params['learning_rate'] ?? $defaults['learning_rate']);
        $l2Penalty = (float) ($params['l2_penalty'] ?? $defaults['l2_penalty']);

        $classifier = $this->instantiateLogisticRegression($iterations, $learningRate, $l2Penalty);

        if ($progressNotifier !== null && method_exists($classifier, 'setProgressCallback')) {
            $classifier->setProgressCallback($progressNotifier);
        }

        return $classifier;
    }

    /**
     * Build an MLP classifier with flexible constructor arguments.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $defaults
     *
     * @return MLPClassifier
     * @throws InvalidArgumentException
     */
    private function buildMlpClassifier(array $params, array $defaults): MLPClassifier
    {
        $hiddenLayers = $this->resolveHiddenLayers($params['hidden_layers'] ?? $defaults['hidden_layers']);
        $iterations = (int) ($params['iterations'] ?? $defaults['iterations']);
        $learningRate = (float) ($params['learning_rate'] ?? $defaults['learning_rate']);

        $trainingFunction = defined(MLPClassifier::class . '::TRAINING_BACKPROPAGATION')
            ? constant(MLPClassifier::class . '::TRAINING_BACKPROPAGATION')
            : null;

        $attempts = [];

        if ($trainingFunction !== null) {
            $attempts[] = [$hiddenLayers, $iterations, $trainingFunction, $learningRate];
            $attempts[] = [$hiddenLayers, $iterations, $trainingFunction];
        }

        $attempts[] = [$hiddenLayers, $iterations, $learningRate];
        $attempts[] = [$hiddenLayers, $iterations];

        foreach ($attempts as $arguments) {
            try {
                return new MLPClassifier(...$arguments);
            } catch (ArgumentCountError|InvalidArgumentException|TypeError $exception) {
                $lastError = $exception;
            }
        }

        throw $lastError instanceof InvalidArgumentException
            ? $lastError
            : new InvalidArgumentException('Unable to instantiate MLP classifier with the provided parameters.', 0, $lastError);
    }

    /**
     * Instantiate logistic regression with flexible constructor arguments.
     *
     * @param int $iterations
     * @param float $learningRate
     * @param float $lambda
     *
     * @return PhpmlLogisticRegression
     * @throws InvalidArgumentException
     */
    private function instantiateLogisticRegression(int $iterations, float $learningRate, float $lambda): PhpmlLogisticRegression
    {
        $candidates = [
            [PhpmlLogisticRegression::BATCH_TRAINING, $learningRate, $iterations, null, $lambda],
            [PhpmlLogisticRegression::BATCH_TRAINING, $learningRate, $iterations],
            [PhpmlLogisticRegression::BATCH_TRAINING, $learningRate],
            [$iterations, $learningRate, $lambda],
            [$iterations, $learningRate],
            [$learningRate],
            [],
        ];

        foreach ($candidates as $arguments) {
            try {
                return new PhpmlLogisticRegression(...$arguments);
            } catch (ArgumentCountError|InvalidArgumentException|TypeError $exception) {
                $lastException = $exception;
            }
        }

        if ($lastException instanceof InvalidArgumentException) {
            throw $lastException;
        }

        throw new RuntimeException('Unable to instantiate logistic regression classifier.', 0, $lastException);
    }

    /**
     *
     * @return list<int>
     */
    private function resolveHiddenLayers(mixed $value): array
    {
        if (is_array($value) && $value !== []) {
            return array_values(array_map('intval', $value));
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded) && $decoded !== []) {
                return array_values(array_map('intval', $decoded));
            }
        }

        return [16];
    }

    /**
     * Formats a feature name to be more human-readable.
     *
     * @param array<string, mixed> $report
     * @param array{labels: list<int|float|string>, matrix: list<list<int>>} $confusion
     * @param list<float> $probabilities
     * @param list<int> $actual
     *
     * @return array<string, mixed>
     */
    private function formatMetrics(array $report, array $confusion, array $probabilities, array $actual): array
    {
        $accuracy = $report['accuracy'];
        $macro = $report['macro'];
        $weighted = $report['weighted'];

        $perClass = array_map(function ($classMetrics) {
            return [
                'precision' => round($classMetrics['precision'], 4),
                'recall' => round($classMetrics['recall'], 4),
                'f1' => round($classMetrics['f1'], 4),
                'support' => $classMetrics['support'],
            ];
        }, $report['per_class']);

        return [
            'accuracy' => round($accuracy, 4),
            'macro_precision' => round($macro['precision'], 4),
            'macro_recall' => round($macro['recall'], 4),
            'macro_f1' => round($macro['f1'], 4),
            'weighted_precision' => round($weighted['precision'], 4),
            'weighted_recall' => round($weighted['recall'], 4),
            'weighted_f1' => round($weighted['f1'], 4),
            'per_class' => $perClass,
            'confusion_matrix' => $confusion,
            'auc' => $this->computeAuc($actual, $probabilities),
        ];
    }

    /**
     * Compute AUC (Area Under the Curve) for binary classification.
     *
     * @param list<int> $labels
     * @param list<float> $scores
     *
     * @return float
     */
    private function computeAuc(array $labels, array $scores): float
    {
        $positives = [];
        $negatives = [];

        foreach ($labels as $index => $label) {
            $score = $scores[$index] ?? 0.0;

            if ($label === 1) {
                $positives[] = $score;
            } else {
                $negatives[] = $score;
            }
        }

        $posCount = count($positives);
        $negCount = count($negatives);

        if ($posCount === 0 || $negCount === 0) {
            return 0.0;
        }

        $wins = 0.0;
        $ties = 0.0;

        foreach ($positives as $positiveScore) {
            foreach ($negatives as $negativeScore) {
                if ($positiveScore > $negativeScore) {
                    $wins++;
                } elseif ($positiveScore === $negativeScore) {
                    $ties++;
                }
            }
        }

        $auc = ($wins + (0.5 * $ties)) / ($posCount * $negCount);

        return round($auc, 4);
    }

    /**
     * Compute feature importances using correlation with the label.
     *
     * @param list<list<float>> $samples
     * @param list<int> $labels
     * @param list<string> $featureNames
     *
     * @return list<array{name: string, contribution: float}>
     */
    private function computeFeatureImportances(array $samples, array $labels, array $featureNames): array
    {
        if ($samples === [] || $labels === []) {
            return [];
        }

        $labelMean = array_sum($labels) / count($labels);
        $labelVariance = 0.0;

        foreach ($labels as $label) {
            $delta = $label - $labelMean;
            $labelVariance += $delta * $delta;
        }

        $labelStd = sqrt($labelVariance / max(1, count($labels) - 1));
        $labelStd = $labelStd > 0.0 ? $labelStd : 1.0;

        $importances = [];
        $featureCount = count($samples[0]);

        for ($index = 0; $index < $featureCount; $index++) {
            $values = array_column($samples, $index);
            $mean = array_sum($values) / max(1, count($values));
            $variance = 0.0;
            $covariance = 0.0;

            foreach ($values as $key => $value) {
                $delta = $value - $mean;
                $variance += $delta * $delta;
                $covariance += $delta * ($labels[$key] - $labelMean);
            }

            $std = sqrt($variance / max(1, count($values) - 1));
            $std = $std > 0.0 ? $std : 1.0;
            $correlation = $covariance / (max(1, count($values) - 1) * $std * $labelStd);
            $importances[] = [
                'name' => $this->prettifyFeatureName($featureNames[$index] ?? ('Feature ' . ($index + 1))),
                'contribution' => round(abs($correlation), 4),
            ];
        }

        usort($importances, static fn ($a, $b) => $b['contribution'] <=> $a['contribution']);

        return array_slice($importances, 0, 10);
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
     * Resolve an imputer strategy identifier to a human-readable label.
     */
    private function describeImputerStrategy(int $strategy): string
    {
        $candidates = [
            'mean' => $this->imputerConstant('STRATEGY_MEAN', 0),
        ];

        if ($this->hasImputerConstant('STRATEGY_MEDIAN')) {
            $candidates['median'] = $this->imputerConstant('STRATEGY_MEDIAN', 1);
        }

        if ($this->hasImputerConstant('STRATEGY_MOST_FREQUENT')) {
            $candidates['most_frequent'] = $this->imputerConstant('STRATEGY_MOST_FREQUENT', 2);
        }

        if ($this->hasImputerConstant('STRATEGY_CONSTANT')) {
            $candidates['constant'] = $this->imputerConstant('STRATEGY_CONSTANT', 3);
        }

        foreach ($candidates as $label => $value) {
            if ($value === $strategy) {
                return $label;
            }
        }

        return (string) $strategy;
    }

    /**
     * Resolve a normalizer type identifier to a human-readable label.
     */
    private function describeNormalizerType(int $type): string
    {
        $candidates = [];

        $l1 = $this->normalizerConstant('NORM_L1');

        if ($l1 !== null) {
            $candidates['l1'] = $l1;
        }

        $l2 = $this->normalizerConstant('NORM_L2');

        if ($l2 !== null) {
            $candidates['l2'] = $l2;
        }

        $max = $this->normalizerConstant('NORM_MAX') ?? $this->normalizerConstant('NORM_LINF');

        if ($max !== null) {
            $candidates['max'] = $max;
        }

        $std = $this->normalizerConstant('NORM_STD');

        if ($std !== null) {
            $candidates['std'] = $std;
        }

        foreach ($candidates as $label => $value) {
            if ($value === $type) {
                return $label;
            }
        }

        return (string) $type;
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
     * Prettify feature name for display.
     *
     * @param string $name
     *
     * @return string
     */
    private function prettifyFeatureName(string $name): string
    {
        $name = str_replace(['_', '-'], ' ', $name);
        $name = trim($name);

        return ucwords($name);
    }

    /**
     * Compute means and standard deviations for each feature.
     *
     * @param list<list<float>> $samples
     *
     * @return array{means: list<float>, std_devs: list<float>}
     */
    private function computeStatistics(array $samples): array
    {
        if ($samples === []) {
            return [
                'means' => [],
                'std_devs' => [],
            ];
        }

        $featureCount = count($samples[0]);
        $means = array_fill(0, $featureCount, 0.0);
        $variances = array_fill(0, $featureCount, 0.0);

        foreach ($samples as $sample) {
            foreach ($sample as $index => $value) {
                $means[$index] += $value;
            }
        }

        foreach ($means as $index => $sum) {
            $means[$index] = $sum / count($samples);
        }

        foreach ($samples as $sample) {
            foreach ($sample as $index => $value) {
                $delta = $value - $means[$index];
                $variances[$index] += $delta * $delta;
            }
        }

        $stdDevs = [];

        foreach ($variances as $index => $variance) {
            $std = sqrt($variance / max(1, count($samples) - 1));
            $stdDevs[$index] = $std > 1e-12 ? $std : 1.0;
        }

        return [
            'means' => $means,
            'std_devs' => $stdDevs,
        ];
    }

    private function normalizeSamplesSafely(Normalizer $normalizer, array &$samples): void
    {
        if ($samples === []) {
            return;
        }

        try {
            $normalizer->transform($samples);
        } catch (InvalidArgumentException $exception) {
            if (! $this->isInsufficientSampleException($exception)) {
                throw $exception;
            }
        }
    }

    private function isInsufficientSampleException(InvalidArgumentException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'zero elements') || str_contains($message, 'at least 2 elements');
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
     * Normalize and validate hyperparameters from input.
     *
     * @param array $input
     *
     * @return array{
     *     model_type: string,
     *     learning_rate: float,
     *     iterations: int,
     *     validation_split: float,
     *     l2_penalty: float,
     *     log_interval: int,
     *     normalization: int,
     *     imputation_strategy: int,
     *     normalization: string,
     *     imputation_strategy: string,
     *     lambda: float,
     *     cost: float,
     *     tolerance: float,
     *     cache_size: float,
     *     shrinking: bool,
     *     probability_estimates: bool,
     *     kernel: string,
     *     kernel_options: array<string, float|int>,
     *     k: int,
     *     max_depth: int,
     *     min_samples_split: int,
     *     hidden_layers: list<int>,
     *     cv_folds: int,
     *     cv_validation_split: float,
     *     search_grid: array<string, list<mixed>>
     * }
     */
    private function resolveHyperparameters(array $input): array
    {
        $modelType = is_string($input['model_type'] ?? null) ? strtolower($input['model_type']) : 'logistic_regression';
        $allowedModels = ['logistic_regression', 'svc', 'knn', 'naive_bayes', 'decision_tree', 'mlp'];

        if (! in_array($modelType, $allowedModels, true)) {
            $modelType = 'logistic_regression';
        }

        $learningRate = isset($input['learning_rate']) ? (float) $input['learning_rate'] : 0.3;
        $iterations = isset($input['iterations']) ? (int) $input['iterations'] : 600;
        $validationSplit = isset($input['validation_split']) ? (float) $input['validation_split'] : 0.2;
        $l2Penalty = isset($input['l2_penalty']) ? (float) $input['l2_penalty'] : 0.01;
        $logInterval = isset($input['log_interval']) ? (int) $input['log_interval'] : 200;
        $normalization = $this->normalizeNormalizerType($input['normalization'] ?? null);
        $imputation = $this->normalizeImputationStrategy($input['imputation_strategy'] ?? null);
        $lambda = isset($input['lambda']) ? (float) $input['lambda'] : 0.0001;
        $cost = isset($input['cost']) ? (float) $input['cost'] : 1.0;
        $tolerance = isset($input['tolerance']) ? (float) $input['tolerance'] : 0.001;
        $cacheSize = isset($input['cache_size']) ? (float) $input['cache_size'] : 100.0;
        $shrinking = $this->normalizeBoolean($input['shrinking'] ?? true, true);
        $probabilityEstimates = $this->normalizeBoolean($input['probability_estimates'] ?? true, true);
        $kernel = is_string($input['kernel'] ?? null) ? strtolower($input['kernel']) : 'rbf';
        $kernelOptionsInput = isset($input['kernel_options']) && is_array($input['kernel_options'])
            ? $input['kernel_options']
            : [];
        $k = isset($input['k']) ? (int) $input['k'] : 5;
        $maxDepth = isset($input['max_depth']) ? (int) $input['max_depth'] : 5;
        $minSamplesSplit = isset($input['min_samples_split']) ? (int) $input['min_samples_split'] : 2;
        $hiddenLayers = $this->resolveHiddenLayers($input['hidden_layers'] ?? [16]);
        $cvFolds = isset($input['cv_folds']) ? (int) $input['cv_folds'] : 3;
        $cvValidationSplit = isset($input['cv_validation_split']) ? (float) $input['cv_validation_split'] : 0.25;
        $searchGrid = $this->resolveGrid($input['grid'] ?? $input['search_grid'] ?? []);

        $learningRate = max(0.0001, min($learningRate, 1.0));
        $iterations = max(100, min($iterations, 5000));
        $validationSplit = max(0.1, min($validationSplit, 0.5));
        $l2Penalty = max(0.0, min($l2Penalty, 10.0));
        $logInterval = max(1, min($logInterval, $iterations));
        $lambda = max(0.0, min($lambda, 1.0));
        $cost = $this->clampFloat($cost, 0.0001, 1000.0);
        $tolerance = $this->clampFloat($tolerance, 1.0e-6, 0.1);
        $cacheSize = $this->clampFloat($cacheSize, 1.0, 4096.0);
        $allowedKernels = array_keys($this->availableSvcKernelTypes());

        if (! in_array($kernel, $allowedKernels, true)) {
            $kernel = 'rbf';
        }

        $kernelOptions = $this->resolveSvcKernelOptions($kernel, $kernelOptionsInput);
        $k = max(1, min($k, 21));
        $maxDepth = max(2, min($maxDepth, 20));
        $minSamplesSplit = max(2, min($minSamplesSplit, 20));
        $cvFolds = max(2, min($cvFolds, 10));
        $cvValidationSplit = max(0.1, min($cvValidationSplit, 0.5));

        $availableNormalizations = $this->availableNormalizerTypes();

        if (! in_array($normalization, $availableNormalizations, true)) {
            $fallbackNormalization = $this->normalizerConstant('NORM_L2');
            $normalization = $fallbackNormalization ?? ($availableNormalizations[0] ?? $normalization);
        }

        $availableStrategies = $this->availableImputationStrategies();

        if (! in_array($imputation, $availableStrategies, true)) {
            $fallbackStrategy = $this->imputerConstant('STRATEGY_MEAN', 0);
            $imputation = $fallbackStrategy ?? ($availableStrategies[0] ?? $imputation);
        }

        return [
            'model_type' => $modelType,
            'learning_rate' => $learningRate,
            'iterations' => $iterations,
            'validation_split' => $validationSplit,
            'l2_penalty' => $l2Penalty,
            'log_interval' => $logInterval,
            'normalization' => $normalization,
            'imputation_strategy' => $imputation,
            'lambda' => $lambda,
            'cost' => $cost,
            'tolerance' => $tolerance,
            'cache_size' => $cacheSize,
            'shrinking' => $shrinking,
            'probability_estimates' => $probabilityEstimates,
            'kernel' => $kernel,
            'kernel_options' => $kernelOptions,
            'k' => $k,
            'max_depth' => $maxDepth,
            'min_samples_split' => $minSamplesSplit,
            'hidden_layers' => $hiddenLayers,
            'cv_folds' => $cvFolds,
            'cv_validation_split' => $cvValidationSplit,
            'search_grid' => $searchGrid,
        ];
    }

    /**
     * Normalize hyperparameter grid from various input formats.
     *
     * @param mixed $grid
     *
     * @return array<string, list<mixed>>
     */
    private function resolveGrid(mixed $grid): array
    {
        if (! is_array($grid)) {
            return [];
        }

        $resolved = [];

        foreach ($grid as $key => $values) {
            if (! is_string($key)) {
                continue;
            }

            $values = Arr::wrap($values);
            $values = array_values(array_filter($values, static fn ($value) => $value !== null));

            if ($values === []) {
                continue;
            }

            $resolved[$key] = $values;
        }

        return $resolved;
    }

    /**
     * Get the value of a Normaliser constant if it exists.
     *
     * @param mixed $value
     *
     * @return int
     */
    private function normalizeNormalizerType(mixed $value): int
    {
        $default = $this->normalizerConstant('NORM_L2') ?? 2;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            $maxNorm = $this->normalizerConstant('NORM_MAX') ?? $this->normalizerConstant('NORM_LINF');
            $stdNorm = $this->normalizerConstant('NORM_STD');

            $map = array_filter([
                'l1' => $this->normalizerConstant('NORM_L1'),
                'l2' => $this->normalizerConstant('NORM_L2'),
                'linf' => $maxNorm,
                'inf' => $maxNorm,
                'max' => $maxNorm,
                'maxnorm' => $maxNorm,
                'std' => $stdNorm,
                'zscore' => $stdNorm,
            ], static fn ($candidate) => $candidate !== null);

            if (array_key_exists($normalized, $map)) {
                return (int) $map[$normalized];
            }
        }

        return $default;
    }

    /**
     * Normalize imputation strategy from various input formats.
     *
     * @param mixed $value
     *
     * @return int
     */
    private function normalizeImputationStrategy(mixed $value): int
    {
        $default = $this->imputerConstant('STRATEGY_MEAN', 0);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            $map = array_filter([
                'mean' => $this->imputerConstant('STRATEGY_MEAN', $default),
                'median' => $this->hasImputerConstant('STRATEGY_MEDIAN')
                    ? $this->imputerConstant('STRATEGY_MEDIAN', 1)
                    : null,
                'most_frequent' => $this->hasImputerConstant('STRATEGY_MOST_FREQUENT')
                    ? $this->imputerConstant('STRATEGY_MOST_FREQUENT', 2)
                    : null,
                'mostfrequent' => $this->hasImputerConstant('STRATEGY_MOST_FREQUENT')
                    ? $this->imputerConstant('STRATEGY_MOST_FREQUENT', 2)
                    : null,
                'constant' => $this->hasImputerConstant('STRATEGY_CONSTANT')
                    ? $this->imputerConstant('STRATEGY_CONSTANT', 3)
                    : null,
            ], static fn ($candidate) => $candidate !== null);

            if (array_key_exists($normalized, $map)) {
                return (int) $map[$normalized];
            }
        }

        return $default;
    }

    /**
     * Get available normalizer types based on installed PHP-ML version.
     *
     * @return list<int>
     */
    private function availableNormalizerTypes(): array
    {
        $types = [];

        foreach (['NORM_L1', 'NORM_L2'] as $name) {
            $value = $this->normalizerConstant($name);

            if ($value !== null) {
                $types[] = $value;
            }
        }

        foreach (['NORM_MAX', 'NORM_LINF'] as $name) {
            $value = $this->normalizerConstant($name);

            if ($value !== null) {
                $types[] = $value;
            }
        }

        $std = $this->normalizerConstant('NORM_STD');

        if ($std !== null) {
            $types[] = $std;
        }

        if ($types === []) {
            $types[] = 2;
        }

        return array_values(array_unique($types));
    }

    /**
     * Get available imputation strategies based on installed PHP-ML version.
     *
     * @return list<int>
     */
    private function availableImputationStrategies(): array
    {
        $strategies = [$this->imputerConstant('STRATEGY_MEAN', 0)];

        if ($this->hasImputerConstant('STRATEGY_MEDIAN')) {
            $strategies[] = $this->imputerConstant('STRATEGY_MEDIAN', 1);
        }

        if ($this->hasImputerConstant('STRATEGY_MOST_FREQUENT')) {
            $strategies[] = $this->imputerConstant('STRATEGY_MOST_FREQUENT', 2);
        }

        if ($this->hasImputerConstant('STRATEGY_CONSTANT')) {
            $strategies[] = $this->imputerConstant('STRATEGY_CONSTANT', 3);
        }

        return array_values(array_unique($strategies));
    }

    /**
     * Check if the imputer constant is defined (for compatibility with different PHP-ML versions).
     *
     * @param string $name
     *
     * @return bool
     */
    private function hasImputerConstant(string $name): bool
    {
        return defined(Imputer::class . '::' . $name);
    }

    /**
     * Get the value of a Normalizer constant if it exists.
     *
     * @param string $name
     *
     * @return int|null
     */
    private function normalizerConstant(string $name): ?int
    {
        $identifier = Normalizer::class . '::' . $name;

        if (! defined($identifier)) {
            return null;
        }

        return (int) constant($identifier);
    }

    /**
     * Get the value of an Imputer constant if it exists, otherwise return the fallback.
     *
     * @param string $name
     * @param int $fallback
     *
     * @return int
     */
    private function imputerConstant(string $name, int $fallback): int
    {
        $identifier = Imputer::class . '::' . $name;

        if (! defined($identifier)) {
            return $fallback;
        }

        return (int) constant($identifier);
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

    private function sampleDataset(array $samples, array $labels, float $sampleRate = 0.5): array
    {
        $count = count($samples);
        if ($count === 0) {
            return [
                'samples' => [],
                'labels' => [],
            ];
        }

        $sampleSize = (int) ceil($count * $sampleRate);
        $sampleSize = max(1, min($count, $sampleSize));

        // Random sampling without allocating an additional index array
        $indices = array_rand($samples, $sampleSize);
        if (!is_array($indices)) {
            $indices = [$indices];
        }

        $sampledSamples = [];
        $sampledLabels = [];

        foreach ($indices as $index) {
            $sampledSamples[] = $samples[$index];
            $sampledLabels[] = $labels[$index];
        }

        return [
            'samples' => $sampledSamples,
            'labels' => $sampledLabels,
        ];
    }
}
