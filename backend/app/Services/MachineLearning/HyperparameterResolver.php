<?php

declare(strict_types=1);

namespace App\Services\MachineLearning;

use Illuminate\Support\Arr;

/**
 * Service for resolving, validating, and generating hyperparameter configurations.
 *
 * Handles hyperparameter normalization, validation, grid search combinations,
 * and kernel-specific options for various classifier types.
 */
class HyperparameterResolver
{
    public function __construct(
        private readonly NormalizerResolver $normalizerResolver,
        private readonly ImputerResolver $imputerResolver,
    ) {
    }

    /**
     * Resolve and validate all hyperparameters from user input.
     *
     * @param array<string, mixed> $input User-provided hyperparameters
     *
     * @return array<string, mixed> Validated and normalised hyperparameters
     */
    public function resolve(array $input): array
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
        $normalization = $this->normalizerResolver->normaliseType($input['normalization'] ?? null);
        $imputation = $this->imputerResolver->normaliseStrategy($input['imputation_strategy'] ?? null);
        $lambda = isset($input['lambda']) ? (float) $input['lambda'] : 0.0001;
        $cost = isset($input['cost']) ? (float) $input['cost'] : 1.0;
        $tolerance = isset($input['tolerance']) ? (float) $input['tolerance'] : 0.001;
        $cacheSize = isset($input['cache_size']) ? (float) $input['cache_size'] : 100.0;
        $shrinking = $this->normaliseBoolean($input['shrinking'] ?? true, true);
        $probabilityEstimates = $this->normaliseBoolean($input['probability_estimates'] ?? true, true);
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

        // Validate and clamp values
        $learningRate = max(0.0001, min($learningRate, 1.0));
        $iterations = max(100, min($iterations, 5000));
        $validationSplit = max(0.1, min($validationSplit, 0.5));
        $l2Penalty = max(0.0, min($l2Penalty, 10.0));
        $logInterval = max(1, min($logInterval, $iterations));
        $lambda = max(0.0, min($lambda, 1.0));
        $cost = $this->clampFloat($cost, 0.0001, 1000.0);
        $tolerance = $this->clampFloat($tolerance, 1.0e-6, 0.1);
        $cacheSize = $this->clampFloat($cacheSize, 1.0, 4096.0);
        $allowedKernels = ['linear', 'polynomial', 'rbf', 'sigmoid'];

        if (! in_array($kernel, $allowedKernels, true)) {
            $kernel = 'rbf';
        }

        $kernelOptions = $this->resolveSvcKernelOptions($kernel, $kernelOptionsInput);
        $k = max(1, min($k, 21));
        $maxDepth = max(2, min($maxDepth, 20));
        $minSamplesSplit = max(2, min($minSamplesSplit, 20));
        $cvFolds = max(2, min($cvFolds, 10));
        $cvValidationSplit = max(0.1, min($cvValidationSplit, 0.5));

        $availableNormalizations = $this->normalizerResolver->getAvailableTypes();

        if (! in_array($normalization, $availableNormalizations, true)) {
            $fallbackNormalization = $this->normalizerResolver->getConstant('NORM_L2');
            $normalization = $fallbackNormalization ?? ($availableNormalizations[0] ?? $normalization);
        }

        $availableStrategies = $this->imputerResolver->getAvailableStrategies();

        if (! in_array($imputation, $availableStrategies, true)) {
            $fallbackStrategy = $this->imputerResolver->getConstant('STRATEGY_MEAN', 0);
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
     * Generate hyperparameter grid for grid search.
     *
     * @param array<string, mixed> $hyperparameters Resolved hyperparameters
     *
     * @return list<array<string, mixed>> List of hyperparameter combinations
     */
    public function generateGrid(array $hyperparameters): array
    {
        $modelType = $hyperparameters['model_type'];
        $userGrid = $hyperparameters['search_grid'];

        if ($modelType === 'svc') {
            return $this->generateSvcGrid($hyperparameters, $userGrid);
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
     * @param array<string, mixed> $hyperparameters Resolved hyperparameters
     * @param array<string, list<mixed>> $userGrid User-provided grid overrides
     *
     * @return list<array<string, mixed>> List of SVC hyperparameter combinations
     */
    private function generateSvcGrid(array $hyperparameters, array $userGrid): array
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
     * @param string $key Parameter name
     * @param list<float|int> $defaults Default values
     * @param array<string, list<mixed>> $userGrid User-provided grid
     * @param float $min Minimum allowed value
     * @param float $max Maximum allowed value
     *
     * @return list<float> Merged and validated values
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
     * @param string $key Parameter name
     * @param list<mixed> $defaults Default values
     * @param array<string, list<mixed>> $userGrid User-provided grid
     *
     * @return list<bool> Merged boolean values
     */
    private function mergeGridBooleanValues(string $key, array $defaults, array $userGrid): array
    {
        $values = [];

        foreach ($defaults as $value) {
            $values[] = $this->normaliseBoolean($value, true);
        }

        if (isset($userGrid[$key])) {
            foreach ($userGrid[$key] as $value) {
                $values[] = $this->normaliseBoolean($value, true);
            }
        }

        $values = array_values(array_unique($values, SORT_REGULAR));

        return $values === [] ? [true, false] : $values;
    }

    /**
     * Merge kernel definitions from defaults and user overrides.
     *
     * @param array<string, mixed> $hyperparameters Resolved hyperparameters
     * @param array<string, list<mixed>> $userGrid User-provided grid
     *
     * @return list<array{kernel: string, kernel_options: array<string, float|int>}> Kernel configurations
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
     * Normalise kernel-specific options with appropriate bounds.
     *
     * @param string $kernel Kernel name
     * @param array<string, mixed> $options User-provided options
     *
     * @return array<string, float|int> Normalised options
     */
    public function resolveSvcKernelOptions(string $kernel, array $options): array
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
     * Normalise hyperparameter grid from various input formats.
     *
     * @param mixed $grid User-provided grid
     *
     * @return array<string, list<mixed>> Normalised grid
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
