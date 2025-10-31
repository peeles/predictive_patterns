<?php

namespace App\Services;

use App\Services\H3\H3CacheManager;
use App\Services\H3\H3ParameterNormalizer;
use App\Services\H3\H3QueryBuilder;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Aggregates dataset record counts across H3 cells with optional temporal and category filtering.
 */
class H3AggregationService
{
    private const SUPPORTED_RESOLUTIONS = [6, 7, 8];

    private const CACHE_TTL_MINUTES = 10;

    public function __construct(
        private readonly H3CacheManager $cacheManager,
        private readonly H3QueryBuilder $queryBuilder,
        private readonly H3ParameterNormalizer $parameterNormalizer,
    ) {
    }

    /**
     * Aggregate dataset records across H3 cells intersecting the provided bounding box.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     *
     * @return \App\DataTransferObjects\HexAggregate[]
     *
     * @throws InvalidArgumentException|\Psr\SimpleCache\InvalidArgumentException
     */
    public function aggregateByBoundingBox(
        array $boundingBox,
        int $resolution,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        ?string $category = null,
        ?int $timeOfDayStart = null,
        ?int $timeOfDayEnd = null,
        ?string $severity = null,
    ): array {
        if (! in_array($resolution, self::SUPPORTED_RESOLUTIONS, true)) {
            throw new InvalidArgumentException('Unsupported resolution supplied.');
        }

        $cacheKey = $this->cacheManager->buildKey(
            $boundingBox,
            $resolution,
            $from,
            $to,
            $category,
            $timeOfDayStart,
            $timeOfDayEnd,
            $severity,
        );

        $tags = $this->cacheManager->buildTags($resolution, $from, $to);

        return $this->cacheManager->rememberWithTags(
            $cacheKey,
            $tags,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->queryBuilder->aggregate(
                $boundingBox,
                $resolution,
                $from,
                $to,
                $category,
                $timeOfDayStart,
                $timeOfDayEnd,
                $severity,
            )
        );
    }

    /**
     * Convenience wrapper accepting a string bounding box and returning keyed results for controllers.
     *
     * @return array<string, array{count: int, categories: array<string, int>, statistics: array<string, mixed>}> indexed by H3 cell id
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function aggregateByBbox(
        string $bboxString,
        int $resolution,
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        ?string $category = null,
        ?int $timeOfDayStart = null,
        ?int $timeOfDayEnd = null,
        ?string $severity = null,
        ?float $confidenceLevel = null,
    ): array {
        $boundingBox = $this->parameterNormalizer->parseBoundingBox($bboxString);
        $fromCarbon = $from instanceof CarbonInterface ? $from : $this->parameterNormalizer->parseDate($from);
        $toCarbon = $to instanceof CarbonInterface ? $to : $this->parameterNormalizer->parseDate($to);
        $category = $this->parameterNormalizer->normaliseCategory($category);

        [$timeOfDayStart, $timeOfDayEnd] = $this->parameterNormalizer->normaliseTimeOfDayRange($timeOfDayStart, $timeOfDayEnd);
        $severity = $this->parameterNormalizer->normaliseSeverity($severity);
        $confidenceLevel = $this->parameterNormalizer->normaliseConfidenceLevel($confidenceLevel);

        $aggregates = $this->aggregateByBoundingBox(
            $boundingBox,
            $resolution,
            $fromCarbon,
            $toCarbon,
            $category,
            $timeOfDayStart,
            $timeOfDayEnd,
            $severity,
        );

        $result = [];
        foreach ($aggregates as $aggregate) {
            $meanRisk = $aggregate->meanRiskScore();
            $confidenceInterval = $aggregate->confidenceInterval($confidenceLevel);

            $result[$aggregate->h3Index] = [
                'count' => $aggregate->count,
                'categories' => $aggregate->categories,
                'statistics' => [
                    'mean_risk_score' => $meanRisk !== null ? round($meanRisk, 4) : null,
                    'confidence_interval' => $confidenceInterval !== null ? [
                        'lower' => round($confidenceInterval['lower'], 4),
                        'upper' => round($confidenceInterval['upper'], 4),
                        'level' => $confidenceInterval['level'],
                    ] : null,
                    'sample_size' => $aggregate->riskValueCount,
                    'confidence_level' => $confidenceInterval['level'] ?? $confidenceLevel,
                ],
            ];
        }

        return $result;
    }

    /**
     * Increment the cache version so downstream caches pick up fresh aggregates.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function bumpCacheVersion(): int
    {
        return $this->cacheManager->bumpVersion();
    }

    /**
     * Expose the current cache version for external callers that need to build compatible keys.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function cacheVersion(): int
    {
        return $this->cacheManager->getVersion();
    }

    /**
     * Invalidate cached aggregates for the supplied records, using tag-based flushing when available.
     *
     * @param array<int, array<string, mixed>> $records
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function invalidateAggregatesForRecords(array $records): void
    {
        $this->cacheManager->invalidateForRecords($records);
    }

    /**
     * Determine whether the underlying cache store supports tagging.
     */
    public function supportsTagging(): bool
    {
        return $this->cacheManager->supportsTagging();
    }

    /**
     * Provide the tag set used for cached aggregates that match the given filters.
     *
     * @return list<string>
     */
    public function cacheTags(int $resolution, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return $this->cacheManager->buildTags($resolution, $from, $to);
    }
}
