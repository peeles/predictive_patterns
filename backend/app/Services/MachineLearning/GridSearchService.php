<?php

declare(strict_types=1);

namespace App\Services\MachineLearning;

use App\Support\Metrics\ClassificationReportGenerator;
use App\Support\Phpml\ImputerFactory;
use Phpml\CrossValidation\RandomSplit;
use Phpml\Dataset\ArrayDataset;
use Phpml\Exception\InvalidArgumentException;
use Phpml\Preprocessing\Normalizer;

/**
 * Service for performing grid search with cross-validation.
 *
 * Evaluates multiple hyperparameter combinations using k-fold cross-validation
 * to find the best performing configuration.
 */
class GridSearchService
{
    public function __construct(
        private readonly HyperparameterResolver $hyperparameterResolver = new HyperparameterResolver(),
        private readonly ClassifierFactory $classifierFactory = new ClassifierFactory(),
        private readonly DataPreprocessor $dataPreprocessor = new DataPreprocessor(),
    ) {
    }

    /**
     * Perform grid search with cross-validation.
     *
     * @param list<list<float>> $samples Training samples
     * @param list<int> $labels Training labels
     * @param array<string, mixed> $hyperparameters Resolved hyperparameters
     * @param callable|null $progressNotifier Progress callback
     *
     * @return array{
     *     best_hyperparameters: array<string, mixed>,
     *     metrics: array{
     *         evaluations: list<array{hyperparameters: array<string, mixed>, accuracy: float, macro_f1: float}>,
     *         best_accuracy: float,
     *         best_macro_f1: float,
     *         best_hyperparameters: array<string, mixed>
     *     }
     * }
     *
     * @throws InvalidArgumentException
     */
    public function search(
        array $samples,
        array $labels,
        array $hyperparameters,
        ?callable $progressNotifier = null
    ): array {
        $grid = $this->hyperparameterResolver->generateGrid($hyperparameters);

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

                $statistics = $this->dataPreprocessor->computeStatistics($trainSamples);
                $trainSamples = $this->dataPreprocessor->applyStandardisation($trainSamples, $statistics['means'], $statistics['std_devs']);
                $testSamples = $this->dataPreprocessor->applyStandardisation($testSamples, $statistics['means'], $statistics['std_devs']);

                $normalizer = new Normalizer($hyperparameters['normalization']);
                $this->dataPreprocessor->normaliseSamplesSafely($normalizer, $trainSamples);
                $this->dataPreprocessor->normaliseSamplesSafely($normalizer, $testSamples);

                $classifier = $this->classifierFactory->create(
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

                // Free fold-specific memory to prevent accumulation
                unset($split, $trainSamples, $trainLabels, $testSamples, $testLabels);
                unset($imputer, $normalizer, $classifier, $predictions, $classification, $report, $statistics);

                // Periodic garbage collection every 3 folds to prevent memory buildup
                if ($fold > 0 && $fold % 3 === 0) {
                    gc_collect_cycles();
                }
            }

            $averageScore = $scores === [] ? 0.0 : array_sum($scores) / count($scores);
            $averageMacro = $macroScores === [] ? 0.0 : array_sum($macroScores) / count($macroScores);

            // Clean up after each parameter combination
            unset($scores, $macroScores);

            // Run garbage collection after each grid parameter to free classifier memory
            if ($index > 0 && $index % 2 === 0) {
                gc_collect_cycles();
            }

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
     * Check if an exception is due to insufficient samples.
     *
     * @param InvalidArgumentException $exception Exception to check
     *
     * @return bool True if exception is due to insufficient samples
     */
    private function isInsufficientSampleException(InvalidArgumentException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'zero elements') || str_contains($message, 'at least 2 elements');
    }
}
