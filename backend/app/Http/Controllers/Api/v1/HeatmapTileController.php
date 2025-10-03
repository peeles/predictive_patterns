<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\HeatmapTileRequest;
use App\Services\H3AggregationService;
use App\Services\H3GeometryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class HeatmapTileController extends BaseController
{
    private const CACHE_PREFIX = 'heatmap_tiles:';
    private const CACHE_TTL_MINUTES = 5;

    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:map']);
    }

    public function __invoke(
        HeatmapTileRequest $request,
        H3AggregationService $aggregationService,
        H3GeometryService $geometryService,
    ): JsonResponse {
        $zoom = $request->zoom();
        $tileX = $request->tileX();
        $tileY = $request->tileY();
        $from = $request->startTime();
        $to = $request->endTime($from);
        $horizon = $request->horizonHours();

        $resolution = $this->resolutionForZoom($zoom);
        $boundingBox = $this->tileBoundingBox($tileX, $tileY, $zoom);

        $cacheKey = $this->buildCacheKey($zoom, $tileX, $tileY, $resolution, $from, $to, $horizon);

        $payload = Cache::remember(
            $cacheKey,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($aggregationService, $geometryService, $boundingBox, $resolution, $from, $to, $horizon) {
                $aggregates = $aggregationService->aggregateByBoundingBox($boundingBox, $resolution, $from, $to, null);

                $cells = [];
                $maxCount = 0;

                foreach ($aggregates as $aggregate) {
                    $polygon = $geometryService->polygonCoordinates($aggregate->h3Index);
                    $centroid = $this->polygonCentroid($polygon);

                    $cells[] = [
                        'h3' => $aggregate->h3Index,
                        'count' => $aggregate->count,
                        'categories' => $aggregate->categories,
                        'centroid' => [
                            'lat' => round($centroid[1], 6),
                            'lng' => round($centroid[0], 6),
                        ],
                        'polygon' => array_map(
                            static fn (array $vertex): array => [
                                'lng' => round($vertex[0], 6),
                                'lat' => round($vertex[1], 6),
                            ],
                            $polygon
                        ),
                    ];

                    $maxCount = max($maxCount, $aggregate->count);
                }

                return [
                    'meta' => [
                        'bounds' => [
                            'west' => $boundingBox[0],
                            'south' => $boundingBox[1],
                            'east' => $boundingBox[2],
                            'north' => $boundingBox[3],
                        ],
                        'max_count' => $maxCount,
                        'timeframe' => [
                            'from' => $from?->toIso8601String(),
                            'to' => $to?->toIso8601String(),
                        ],
                        'horizon_hours' => $horizon,
                    ],
                    'cells' => $cells,
                ];
            }
        );

        return $this->successResponse([
            'z' => $zoom,
            'x' => $tileX,
            'y' => $tileY,
            'resolution' => $resolution,
            'meta' => $payload['meta'],
            'cells' => $payload['cells'],
        ]);
    }

    /**
     * Determine the H3 resolution to use for the supplied zoom level.
     *
     * @param int $zoom
     *
     * @return int
     */
    private function resolutionForZoom(int $zoom): int
    {
        if ($zoom <= 8) {
            return 6;
        }

        if ($zoom <= 11) {
            return 7;
        }

        return 8;
    }

    /**
     * Convert tile coordinates into a geographic bounding box.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function tileBoundingBox(int $x, int $y, int $z): array
    {
        $scale = 1 << $z;
        $west = $x / $scale * 360 - 180;
        $east = ($x + 1) / $scale * 360 - 180;

        $north = $this->tileLat($y, $scale);
        $south = $this->tileLat($y + 1, $scale);

        return [$west, $south, $east, $north];
    }

    private function tileLat(int $y, int $scale): float
    {
        $n = pi() - (2.0 * pi() * $y) / $scale;

        return rad2deg(atan(sinh($n)));
    }

    /**
     * Approximate the centroid of a polygon represented as [lng, lat] coordinate pairs.
     *
     * @param array<int, array{0: float, 1: float}> $polygon
     *
     * @return array{0: float, 1: float}
     */
    private function polygonCentroid(array $polygon): array
    {
        $points = $polygon;

        if (count($points) > 1) {
            $first = $points[0];
            $last = $points[count($points) - 1];

            if ($first[0] === $last[0] && $first[1] === $last[1]) {
                array_pop($points);
            }
        }

        $count = count($points);

        if ($count === 0) {
            return [0.0, 0.0];
        }

        if ($count === 1) {
            return [$points[0][0], $points[0][1]];
        }

        $area = 0.0;
        $centroidX = 0.0;
        $centroidY = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $current = $points[$i];
            $next = $points[($i + 1) % $count];
            $cross = ($current[0] * $next[1]) - ($next[0] * $current[1]);
            $area += $cross;
            $centroidX += ($current[0] + $next[0]) * $cross;
            $centroidY += ($current[1] + $next[1]) * $cross;
        }

        $area *= 0.5;

        if (abs($area) < 1e-12) {
            $avgLng = 0.0;
            $avgLat = 0.0;
            foreach ($points as $point) {
                $avgLng += $point[0];
                $avgLat += $point[1];
            }

            return [$avgLng / $count, $avgLat / $count];
        }

        $factor = 1 / (6 * $area);

        return [$centroidX * $factor, $centroidY * $factor];
    }

    private function buildCacheKey(
        int $zoom,
        int $x,
        int $y,
        int $resolution,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?int $horizon,
    ): string {
        $fromKey = $from?->toIso8601String() ?? 'null';
        $toKey = $to?->toIso8601String() ?? 'null';
        $horizonKey = $horizon !== null ? (string) $horizon : 'null';

        $rawKey = implode('|', [
            $zoom,
            $x,
            $y,
            $resolution,
            $fromKey,
            $toKey,
            $horizonKey,
        ]);

        $version = Cache::get(H3AggregationService::CACHE_VERSION_KEY, 1);

        return sprintf('%s%d:%s', self::CACHE_PREFIX, $version, md5($rawKey));
    }
}
