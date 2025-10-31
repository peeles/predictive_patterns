<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Services\Dataset\ColumnMapper;
use App\Services\MachineLearning\ImputerResolver;
use App\Services\MachineLearning\NormalizerResolver;
use App\Support\DatasetRowBuffer;
use App\Support\ErrorSanitizer;
use App\Support\DatasetRowPreprocessor;
use App\Support\Metrics\ClassificationReportGenerator;
use App\Support\Phpml\ImputerFactory;
use App\Support\ProbabilityScoreExtractor;
use Illuminate\Support\Facades\Storage;
use Phpml\Exception\FileException;
use Phpml\Exception\InvalidOperationException;
use Phpml\Exception\NormalizerException;
use Phpml\Exception\SerializeException;
use Phpml\ModelManager;
use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Normalizer;
use RuntimeException;
use Throwable;

class ModelEvaluationService
{
    public function __construct(
        private readonly ColumnMapper $columnMapper,
        private readonly NormalizerResolver $normalizerResolver,
        private readonly ImputerResolver $imputerResolver,
    ) {
    }
    /**
     * Evaluates a trained predictive model against a labeled dataset and computes various classification metrics.
     *
     * @param PredictiveModel $model
     * @param Dataset $dataset
     * @param callable|null $progressCallback
     *
     * @return array{
     *     accuracy: float,
     *     macro_precision: float,
     *     macro_recall: float,
     *     macro_f1: float,
     *     weighted_precision: float,
     *     weighted_recall: float,
     *     weighted_f1: float,
     *     per_class: array<int, array{precision: float, recall: float, f1: float, support: int}>,
     *     confusion_matrix: array{labels: list<int>, matrix: list<list<int>>},
     *     auc: float
     * }
     *
     * @throws FileException
     * @throws SerializeException|NormalizerException
     * @throws InvalidOperationException
     */
    public function evaluate(PredictiveModel $model, Dataset $dataset, ?callable $progressCallback = null): array
    {
        $artifactPath = $this->resolveArtifactPath($model);
        $disk = Storage::disk('local');

        if (! $disk->exists($artifactPath)) {
            throw ErrorSanitizer::exception(
                sprintf('Model artifact "%s" was not found.', $artifactPath),
                ErrorSanitizer::ERROR_ARTIFACT_NOT_FOUND,
                ['model_id' => $model->getKey()]
            );
        }

        $artifactContents = $disk->get($artifactPath);
        $artifact = json_decode($artifactContents, true);

        if (! is_array($artifact)) {
            throw new RuntimeException('Model artifact could not be decoded.');
        }

        $featureMeans = $this->extractNumericList($artifact['feature_means'] ?? null, 'feature_means');
        $featureStdDevs = $this->extractNumericList($artifact['feature_std_devs'] ?? null, 'feature_std_devs');
        $normalizer = $this->resolveNormalizer($artifact['normalization'] ?? null);
        $imputer = $this->resolveImputer($artifact['imputer'] ?? null);
        $modelFile = $artifact['model_file'] ?? null;

        if (! is_string($modelFile) || $modelFile === '') {
            throw new RuntimeException('Model artifact is missing a persisted model file.');
        }

        if (! $disk->exists($modelFile)) {
            throw ErrorSanitizer::exception(
                sprintf('Trained model file "%s" was not found.', $modelFile),
                ErrorSanitizer::ERROR_FILE_NOT_FOUND,
                ['model_id' => $model->getKey(), 'model_file' => basename($modelFile)]
            );
        }

        if ($dataset->file_path === null) {
            throw new RuntimeException('Evaluation dataset is missing a file path.');
        }

        if (! $disk->exists($dataset->file_path)) {
            throw ErrorSanitizer::exception(
                sprintf('Evaluation dataset "%s" was not found.', $dataset->file_path),
                ErrorSanitizer::ERROR_DATASET_NOT_FOUND,
                ['dataset_id' => $dataset->id]
            );
        }

        if ($progressCallback !== null) {
            $progressCallback(15.0);
        }

        $categories = $this->extractStringList($artifact['categories'] ?? null, 'categories');

        if ($progressCallback !== null) {
            $progressCallback(35.0);
        }

        $columnMap = $this->columnMapper->resolveColumnMap($dataset);
        $buffer = DatasetRowPreprocessor::prepareEvaluationData(
            $disk->path($dataset->file_path),
            $columnMap,
            $categories
        );

        if ($buffer->count() === 0) {
            throw new RuntimeException('No usable rows were found in the evaluation dataset.');
        }

        $modelManager = new ModelManager();
        $classifier = $modelManager->restoreFromFile($disk->path($modelFile));

        if ($progressCallback !== null) {
            $progressCallback(55.0);
        }

        $metrics = $this->evaluateBuffer(
            $buffer,
            $classifier,
            $featureMeans,
            $featureStdDevs,
            $normalizer,
            $imputer
        );

        if ($progressCallback !== null) {
            $progressCallback(85.0);
        }

        return $metrics;
    }

