<?php

declare(strict_types=1);

namespace App\Services\Prediction;

use App\Support\ProbabilityScoreExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;
use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Normalizer;

/**
 * Service for scoring prediction entries using a trained classifier.
 *
 * Applies preprocessing transformations (imputation, standardisation, normalisation)
 * before generating probability scores from the classifier.
 */
class PredictionScorer
{
    /**
     * Score prediction entries with the trained classifier.
     *
     * Processes entries in chunks, applying preprocessing transformations and
     * generating probability scores for each entry.
     *
     * @param LazyCollection<int, array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, features: list<float>}> $entries
     * @param object $classifier Trained classifier instance
     * @param array{means: list<float>, std_devs: list<float>, normalizer: Normalizer, imputer: Imputer} $context
     *
     * @return list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, score: float}>
     */
    public function score(LazyCollection $entries, object $classifier, array $context): array
    {
        $means = $context['means'];
        $stdDevs = $context['std_devs'];
        $normalizer = $context['normalizer'];
        $imputer = $context['imputer'];

        return $entries
            ->chunk(1000)
            ->flatMap(function ($chunk) use ($imputer, $means, $stdDevs, $normalizer, $classifier) {
                $chunkEntries = $chunk instanceof LazyCollection ? $chunk->all() : (array) $chunk;

                if ($chunkEntries === []) {
                    return [];
                }

                $samples = [];
                $metadata = [];

                foreach ($chunkEntries as $entry) {
                    $samples[] = array_map('floatval', $entry['features']);
                    $metadata[] = [
                        'timestamp' => $entry['timestamp'],
                        'latitude' => $entry['latitude'],
                        'longitude' => $entry['longitude'],
                        'category' => $entry['category'],
                    ];
                }

                $imputer->transform($samples);

                $standardised = [];

                foreach ($samples as $sample) {
                    $standardised[] = $this->standardise($sample, $means, $stdDevs);
                }

                $normalizer->transform($standardised);

                if (method_exists($classifier, 'predictProbabilities')) {
                    $probabilities = ProbabilityScoreExtractor::extractList((array) $classifier->predictProbabilities($standardised));
                } else {
                    $predictions = $classifier->predict($standardised);
                    $probabilities = ProbabilityScoreExtractor::extractList(
                        array_map(static fn (int $value): float => $value >= 1 ? 1.0 : 0.0, $predictions)
                    );
                }

                $scored = [];

                foreach ($metadata as $index => $meta) {
                    $score = $probabilities[$index] ?? 0.0;

                    $scored[] = [
                        'timestamp' => $meta['timestamp'],
                        'latitude' => $meta['latitude'],
                        'longitude' => $meta['longitude'],
                        'category' => $meta['category'],
                        'score' => max(0.0, min(1.0, $score)),
                    ];
                }

                return $scored;
            })
            ->values()
            ->all();
    }

    /**
     * Standardise a feature row using mean and standard deviation.
     *
     * @param list<float> $features Feature values
     * @param list<float> $means Feature means
     * @param list<float> $stdDevs Feature standard deviations
     *
     * @return list<float> Standardised features
     */
    private function standardise(array $features, array $means, array $stdDevs): array
    {
        $standardised = [];

        foreach ($features as $index => $value) {
            $mean = $means[$index] ?? 0.0;
            $std = $stdDevs[$index] ?? 1.0;
            $standardised[] = ($value - $mean) / ($std > 1e-12 ? $std : 1.0);
        }

        return $standardised;
    }
}
