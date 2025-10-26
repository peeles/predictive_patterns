<?php

declare(strict_types=1);

namespace App\Services\MachineLearning;

use Phpml\Preprocessing\Imputer;

/**
 * Centralised service for resolving Phpml Imputer constants and strategies.
 *
 * Handles the mapping between string imputation strategy identifiers
 * (e.g., 'mean', 'median', 'most_frequent') and their corresponding Phpml
 * constant values, with graceful fallbacks for different Phpml versions.
 */
class ImputerResolver
{
    /**
     * Normalise a mixed imputation strategy value to its integer constant.
     *
     * Accepts:
     * - Integer constant values (passed through)
     * - String identifiers ('mean', 'median', 'most_frequent', 'constant')
     * - Falls back to STRATEGY_MEAN if value is invalid
     *
     * @param mixed $value User-provided imputation strategy
     *
     * @return int Phpml Imputer constant value
     */
    public function normaliseStrategy(mixed $value): int
    {
        $default = $this->getConstant('STRATEGY_MEAN', 0);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalised = strtolower(trim($value));
            $map = array_filter([
                'mean' => $this->getConstant('STRATEGY_MEAN', $default),
                'median' => $this->hasConstant('STRATEGY_MEDIAN')
                    ? $this->getConstant('STRATEGY_MEDIAN', 1)
                    : null,
                'most_frequent' => $this->hasConstant('STRATEGY_MOST_FREQUENT')
                    ? $this->getConstant('STRATEGY_MOST_FREQUENT', 2)
                    : null,
                'mostfrequent' => $this->hasConstant('STRATEGY_MOST_FREQUENT')
                    ? $this->getConstant('STRATEGY_MOST_FREQUENT', 2)
                    : null,
                'constant' => $this->hasConstant('STRATEGY_CONSTANT')
                    ? $this->getConstant('STRATEGY_CONSTANT', 3)
                    : null,
            ], static fn ($candidate) => $candidate !== null);

            if (array_key_exists($normalised, $map)) {
                return (int) $map[$normalised];
            }
        }

        return $default;
    }

    /**
     * Get the value of an Imputer constant if it exists, otherwise return the fallback.
     *
     * @param string $name Constant name without class prefix (e.g., 'STRATEGY_MEAN')
     * @param int $fallback Default value if constant not defined
     *
     * @return int Constant value or fallback
     */
    public function getConstant(string $name, int $fallback): int
    {
        $identifier = Imputer::class . '::' . $name;

        if (! defined($identifier)) {
            return $fallback;
        }

        return (int) constant($identifier);
    }

    /**
     * Check if an Imputer constant is defined.
     *
     * Useful for checking compatibility with different Phpml versions.
     *
     * @param string $name Constant name without class prefix (e.g., 'STRATEGY_MEDIAN')
     *
     * @return bool True if constant exists
     */
    public function hasConstant(string $name): bool
    {
        return defined(Imputer::class . '::' . $name);
    }

    /**
     * Get a human-readable description of an imputation strategy constant.
     *
     * @param int $strategy Phpml Imputer constant value
     *
     * @return string Human-readable strategy name ('mean', 'median', 'most_frequent', 'constant', or numeric string)
     */
    public function describeStrategy(int $strategy): string
    {
        $candidates = [
            'mean' => $this->getConstant('STRATEGY_MEAN', 0),
        ];

        if ($this->hasConstant('STRATEGY_MEDIAN')) {
            $candidates['median'] = $this->getConstant('STRATEGY_MEDIAN', 1);
        }

        if ($this->hasConstant('STRATEGY_MOST_FREQUENT')) {
            $candidates['most_frequent'] = $this->getConstant('STRATEGY_MOST_FREQUENT', 2);
        }

        if ($this->hasConstant('STRATEGY_CONSTANT')) {
            $candidates['constant'] = $this->getConstant('STRATEGY_CONSTANT', 3);
        }

        foreach ($candidates as $label => $value) {
            if ($value === $strategy) {
                return $label;
            }
        }

        return (string) $strategy;
    }

    /**
     * Get all available imputation strategy constants for this Phpml version.
     *
     * @return list<int> Array of available Imputer constant values
     */
    public function getAvailableStrategies(): array
    {
        $strategies = [$this->getConstant('STRATEGY_MEAN', 0)];

        if ($this->hasConstant('STRATEGY_MEDIAN')) {
            $strategies[] = $this->getConstant('STRATEGY_MEDIAN', 1);
        }

        if ($this->hasConstant('STRATEGY_MOST_FREQUENT')) {
            $strategies[] = $this->getConstant('STRATEGY_MOST_FREQUENT', 2);
        }

        if ($this->hasConstant('STRATEGY_CONSTANT')) {
            $strategies[] = $this->getConstant('STRATEGY_CONSTANT', 3);
        }

        return array_values(array_unique($strategies));
    }
}