    private function resolveArtifactPath(PredictiveModel $model): string
    {
        $artifactPath = $this->resolveArtifactPathFromMetadata($model->metadata ?? null);

        if ($artifactPath === null) {
            $artifactPath = $this->findMostRecentArtifactOnDisk($model);
        }

        if ($artifactPath === null) {
            throw new RuntimeException('Model metadata does not contain an artifact path and no artifact could be located on disk.');
        }

        return $artifactPath;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function resolveArtifactPathFromMetadata(?array $metadata): ?string
    {
        if (! is_array($metadata)) {
            return null;
        }

        $artifactPath = $metadata['artifact_path'] ?? null;

        if (is_string($artifactPath) && $artifactPath !== '') {
            return $artifactPath;
        }

        if (isset($metadata['artifacts']) && is_array($metadata['artifacts'])) {
            $artifacts = $metadata['artifacts'];

            if ($artifacts !== []) {
                $last = end($artifacts);

                if (is_array($last)) {
                    $path = $last['path'] ?? null;

                    if (is_string($path) && $path !== '') {
                        return $path;
                    }
                }
            }
        }

        return null;
    }

    private function findMostRecentArtifactOnDisk(PredictiveModel $model): ?string
    {
        $disk = Storage::disk('local');
        $directory = sprintf('models/%s', $model->getKey());

        $files = array_filter(
            $disk->files($directory),
            static fn (string $path): bool => str_ends_with(strtolower($path), '.json')
        );

        if ($files === []) {
            return null;
        }

        rsort($files);

        return $files[0] ?? null;
    }


    /**
     * @throws InvalidOperationException
     */
    private function evaluateBuffer(
        DatasetRowBuffer $buffer,
        object $classifier,
        array $means,
        array $stdDevs,
        Normalizer $normalizer,
        Imputer $imputer
    ): array {
        $samples = [];
        $labels = [];

        foreach ($buffer as $row) {
            $samples[] = array_map('floatval', $row['features']);
            $labels[] = (int) $row['label'];
        }

        if ($samples === []) {
            throw new RuntimeException('No samples available for evaluation.');
        }

        $imputer->transform($samples);
        $standardized = [];

        foreach ($samples as $sample) {
            $standardized[] = $this->normalizeRow($sample, $means, $stdDevs);
        }

        $normalizer->transform($standardized);

        $predictions = $classifier->predict($standardized);
        $rawProbabilities = method_exists($classifier, 'predictProbabilities')
            ? (array) $classifier->predictProbabilities($standardized)
            : array_map(static fn (int $value): float => $value >= 1 ? 1.0 : 0.0, $predictions);
        $probabilities = ProbabilityScoreExtractor::extractList($rawProbabilities);

        $classification = ClassificationReportGenerator::generate($labels, $predictions);
        $report = $classification['report'];
        $confusion = $classification['confusion'];

        $perClass = array_map(function ($metrics) {
            return [
                'precision' => round($metrics['precision'], 4),
                'recall' => round($metrics['recall'], 4),
                'f1' => round($metrics['f1'], 4),
                'support' => $metrics['support'],
            ];
        }, $report['per_class']);

        return [
            'accuracy' => round($report['accuracy'], 4),
            'macro_precision' => round($report['macro']['precision'], 4),
            'macro_recall' => round($report['macro']['recall'], 4),
            'macro_f1' => round($report['macro']['f1'], 4),
            'weighted_precision' => round($report['weighted']['precision'], 4),
            'weighted_recall' => round($report['weighted']['recall'], 4),
            'weighted_f1' => round($report['weighted']['f1'], 4),
            'per_class' => $perClass,
            'confusion_matrix' => $confusion,
            'auc' => $this->computeAuc($labels, $probabilities),
        ];
    }

    /**
     * Resolves a Normalizer instance based on the provided normalization configuration.
     *
     * @param mixed $normalization
     *
     * @return Normalizer
     * @throws NormalizerException
     */
    private function resolveNormalizer(mixed $normalization): Normalizer
    {
        $candidate = $normalization;

        if (is_array($normalization)) {
            $candidate = $normalization['type'] ?? null;
        }

        $type = $this->normalizerResolver->normaliseType($candidate);

        try {
            return new Normalizer($type);
        } catch (Throwable) {
            $fallback = $this->normalizerResolver->getConstant('NORM_L2') ?? 2;

            return new Normalizer($fallback);
        }
    }

    private function normalizeRow(array $features, array $means, array $stdDevs): array
    {
        if (count($means) !== count($stdDevs)) {
            throw new RuntimeException('Normalization parameters are misaligned.');
        }

        if (count($features) !== count($means)) {
            throw new RuntimeException('Feature vector size mismatch during normalization.');
        }

        return array_map(
            static fn ($value, $mean, $std) => ($value - $mean) / ($std > 0 ? $std : 1.0),
            $features,
            $means,
            $stdDevs
        );
    }

    /**
     * @param list<float>|mixed $values
     *
     * @return list<float>
     */
    private function extractNumericList(mixed $values, string $key): array
    {
        if (! is_array($values)) {
            throw new RuntimeException(sprintf('Model artifact is missing "%s".', $key));
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_numeric($value)) {
                throw new RuntimeException(sprintf('Model artifact contains invalid values for "%s".', $key));
            }

            $normalized[] = (float) $value;
        }

        if ($normalized === []) {
            throw new RuntimeException(sprintf('Model artifact does not contain any values for "%s".', $key));
        }

        return $normalized;
    }

