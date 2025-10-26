<?php

declare(strict_types=1);

namespace App\Services\MachineLearning;

use Phpml\Preprocessing\Normalizer;

/**
 * Centralised service for resolving Phpml Normalizer constants and types.
 *
 * Handles the mapping between string normaliser identifiers (e.g., 'l1', 'l2')
 * and their corresponding Phpml constant values, with graceful fallbacks for
 * different Phpml versions.
 */
class NormalizerResolver
{
    /**
     * Normalise a mixed normaliser type value to its integer constant.
     *
     * Accepts:
     * - Integer constant values (passed through)
     * - String identifiers ('l1', 'l2', 'linf', 'max', 'std')
     * - Falls back to NORM_L2 if value is invalid
     *
     * @param mixed $value User-provided normaliser type
     *
     * @return int Phpml Normalizer constant value
     */
    public function normaliseType(mixed $value): int
    {
        $default = $this->getConstant('NORM_L2') ?? 2;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalised = strtolower(trim($value));
            $maxNorm = $this->getConstant('NORM_MAX') ?? $this->getConstant('NORM_LINF');
            $stdNorm = $this->getConstant('NORM_STD');

            $map = array_filter([
                'l1' => $this->getConstant('NORM_L1'),
                'l2' => $this->getConstant('NORM_L2'),
                'linf' => $maxNorm,
                'inf' => $maxNorm,
                'max' => $maxNorm,
                'maxnorm' => $maxNorm,
                'std' => $stdNorm,
            ], static fn ($val): bool => $val !== null);

            if (isset($map[$normalised])) {
                return $map[$normalised];
            }
        }

        return $default;
    }

    /**
     * Get the value of a Normalizer constant if it exists.
     *
     * @param string $name Constant name without class prefix (e.g., 'NORM_L2')
     *
     * @return int|null Constant value, or null if not defined
     */
    public function getConstant(string $name): ?int
    {
        $identifier = Normalizer::class . '::' . $name;

        if (! defined($identifier)) {
            return null;
        }

        return (int) constant($identifier);
    }

    /**
     * Get a human-readable description of a normaliser type constant.
     *
     * @param int $type Phpml Normalizer constant value
     *
     * @return string Human-readable normaliser type ('l1', 'l2', 'max', 'std', 'unknown')
     */
    public function describeType(int $type): string
    {
        $candidates = [];

        $l1 = $this->getConstant('NORM_L1');
        if ($l1 !== null) {
            $candidates['l1'] = $l1;
        }

        $l2 = $this->getConstant('NORM_L2');
        if ($l2 !== null) {
            $candidates['l2'] = $l2;
        }

        $max = $this->getConstant('NORM_MAX') ?? $this->getConstant('NORM_LINF');
        if ($max !== null) {
            $candidates['max'] = $max;
        }

        $std = $this->getConstant('NORM_STD');
        if ($std !== null) {
            $candidates['std'] = $std;
        }

        foreach ($candidates as $label => $value) {
            if ($value === $type) {
                return $label;
            }
        }

        return 'unknown';
    }

    /**
     * Get all available normaliser type constants for this Phpml version.
     *
     * @return list<int> Array of available Normalizer constant values
     */
    public function getAvailableTypes(): array
    {
        $types = [];

        // Try L1 and L2 norms
        foreach (['NORM_L1', 'NORM_L2'] as $name) {
            $value = $this->getConstant($name);
            if ($value !== null) {
                $types[] = $value;
            }
        }

        // Try max/infinity norm (has multiple aliases)
        foreach (['NORM_MAX', 'NORM_LINF'] as $name) {
            $value = $this->getConstant($name);
            if ($value !== null) {
                $types[] = $value;
                break; // Only add once
            }
        }

        // Try standard deviation norm
        $std = $this->getConstant('NORM_STD');
        if ($std !== null) {
            $types[] = $std;
        }

        // Fallback to L2 constant if nothing found
        if ($types === []) {
            $types[] = 2;
        }

        return array_values(array_unique($types));
    }
}
