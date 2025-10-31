<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\MachineLearning\ClassifierFactory;
use App\Services\MachineLearning\FeatureImportanceCalculator;
use App\Services\MachineLearning\GridSearchService;
use App\Services\MachineLearning\HyperparameterResolver;
use App\Services\MachineLearning\MetricsFormatter;
use App\Services\MachineLearning\ModelArtifactPersistenceService;
use App\Services\MachineLearning\TrainingDataPreparationService;
use App\Support\Metrics\ClassificationReportGenerator;
use App\Support\ProbabilityScoreExtractor;
use Phpml\Exception\FileException;
use Phpml\Exception\InvalidOperationException;
use Phpml\Exception\LibsvmCommandException;
use Phpml\Exception\NormalizerException;
use Phpml\Exception\SerializeException;
use RuntimeException;

/**
 * Orchestrates the complete model training pipeline.
 *
 * Coordinates data preparation, grid search, training, evaluation,
 * and persistence through specialized services.
 */
class ModelTrainingService
{
    public function __construct(
        private readonly HyperparameterResolver $hyperparameterResolver,
        private readonly TrainingDataPreparationService $dataPreparationService,
        private readonly GridSearchService $gridSearchService,
        private readonly ClassifierFactory $classifierFactory,
        private readonly MetricsFormatter $metricsFormatter,
        private readonly FeatureImportanceCalculator $featureImportanceCalculator,
        private readonly ModelArtifactPersistenceService $persistenceService,
    ) {
    }

    /**
     * Train a predictive model using the specified dataset and hyperparameters.
     *
     * @param TrainingRun $run Training run instance
     * @param PredictiveModel $model Model to train
     * @param array<string, mixed> $hyperparameters User-provided hyperparameters
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

        // Phase 1: Resolve hyperparameters
        $this->notifyProgress($progressCallback, 10.0, 'Analyzing dataset schema');
        $resolvedHyperparameters = $this->hyperparameterResolver->resolve($hyperparameters);

        // Phase 2: Prepare and preprocess data
        $this->notifyProgress($progressCallback, 20.0, 'Loading and preprocessing dataset');
        $preparedData = $this->dataPreparationService->prepare($dataset, $resolvedHyperparameters);

        $this->notifyProgress($progressCallback, 40.0, 'Dataset prepared for training');

        // Phase 3: Grid search for best hyperparameters
        $this->notifyProgress($progressCallback, 50.0, 'Running cross validation grid search');
        $progressNotifier = $this->buildProgressNotifier($progressCallback, $resolvedHyperparameters);

        $gridSearch = $this->gridSearchService->search(
            $preparedData['train_samples'],
            $preparedData['train_labels'],
            $resolvedHyperparameters,
            $progressNotifier
        );

        $bestParams = $gridSearch['best_hyperparameters'];
        $cvMetrics = $gridSearch['metrics'];
        $finalHyperparameters = array_merge($resolvedHyperparameters, $bestParams);
        unset($finalHyperparameters['search_grid']);

        unset($gridSearch);
        gc_collect_cycles();

        // Phase 4: Train final classifier
        $this->notifyProgress($progressCallback, 62.0, 'Training selected algorithm');

        $classifier = $this->classifierFactory->create(
            $resolvedHyperparameters['model_type'],
            $bestParams,
            $resolvedHyperparameters,
            $progressNotifier
        );

        $classifier->train($preparedData['train_samples'], $preparedData['train_labels']);
        gc_collect_cycles();

        // Phase 5: Evaluate on validation set
        $this->notifyProgress($progressCallback, 75.0, 'Evaluating validation dataset');

        $metrics = $this->evaluateModel(
            $classifier,
            $preparedData['validation_samples'],
            $preparedData['validation_labels']
        );

        // Phase 6: Calculate feature importances
        $this->notifyProgress($progressCallback, 82.0, 'Computing feature importances');

        $featureImportances = $this->featureImportanceCalculator->calculate(
            $preparedData['train_samples'],
            $preparedData['train_labels'],
            $preparedData['feature_names']
        );

        // Phase 7: Persist model and artifacts
        $this->notifyProgress($progressCallback, 87.0, 'Persisting trained model');

        $trainingMetadata = [
            'feature_names' => $preparedData['feature_names'],
            'feature_means' => $preparedData['feature_means'],
            'feature_std_devs' => $preparedData['feature_std_devs'],
            'categories' => $preparedData['categories'],
            'category_overflowed' => $preparedData['category_overflowed'],
        ];

        $persistence = $this->persistenceService->persist(
            $model,
            $run,
            $classifier,
            $preparedData['imputer'],
            $trainingMetadata,
            $metrics,
            $finalHyperparameters,
            $cvMetrics,
            $featureImportances
        );

        // Cleanup
        unset($classifier, $preparedData);
        gc_collect_cycles();

        $this->notifyProgress($progressCallback, 92.0, 'Recording training metadata');

        return [
            'metrics' => $metrics,
            'artifact_path' => $persistence['artifact_path'],
            'version' => $persistence['version'],
            'metadata' => ['artifact_path' => $persistence['artifact_path']],
            'hyperparameters' => $finalHyperparameters,
        ];
    }

    /**
     * Evaluate classifier on validation set.
     *
     * @param object $classifier Trained classifier
     * @param list<list<float>> $samples Validation samples
     * @param list<int> $labels True labels
     *
     * @return array<string, mixed> Evaluation metrics
     */
    private function evaluateModel(object $classifier, array $samples, array $labels): array
    {
        $predicted = $classifier->predict($samples);

        $rawProbabilities = method_exists($classifier, 'predictProbabilities')
            ? (array) $classifier->predictProbabilities($samples)
            : array_map(static fn (int $value): float => $value >= 1 ? 1.0 : 0.0, $predicted);

        $probabilities = ProbabilityScoreExtractor::extractList($rawProbabilities);

        $classification = ClassificationReportGenerator::generate($labels, $predicted);
        $metrics = $this->metricsFormatter->format(
            $classification['report'],
            $classification['confusion'],
            $probabilities,
            $labels
        );

        unset($predicted, $rawProbabilities, $probabilities, $classification);
        gc_collect_cycles();

        return $metrics;
    }

    /**
     * Notify progress via callback if provided.
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
     * @param callable|null $progressCallback Main progress callback
     * @param array<string, mixed> $hyperparameters Hyperparameters
     *
     * @return callable|null Progress notifier
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