    /**
     * @param list<string>|mixed $values
     *
     * @return list<string>
     */
    private function extractStringList(mixed $values, string $key): array
    {
        if ($values === null) {
            return [];
        }

        if (! is_array($values)) {
            throw new RuntimeException(sprintf('Model artifact contains invalid values for "%s".', $key));
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new RuntimeException(sprintf('Model artifact contains invalid values for "%s".', $key));
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    private function resolveImputer(mixed $imputer): Imputer
    {
        $strategySource = $imputer;
        $statistics = [];
        $missingValue = null;
        $fillValue = 0.0;

        if (is_array($imputer)) {
            $strategySource = $imputer['strategy'] ?? null;

            if (isset($imputer['statistics']) && is_array($imputer['statistics'])) {
                $statistics = array_map('floatval', $imputer['statistics']);
            }
            if (array_key_exists('missing_value', $imputer)) {
                $missingValue = $imputer['missing_value'];
            }

            if (array_key_exists('fill_value', $imputer)) {
                $fillValue = $imputer['fill_value'];
            }
        }

        $strategy = $this->imputerResolver->normaliseStrategy($strategySource);

        return ImputerFactory::create($strategy, $missingValue, $statistics, $fillValue);
    }

    /**
     * @param list<int> $labels
     * @param list<float> $scores
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
}
