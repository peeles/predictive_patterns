<?php

declare(strict_types=1);

namespace App\Services\Prediction;

use App\Models\Prediction;

/**
 * Service for calculating and persisting SHAP (SHapley Additive exPlanations) values.
 *
 * Ranks feature importance contributions and persists them to the database
 * for model interpretability and explainability.
 */
class ShapValueCalculator
{
    /**
     * Rank feature influences by contribution magnitude.
     *
     * Extracts feature importance from the artifact and returns the top features
     * sorted by absolute contribution value.
     *
     * @param array<string, mixed> $artifact Training artifact containing feature importances
     * @param int $limit Maximum number of features to return
     *
     * @return list<array{name: string, contribution: float, details?: array|null}>
     */
    public function rankFeatures(array $artifact, int $limit = 5): array
    {
        $importances = [];

        if (isset($artifact['feature_importances']) && is_array($artifact['feature_importances'])) {
            foreach ($artifact['feature_importances'] as $importance) {
                if (! is_array($importance)) {
                    continue;
                }

                $name = (string) ($importance['name'] ?? '');

                if ($name === '') {
                    continue;
                }

                $contribution = (float) ($importance['contribution'] ?? 0.0);
                $details = $importance['details'] ?? null;

                if ($details !== null && ! is_array($details)) {
                    $details = ['value' => $details];
                }

                $importances[] = [
                    'name' => $this->prettifyFeatureName($name),
                    'contribution' => round($contribution, 4),
                    'details' => $details,
                ];
            }
        }

        if ($importances === []) {
            $names = array_map(static fn ($name) => (string) $name, $artifact['feature_names'] ?? []);

            foreach ($names as $name) {
                $importances[] = [
                    'name' => $this->prettifyFeatureName($name),
                    'contribution' => 0.0,
                ];
            }
        }

        usort($importances, static fn ($a, $b) => abs($b['contribution']) <=> abs($a['contribution']));

        return array_slice($importances, 0, $limit);
    }

    /**
     * Persist SHAP values to the database.
     *
     * Deletes existing SHAP values for the prediction and creates new records
     * for the provided top features.
     *
     * @param Prediction $prediction Prediction model instance
     * @param list<array{name: string, contribution: float, details?: array|null}> $topFeatures Top features to persist
     *
     * @return void
     */
    public function persist(Prediction $prediction, array $topFeatures): void
    {
        $prediction->shapValues()->delete();

        if ($topFeatures === []) {
            return;
        }

        $records = [];

        foreach ($topFeatures as $feature) {
            $name = (string) ($feature['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $contribution = (float) ($feature['contribution'] ?? 0.0);
            $details = $feature['details'] ?? null;

            if ($details !== null && ! is_array($details)) {
                $details = ['value' => $details];
            }

            $records[] = [
                'feature_name' => $name,
                'value' => round($contribution, 6),
                'details' => $details,
            ];
        }

        if ($records === []) {
            return;
        }

        $prediction->shapValues()->createMany($records);
    }

    /**
     * Prettify a feature name for display.
     *
     * Converts snake_case feature names to Title Case with spaces.
     *
     * @param string $name Feature name in snake_case
     *
     * @return string Prettified feature name
     */
    private function prettifyFeatureName(string $name): string
    {
        $pretty = str_replace('_', ' ', $name);

        return ucwords($pretty);
    }
}
