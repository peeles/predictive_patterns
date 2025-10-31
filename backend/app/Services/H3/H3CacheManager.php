<?php

declare(strict_types=1);

namespace App\Services\H3;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Service for managing H3 aggregation cache.
 *
 * Handles cache versioning, tag-based invalidation, and cache key generation.
 */
class H3CacheManager
{
    private const CACHE_PREFIX = 'h3_aggregations:';

    public const CACHE_VERSION_KEY = self::CACHE_PREFIX.'version';

    private const TAG_PREFIX = self::CACHE_PREFIX.'tag:';

    private const TAG_ALL = self::TAG_PREFIX.'all';

    private const TAG_MONTH_ALL = self::TAG_PREFIX.'month:all';

    private const SUPPORTED_RESOLUTIONS = [6, 7, 8];

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    /**
     * Build a cache key that incorporates the filter parameters and version.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function buildKey(
        array $boundingBox,
        int $resolution,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        ?string $category,
        ?int $timeOfDayStart,
        ?int $timeOfDayEnd,
        ?string $severity,
    ): string {
        $normalizedBbox = array_map(
            static fn (mixed $value): string => number_format((float) $value, 6, '.', ''),
            $boundingBox
        );

        $fromKey = $from?->toIso8601String() ?? 'null';
        $toKey = $to?->toIso8601String() ?? 'null';
        $categoryKey = $category ?? 'null';
        $timeStartKey = $timeOfDayStart !== null ? (string) $timeOfDayStart : 'null';
        $timeEndKey = $timeOfDayEnd !== null ? (string) $timeOfDayEnd : 'null';
        $severityKey = $severity ?? 'null';

        $rawKey = implode('|', [
            implode(',', $normalizedBbox),
            (string) $resolution,
            $fromKey,
            $toKey,
            $categoryKey,
            $timeStartKey,
            $timeEndKey,
            $severityKey,
        ]);

        $version = $this->getVersion();

        return sprintf('%s%d:%s', self::CACHE_PREFIX, $version, md5($rawKey));
    }

    /**
     * Build the cache key tags for the given filter parameters.
     *
     * @return list<string>
     */
    public function buildTags(int $resolution, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $tags = [self::TAG_ALL, $this->tagForResolution($resolution)];

        $monthTags = $this->resolveMonthTags($from, $to);

        if ($monthTags === []) {
            $monthTags = [self::TAG_MONTH_ALL];
        }

        return array_values(array_unique(array_merge($tags, $monthTags)));
    }

    /**
     * Remember a value with tags if supported, otherwise use regular caching.
     *
     * @template TValue
     *
     * @param list<string> $tags
     * @param Closure():TValue $callback
     *
     * @return TValue
     */
    public function rememberWithTags(string $cacheKey, array $tags, DateTimeInterface $ttl, Closure $callback)
    {
        if ($this->supportsTagging() && $tags !== []) {
            return $this->cache->tags($tags)->remember($cacheKey, $ttl, $callback);
        }

        return $this->cache->remember($cacheKey, $ttl, $callback);
    }

    /**
     * Retrieve the current cache version.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getVersion(): int
    {
        $this->initialiseVersion();

        return (int) $this->cache->get(self::CACHE_VERSION_KEY, 1);
    }

    /**
     * Increment the cache version to invalidate all caches.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function bumpVersion(): int
    {
        $this->initialiseVersion();

        $version = $this->cache->increment(self::CACHE_VERSION_KEY);

        if (! is_int($version)) {
            $version = (int) $this->cache->get(self::CACHE_VERSION_KEY, 1) + 1;
            $this->cache->forever(self::CACHE_VERSION_KEY, $version);
        }

        return $version;
    }

    /**
     * Invalidate cached aggregates for the supplied records using tag-based flushing.
     *
     * @param array<int, array<string, mixed>> $records
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function invalidateForRecords(array $records): void
    {
        $this->bumpVersion();

        if (! $this->supportsTagging()) {
            return;
        }

        $monthTags = $this->resolveMonthTagsFromRecords($records);

        $tagsToFlush = $monthTags === []
            ? [self::TAG_MONTH_ALL]
            : $monthTags;

        foreach (self::SUPPORTED_RESOLUTIONS as $resolution) {
            $resolutionTag = $this->tagForResolution($resolution);

            foreach ($tagsToFlush as $monthTag) {
                $this->cache->tags([$resolutionTag, $monthTag])->flush();
            }
        }
    }

    /**
     * Determine whether the underlying cache store supports tagging.
     */
    public function supportsTagging(): bool
    {
        $store = $this->cache->getStore();

        return $store instanceof TaggableStore;
    }

    /**
     * Ensure the cache version key exists before it is read or mutated.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    private function initialiseVersion(): void
    {
        if (! $this->cache->has(self::CACHE_VERSION_KEY)) {
            $this->cache->forever(self::CACHE_VERSION_KEY, 1);
        }
    }

    /**
     * Resolve month tags from record data.
     *
     * @param array<int, array<string, mixed>> $records
     *
     * @return list<string>
     */
    private function resolveMonthTagsFromRecords(array $records): array
    {
        $months = [];

        foreach ($records as $record) {
            $occurredAt = $record['occurred_at'] ?? null;

            if (! is_string($occurredAt)) {
                continue;
            }

            try {
                $month = CarbonImmutable::parse($occurredAt)->startOfMonth()->format('Y-m');
            } catch (Throwable) {
                continue;
            }

            $months[$month] = true;
        }

        if ($months === []) {
            return [];
        }

        return array_map(fn (string $month): string => $this->tagForMonth($month), array_keys($months));
    }

    /**
     * Resolve month tags from a date range.
     *
     * @return list<string>
     */
    private function resolveMonthTags(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $start = $this->normaliseMonth($from);
        $end = $this->normaliseMonth($to);

        if ($start === null && $end === null) {
            return [];
        }

        if ($start === null) {
            $start = $end;
        }

        if ($end === null) {
            $end = $start;
        }

        if ($start === null || $end === null) {
            return [];
        }

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $tags = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $tags[] = $this->tagForMonth($cursor->format('Y-m'));
            $cursor = $cursor->addMonth();
        }

        return $tags;
    }

    /**
     * Generate a tag for a specific resolution.
     */
    private function tagForResolution(int $resolution): string
    {
        return self::TAG_PREFIX.'resolution:'.$resolution;
    }

    /**
     * Generate a tag for a specific month.
     */
    private function tagForMonth(string $month): string
    {
        return self::TAG_PREFIX.'month:'.$month;
    }

    /**
     * Normalise a date to the start of the month.
     */
    private function normaliseMonth(?CarbonInterface $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonImmutable
            ? $value->startOfMonth()
            : CarbonImmutable::instance($value)->startOfMonth();
    }
}
