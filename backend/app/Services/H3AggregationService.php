<?php

namespace App\Services;

use App\DataTransferObjects\HexAggregate;
use App\Models\Crime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Aggregates crime counts across H3 cells with optional temporal and category filtering.
 */
class H3AggregationService
{
    private const SUPPORTED_RESOLUTIONS = [6, 7, 8];
    private const CACHE_PREFIX = 'h3_aggregations:';
    public const CACHE_VERSION_KEY = self::CACHE_PREFIX.'version';
    private const CACHE_TTL_MINUTES = 10;

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

        return Cache::remember(
            $cacheKey,
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

        return (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }

    /**
     * Increment the cache version so downstream caches pick up fresh aggregates.
     */
    public function bumpCacheVersion(): int
    {
        $this->initialiseCacheVersion();

        $version = Cache::increment(self::CACHE_VERSION_KEY);

        if (!is_int($version)) {
            $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1) + 1;
            Cache::forever(self::CACHE_VERSION_KEY, $version);
        }

        return $version;
    }

    /**
     * Ensure the cache version key exists before it is read or mutated.
     */
    private function initialiseCacheVersion(): void
    {
        if (! Cache::has(self::CACHE_VERSION_KEY)) {
            Cache::forever(self::CACHE_VERSION_KEY, 1);
        }
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
