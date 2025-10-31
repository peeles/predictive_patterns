<?php

declare(strict_types=1);

namespace App\Services\MachineLearning;

use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use Illuminate\Support\Facades\Storage;
use Phpml\Classification\Linear\LogisticRegression as PhpmlLogisticRegression;
use Phpml\Exception\FileException;
use Phpml\Exception\SerializeException;
use Phpml\ModelManager;
use Phpml\Preprocessing\Imputer;
use ReflectionClass;

/**
 * Service responsible for persisting trained models and their artifacts.
 *
 * Handles creating model artifacts (JSON metadata) and binary model files
 * with proper versioning and storage organization.
 */
class ModelArtifactPersistenceService
{
    public function __construct(
        private readonly ImputerResolver $imputerResolver,
        private readonly NormalizerResolver $normalizerResolver,
    ) {
    }

    /**
     * Persist trained model and create artifact metadata.
     *
     * @param PredictiveModel $model Model being trained
     * @param TrainingRun $run Training run instance
     * @param object $classifier Trained classifier
     * @param Imputer $imputer Fitted imputer
     * @param array<string, mixed> $trainingData Training metadata
     * @param array<string, mixed> $metrics Evaluation metrics
     * @param array<string, mixed> $hyperparameters Final hyperparameters
     * @param array<string, mixed> $cvMetrics Cross-validation metrics
     * @param array<string, float> $featureImportances Feature importance scores
     *
     * @return array{
     *     artifact_path: string,
     *     version: string
     * }
     *
     * @throws FileException
     * @throws SerializeException
     */
    public function persist(
        PredictiveModel $model,
        TrainingRun $run,
        object $classifier,
        Imputer $imputer,
        array $trainingData,
        array $metrics,
        array $hyperparameters,
        array $cvMetrics,
        array $featureImportances
    ): array {
        $disk = Storage::disk('local');
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
            'model_type' => $hyperparameters['model_type'],
            'feature_names' => $trainingData['feature_names'],
            'feature_means' => $trainingData['feature_means'],
            'feature_std_devs' => $trainingData['feature_std_devs'],
            'imputer' => [
                'strategy' => $this->imputerResolver->describeStrategy($hyperparameters['imputation_strategy']),
                'statistics' => $this->extractImputerStatistics($imputer),
            ],
            'categories' => $trainingData['categories'],
            'category_overflowed' => $trainingData['category_overflowed'],
            'hyperparameters' => $hyperparameters,
            'metrics' => $metrics,
            'grid_search' => $cvMetrics,
            'normalization' => [
                'type' => $this->normalizerResolver->describeType($hyperparameters['normalization']),
            ],
            'feature_importances' => $featureImportances,
            'model_file' => $modelFilePath,
        ];

        $disk->put($artifactPath, json_encode($artifact, JSON_PRETTY_PRINT));

        // Clear progress callback before serialization
        if ($classifier instanceof PhpmlLogisticRegression && method_exists($classifier, 'setProgressCallback')) {
            $classifier->setProgressCallback(null);
        }

        $modelManager = new ModelManager();
        $modelManager->saveToFile($classifier, $disk->path($modelFilePath));

        return [
            'artifact_path' => $artifactPath,
            'version' => $version,
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
}