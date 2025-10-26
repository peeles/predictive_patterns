<?php

declare(strict_types=1);

namespace App\Services\MachineLearning;

use ArgumentCountError;
use Phpml\Classification\DecisionTree;
use Phpml\Classification\KNearestNeighbors;
use Phpml\Classification\Linear\LogisticRegression as PhpmlLogisticRegression;
use Phpml\Classification\MLPClassifier;
use Phpml\Classification\NaiveBayes;
use Phpml\Classification\SVC;
use Phpml\Exception\InvalidArgumentException;
use Phpml\SupportVectorMachine\Kernel;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Factory service for creating machine learning classifier instances.
 *
 * Handles the instantiation of various classifier types with their specific
 * hyperparameters, including version compatibility for different PHP-ML versions.
 */
class ClassifierFactory
{
    /**
     * Create a classifier instance based on model type and parameters.
     *
     * @param string $modelType Classifier type (svc, knn, naive_bayes, decision_tree, mlp, logistic_regression)
     * @param array<string, mixed> $params Hyperparameters for this specific training run
     * @param array<string, mixed> $defaults Default hyperparameter values
     * @param callable|null $progressNotifier Optional progress callback for supported classifiers
     *
     * @return object Classifier instance
     * @throws InvalidArgumentException If classifier cannot be instantiated
     */
    public function create(
        string $modelType,
        array $params,
        array $defaults,
        ?callable $progressNotifier = null
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
     * @param array<string, mixed> $params Hyperparameters
     * @param array<string, mixed> $defaults Default values
     *
     * @return SVC
     */
    private function buildSvcClassifier(array $params, array $defaults): SVC
    {
        $cost = (float) ($params['cost'] ?? $defaults['cost'] ?? 1.0);
        $tolerance = (float) ($params['tolerance'] ?? $defaults['tolerance'] ?? 0.001);
        $cacheSize = (int) round((float) ($params['cache_size'] ?? $defaults['cache_size'] ?? 100.0));
        $shrinking = $this->normaliseBoolean($params['shrinking'] ?? $defaults['shrinking'] ?? true, true);
        $probabilityEstimates = $this->normaliseBoolean(
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
     * Handles version compatibility across different PHP-ML releases.
     *
     * @param array<string, mixed> $params Hyperparameters
     * @param array<string, mixed> $defaults Default values
     * @param callable|null $progressNotifier Progress callback
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
     * Tries multiple constructor signatures for version compatibility.
     *
     * @param array<string, mixed> $params Hyperparameters
     * @param array<string, mixed> $defaults Default values
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
     * Tries multiple constructor signatures for version compatibility.
     *
     * @param int $iterations Training iterations
     * @param float $learningRate Learning rate
     * @param float $lambda L2 regularisation parameter
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
     * Resolve SVC kernel type and options.
     *
     * @param string $kernel Kernel name (linear, polynomial, rbf, sigmoid)
     * @param array<string, mixed> $options Kernel-specific options
     *
     * @return array{type: int, options: array<string, float|int>, instance: object|null}
     */
    private function resolveSvcKernel(string $kernel, array $options): array
    {
        $kernel = strtolower($kernel);
        $allowed = $this->availableSvcKernelTypes();

        if (! isset($allowed[$kernel])) {
            $kernel = 'rbf';
        }

        $normalisedOptions = $this->resolveSvcKernelOptions($kernel, $options);
        $instance = $this->instantiateSvcKernelObject($kernel, $normalisedOptions);

        return [
            'type' => $allowed[$kernel],
            'options' => $normalisedOptions,
            'instance' => $instance,
        ];
    }

    /**
     * Instantiate an SVC instance if possible.
     *
     * @param string $kernel Kernel name
     * @param array<string, mixed> $options Kernel options
     *
     * @return object|null SVC instance or null if unavailable
     */
    private function instantiateSvcKernelObject(string $kernel, array $options): ?object
    {
        if (! class_exists(SVC::class) || ! class_exists(Kernel::class)) {
            return null;
        }

        // Map kernel name → php-ml Kernel constant
        $kernelConst = match (strtolower($kernel)) {
            'linear' => Kernel::LINEAR,
            'polynomial' => Kernel::POLYNOMIAL,
            'sigmoid' => Kernel::SIGMOID,
            default => Kernel::RBF,
        };

        // Hyperparameters with sensible fallbacks
        $c = (float) ($options['C'] ?? $options['c'] ?? 1.0);
        $degree = (int) ($options['degree'] ?? 3);
        $gamma = (float) ($options['gamma'] ?? 0.0);
        $coef0 = (float) ($options['coef0'] ?? 0.0);

        // Trainer/runtime options
        $tolerance = (float) ($options['tolerance'] ?? 1e-3);
        $cacheSize = (int) ($options['cacheSize'] ?? 100);
        $shrinking = (bool) ($options['shrinking'] ?? true);
        $probability = (bool) ($options['probability'] ?? false);

        try {
            return new SVC($kernelConst, $c, $degree, $gamma, $coef0, $tolerance, $cacheSize, $shrinking, $probability);
        } catch (Throwable) {
            // Fallback to minimal signature for version compatibility
            try {
                return new SVC($kernelConst, $c, $degree, $gamma, $coef0);
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * Normalise kernel-specific options with appropriate bounds.
     *
     * @param string $kernel Kernel name
     * @param array<string, mixed> $options User-provided options
     *
     * @return array<string, float|int> Normalised options
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
     * @return array<string, int> Map of kernel names to constants
     */
    private function availableSvcKernelTypes(): array
    {
        $kernels = [
            'linear' => Kernel::LINEAR,
            'polynomial' => Kernel::POLYNOMIAL,
            'sigmoid' => Kernel::SIGMOID,
            'rbf' => Kernel::RBF,
        ];

        $precomputed = Kernel::class . '::PRECOMPUTED';

        if (defined($precomputed)) {
            /** @var int $value */
            $value = constant($precomputed);
            $kernels['precomputed'] = $value;
        }

        return $kernels;
    }

    /**
     * Resolve hidden layers configuration for MLP classifier.
     *
     * @param mixed $value User input (array, JSON string, or fallback)
     *
     * @return list<int> Hidden layer sizes
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
     * Normalise a boolean value with a fallback.
     *
     * @param mixed $value Input value
     * @param bool $default Fallback value
     *
     * @return bool Normalised boolean
     */
    private function normaliseBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $filtered ?? $default;
    }

    /**
     * Clamp a floating point value between the provided bounds.
     *
     * @param float $value Value to clamp
     * @param float $min Minimum bound
     * @param float $max Maximum bound
     *
     * @return float Clamped value
     */
    private function clampFloat(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
