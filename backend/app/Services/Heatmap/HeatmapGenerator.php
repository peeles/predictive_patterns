<?php

declare(strict_types=1);

namespace App\Services\Heatmap;

use Carbon\CarbonImmutable;

/**
 * Service for generating heatmap visualisations from scored prediction entries.
 *
 * Aggregates geospatial entries by location and calculates average intensity scores,
 * identifying hotspots for high-risk areas.
 */
class HeatmapGenerator
{
    /**
     * Aggregate scored entries into a heatmap structure.
     *
     * Groups entries by rounded latitude/longitude coordinates and calculates
     * average intensity scores. Returns both all points and top hotspots.
     *
     * @param list<array{timestamp: CarbonImmutable, latitude: float, longitude: float, category: string, score: float}> $entries
     *
     * @return array{
     *     points: list<array{id: string, lat: float, lng: float, intensity: float, count: int}>,
     *     hotspots: list<array{id: string, lat: float, lng: float, intensity: float, count: int}>
     * }
     */
    public function aggregate(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $latKey = round($entry['latitude'], 3);
            $lngKey = round($entry['longitude'], 3);
            $key = sprintf('%s:%s', $latKey, $lngKey);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'lat' => $latKey,
                    'lng' => $lngKey,
                    'sum' => 0.0,
                    'count' => 0,
                ];
            }

            $groups[$key]['sum'] += $entry['score'];
            $groups[$key]['count']++;
        }

        $points = [];

        foreach ($groups as $key => $group) {
            $average = $group['count'] > 0 ? $group['sum'] / $group['count'] : 0.0;

            $points[] = [
                'id' => $key,
                'lat' => $group['lat'],
                'lng' => $group['lng'],
                'intensity' => round($average, 4),
                'count' => $group['count'],
            ];
        }

        usort($points, static fn ($a, $b) => $b['intensity'] <=> $a['intensity']);

        $hotspots = array_slice($points, 0, 5);

        return [
            'points' => $points,
            'hotspots' => $hotspots,
        ];
    }
}
