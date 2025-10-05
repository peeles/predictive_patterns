<?php

namespace App\Services;

use App\DataTransferObjects\HexAggregate;
use App\Models\Crime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Aggregates crime counts across H3 cells with optional temporal and category filtering.
 */
class H3AggregationService
{
    private const SUPPORTED_RESOLUTIONS = [6, 7, 8];
    private const CACHE_PREFIX = 'h3_aggregations:';
    public const CACHE_VERSION_KEY = self::CACHE_PREFIX . 'version';
    private const CACHE_TTL_MINUTES = 10;
    private const TAG_PREFIX = self::CACHE_PREFIX . 'tag:';
    private const TAG_ALL = self::TAG_PREFIX . 'all';
    private const TAG_MONTH_ALL = self::TAG_PREFIX . 'month:all';

    public function __construct(private readonly CacheRepository $cache)
    {
    }

    /**
     * Aggregate crimes across H3 cells intersecting the provided bounding box.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     * @return HexAggregate[]
     *
     * @throws InvalidArgumentException When an unsupported resolution is supplied
     */
    public function aggregateByBoundingBox(
        array            $boundingBox,
        int              $resolution,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        ?string          $category = null,
    ): array {
        if (!in_array($resolution, self::SUPPORTED_RESOLUTIONS, true)) {
            throw new InvalidArgumentException('Unsupported resolution supplied.');
        }

        $cacheKey = $this->buildCacheKey($boundingBox, $resolution, $from, $to, $category);

        $tags = $this->resolveCacheTags($resolution, $from, $to);

        return $this->rememberWithTags(
            $cacheKey,
            $tags,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->runAggregateQuery($boundingBox, $resolution, $from, $to, $category)
        );
    }

    /**
     * Convenience wrapper accepting a string bounding box and returning keyed results for controllers.
     *
     * @param CarbonInterface|string|null $from
     * @param CarbonInterface|string|null $to
     *
     * @return array<string, array{count: int, categories: array<string, int>}> indexed by H3 cell id
     */
    public function aggregateByBbox(
        string                      $bboxString,
        int                          $resolution,
        CarbonInterface|string|null  $from = null,
        CarbonInterface|string|null  $to = null,
        ?string                      $category = null,
    ): array {
        $boundingBox = $this->parseBoundingBox($bboxString);
        $fromCarbon = $from instanceof CarbonInterface ? $from : $this->parseDate($from);
        $toCarbon = $to instanceof CarbonInterface ? $to : $this->parseDate($to);
        $category = $this->normaliseCategory($category);

        $aggregates = $this->aggregateByBoundingBox($boundingBox, $resolution, $fromCarbon, $toCarbon, $category);

        $result = [];
        foreach ($aggregates as $aggregate) {
            $result[$aggregate->h3Index] = [
                'count' => $aggregate->count,
                'categories' => $aggregate->categories,
            ];
        }

        return $result;
    }

