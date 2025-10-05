<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportRequest;
use App\Services\H3AggregationService;
use App\Services\H3GeometryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;
use JsonException;

class ExportController extends Controller
{
    /**
     * Export aggregated crime data within a bounding box to CSV or GeoJSON format.
     *
     * @param ExportRequest $request
     * @param H3AggregationService $aggregationService
     * @param H3GeometryService $geometryService
     *
     * @return Response|JsonResponse
     *
     * @throws JsonException
     */
    public function __invoke(
        ExportRequest        $request,
        H3AggregationService $aggregationService,
        H3GeometryService    $geometryService
    ): Response|JsonResponse
    {
        $bbox = $request->string('bbox') ?? '-180,-90,180,90';
        $resolution = (int)($request->integer('resolution') ?? 7);
        $from = $request->input('from');
        $to = $request->input('to');
        $crimeType = $request->input('crime_type');
        $format = strtolower((string)($request->string('format') ?? $request->string('type') ?? 'csv'));

        $aggregated = $aggregationService->aggregateByBbox($bbox, $resolution, $from, $to, $crimeType);

        if ($format === 'geojson' || str_contains($request->header('accept', ''), 'geo+json')) {
            return $this->exportGeoJson($aggregated, $geometryService, $resolution);
        }

        return $this->exportCsv($aggregated, $resolution);
    }

    /**
     * Export data to CSV format.
     *
     * @param array<string, array{count: int, categories: array<string, int>}> $data
     * @param int $resolution
     *
     * @return Response
     * @throws JsonException
     */
    private function exportCsv(array $data, int $resolution): Response
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['h3', 'resolution', 'count', 'categories']);

        foreach ($data as $h3 => $payload) {
            fputcsv($handle, [
                $h3,
                $resolution,
                $payload['count'],
                json_encode($payload['categories'], JSON_THROW_ON_ERROR),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="crime-export.csv"',
        ]);
    }

    /**
     * Export data to GeoJSON format.
     *
     * @param array<string, array{count: int, categories: array<string, int>}> $data
     * @param H3GeometryService $geometryService
     * @param int $resolution
     *
     * @return JsonResponse
     */
    private function exportGeoJson(array $data, H3GeometryService $geometryService, int $resolution): JsonResponse
    {
        $features = [];
        foreach ($data as $h3 => $payload) {
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'h3' => $h3,
                    'resolution' => $resolution,
                    'count' => $payload['count'],
                    'categories' => $payload['categories'],
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [
                        $geometryService->polygonCoordinates($h3),
                    ],
                ],
            ];
        }

        return Response::json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ], 200, [
            'Content-Type' => 'application/geo+json',
            'Content-Disposition' => 'attachment; filename="crime-export.geojson"',
        ], JSON_UNESCAPED_SLASHES);
    }
}
