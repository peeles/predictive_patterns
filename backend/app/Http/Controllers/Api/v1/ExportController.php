<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportRequest;
use App\Services\H3AggregationService;
use App\Services\H3GeometryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Response as ResponseFacade;
use JsonException;

class ExportController extends Controller
{
    /**
     * Export aggregated dataset records within a bounding box to CSV or GeoJSON format.
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
    ): Response|JsonResponse {
        $validated = $request->validated();

        $bbox = (string) ($validated['bbox'] ?? '-180,-90,180,90');
        $resolution = (int) ($validated['resolution'] ?? 7);
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;
        $datasetType = $validated['dataset_type'] ?? null;
        $severity = $validated['severity'] ?? null;
        $timeOfDayStart = $validated['time_of_day_start'] ?? null;
        $timeOfDayEnd = $validated['time_of_day_end'] ?? null;
        $confidenceLevel = $validated['confidence_level'] ?? null;
        $format = strtolower((string) ($validated['format'] ?? $validated['type'] ?? 'csv'));

        $aggregated = $aggregationService->aggregateByBbox(
            $bbox,
            $resolution,
            $from,
            $to,
            $datasetType,
            $timeOfDayStart,
            $timeOfDayEnd,
            $severity,
            $confidenceLevel,
        );

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
        fputcsv(
            $handle,
            [
                'h3',
                'resolution',
                'count',
                'categories',
                'mean_risk_score',
                'confidence_level',
                'confidence_interval_lower',
                'confidence_interval_upper',
                'risk_sample_size',
            ]
        );

        foreach ($data as $h3 => $payload) {
            $statistics = $payload['statistics'] ?? [];
            $confidence = $statistics['confidence_interval'] ?? null;

            fputcsv($handle, [
                $h3,
                $resolution,
                $payload['count'],
                json_encode($payload['categories'], JSON_THROW_ON_ERROR),
                $statistics['mean_risk_score'] ?? null,
                $statistics['confidence_level'] ?? null,
                $confidence['lower'] ?? null,
                $confidence['upper'] ?? null,
                $statistics['sample_size'] ?? null,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return ResponseFacade::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="dataset-record-export.csv"',
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
                    'statistics' => $payload['statistics'],
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [
                        $geometryService->polygonCoordinates($h3),
                    ],
                ],
            ];
        }

        return ResponseFacade::json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ], 200, [
            'Content-Type' => 'application/geo+json',
            'Content-Disposition' => 'attachment; filename="dataset-record-export.geojson"',
        ], JSON_UNESCAPED_SLASHES);
    }
}