    /**
     * Apply from/to constraints onto the aggregate query if they are supplied.
     */
    private function applyTemporalFilters(
        Builder          $query,
        ?CarbonInterface $from,
        ?CarbonInterface $to
    ): void {
        if ($from) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to) {
            $query->where('occurred_at', '<=', $to);
        }
    }

    /**
     * Execute the aggregation query without caching.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     *
     * @return HexAggregate[]
     */
    private function runAggregateQuery(
        array            $boundingBox,
        int              $resolution,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        ?string          $category
    ): array {
        [$west, $south, $east, $north] = $boundingBox;

        $query = Crime::query()
            ->whereBetween('lng', [$west, $east])
            ->whereBetween('lat', [$south, $north]);

        $this->applyTemporalFilters($query, $from, $to);

        if ($category) {
            $query->where('category', $category);
        }

        $column = sprintf('h3_res%d', $resolution);

        return $query
            ->selectRaw("$column as h3, category, count(*) as c")
            ->groupBy($column, 'category')
            ->get()
            ->groupBy('h3')
            ->map(
                static function (Collection $rows) {
                    $first = $rows->first();
                    $h3 = (string)($first->h3 ?? '');
                    $count = (int) $rows->sum('c');
                    $categories = $rows
                        ->pluck('c', 'category')
                        ->map(static fn ($value) => (int) $value)
                        ->toArray();

                    return new HexAggregate($h3, $count, $categories);
                }
            )
            ->values()
            ->all();
    }

    /**
     * Build a cache key that incorporates the filter parameters and version.
     */
    private function buildCacheKey(
        array            $boundingBox,
        int              $resolution,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        ?string          $category
    ): string {
        $normalizedBbox = array_map(
            static fn(mixed $value): string => number_format((float) $value, 6, '.', ''),
            $boundingBox
        );

        $fromKey = $from?->toIso8601String() ?? 'null';
        $toKey = $to?->toIso8601String() ?? 'null';
        $categoryKey = $category ?? 'null';

        $rawKey = implode('|', [
            implode(',', $normalizedBbox),
            (string) $resolution,
            $fromKey,
            $toKey,
            $categoryKey,
        ]);

        $version = $this->getCacheVersion();

        return sprintf('%s%d:%s', self::CACHE_PREFIX, $version, md5($rawKey));
    }

    /**
     * Retrieve the current cache version, initialising it if necessary.
     */
    private function getCacheVersion(): int
    {
        $this->initialiseCacheVersion();

        return (int) $this->cache->get(self::CACHE_VERSION_KEY, 1);
    }

    /**
     * Increment the cache version so downstream caches pick up fresh aggregates.
     */
    public function bumpCacheVersion(): int
    {
        $this->initialiseCacheVersion();

        $version = $this->cache->increment(self::CACHE_VERSION_KEY);

        if (!is_int($version)) {
            $version = (int) $this->cache->get(self::CACHE_VERSION_KEY, 1) + 1;
            $this->cache->forever(self::CACHE_VERSION_KEY, $version);
        }

        return $version;
    }

    /**
     * Expose the current cache version for external callers that need to build compatible keys.
     */
    public function cacheVersion(): int
    {
        return $this->getCacheVersion();
    }

    /**
     * Invalidate cached aggregates for the supplied records, using tag-based flushing when available.
     *
     * @param array<int, array<string, mixed>> $records
     */
    public function invalidateAggregatesForRecords(array $records): void
    {
        $this->bumpCacheVersion();

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
        return $this->supportsTaggingInternal();
    }

    /**
     * Provide the tag set used for cached aggregates that match the given filters.
     *
     * @return list<string>
     */
    public function cacheTags(int $resolution, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return $this->resolveCacheTags($resolution, $from, $to);
    }

    /**
     * Ensure the cache version key exists before it is read or mutated.
     */
    private function initialiseCacheVersion(): void
    {
        if (! $this->cache->has(self::CACHE_VERSION_KEY)) {
            $this->cache->forever(self::CACHE_VERSION_KEY, 1);
        }
    }

    /**
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
            } catch (\Throwable) {
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
     * Build the cache key tags for the given filter parameters.
     *
     * @return list<string>
     */
    private function resolveCacheTags(int $resolution, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $tags = [self::TAG_ALL, $this->tagForResolution($resolution)];

        $monthTags = $this->resolveMonthTags($from, $to);

        if ($monthTags === []) {
            $monthTags = [self::TAG_MONTH_ALL];
        }

        return array_values(array_unique(array_merge($tags, $monthTags)));
    }

    /**
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

    private function tagForResolution(int $resolution): string
    {
        return self::TAG_PREFIX . 'resolution:' . $resolution;
    }

    private function tagForMonth(string $month): string
    {
        return self::TAG_PREFIX . 'month:' . $month;
    }

    private function normaliseMonth(?CarbonInterface $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonImmutable
            ? $value->startOfMonth()
            : CarbonImmutable::instance($value)->startOfMonth();
    }

    /**
     * @template TValue
     *
     * @param list<string> $tags
     * @param Closure():TValue $callback
     * @return TValue
     */
    private function rememberWithTags(string $cacheKey, array $tags, DateTimeInterface $ttl, Closure $callback)
    {
        if ($this->supportsTaggingInternal() && $tags !== []) {
            return $this->cache->tags($tags)->remember($cacheKey, $ttl, $callback);
        }

        return $this->cache->remember($cacheKey, $ttl, $callback);
    }

    private function supportsTaggingInternal(): bool
    {
        $store = $this->cache->getStore();

        return $store instanceof TaggableStore;
    }

    /**
     * Convert a bbox string to an array of floats.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function parseBoundingBox(string $bboxString): array
    {
        $parts = array_map('trim', explode(',', $bboxString));

        if (count($parts) !== 4) {
            throw new InvalidArgumentException('Bounding box must contain four comma separated numbers.');
        }

        return [
            (float) $parts[0],
            (float) $parts[1],
            (float) $parts[2],
            (float) $parts[3],
        ];
    }

    /**
     * Parse the supplied value into a Carbon instance when possible.
     */
    private function parseDate(CarbonInterface|string|null $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        try {
            return new CarbonImmutable($value);
        } catch (\Exception) {
            throw new InvalidArgumentException('Unable to parse date value.');
        }
    }

    private function normaliseCategory(?string $category): ?string
    {
        $category = $category !== null ? trim($category) : null;

        return $category !== '' ? $category : null;
    }
}
