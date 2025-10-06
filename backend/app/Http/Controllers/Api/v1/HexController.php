<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Requests\HexAggregationRequest;
use App\Services\H3AggregationService;
use App\Services\H3GeometryService;
use Illuminate\Http\JsonResponse;

class HexController extends BaseController
{
    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:map']);
    }

    /**
     * Aggregate points into H3 hexagons within a bounding box.
     *
     * @param HexAggregationRequest $request
     * @param H3AggregationService $service
     *
     * @return JsonResponse
     */
    public function index(HexAggregationRequest $request, H3AggregationService $service): JsonResponse
    {
        $validated = $request->validated();

        $aggregates = $service->aggregateByBbox(
            $validated['bbox'],
            $validated['resolution'],
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['dataset_type'] ?? null,
            $validated['time_of_day_start'] ?? null,
            $validated['time_of_day_end'] ?? null,
            $validated['severity'] ?? null,
            $validated['confidence_level'] ?? null,
        );

        $cells = [];

        foreach ($aggregates as $h3 => $data) {
            $cells[] = [
                'h3' => $h3,
                'count' => $data['count'],
                'categories' => $data['categories'],
                'statistics' => $data['statistics'],
            ];
        }

        return $this->successResponse([
            'resolution' => $validated['resolution'],
            'cells' => $cells,
        ]);
    }

    /**
     * @param HexAggregationRequest $request
     * @param H3AggregationService $service
     * @param H3GeometryService $geometryService
     *
     * @return JsonResponse
     */
    public function geoJson(
        HexAggregationRequest $request,
        H3AggregationService $service,
        H3GeometryService $geometryService,
    ): JsonResponse {
        $validated = $request->validated();

        $aggregates = $service->aggregateByBbox(
            $validated['bbox'],
            $validated['resolution'],
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['dataset_type'] ?? null,
            $validated['time_of_day_start'] ?? null,
            $validated['time_of_day_end'] ?? null,
            $validated['severity'] ?? null,
            $validated['confidence_level'] ?? null,
        );

        $features = [];

        foreach ($aggregates as $h3 => $data) {
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'h3' => $h3,
                    'count' => $data['count'],
                    'categories' => $data['categories'],
                    'statistics' => $data['statistics'],
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [
                        $geometryService->polygonCoordinates($h3),
                    ],
                ],
            ];
        }

        return $this->successResponse([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }
}
