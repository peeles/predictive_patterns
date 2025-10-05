<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Services\H3AggregationService;
use App\Services\H3GeometryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ExportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_csv_endpoint_returns_successful_response(): void
    {
        $aggregationMock = Mockery::mock(H3AggregationService::class);
        $aggregationMock->shouldReceive('aggregateByBbox')
            ->once()
            ->andReturn([
                '87283080dffffff' => [
                    'count' => 3,
                    'categories' => ['burglary' => 3],
                ],
            ]);

        $this->app->instance(H3AggregationService::class, $aggregationMock);

        $tokens = $this->issueTokensForRole(Role::Viewer);

        $response = $this->withToken($tokens['accessToken'])
            ->get('/api/v1/export?format=csv');

        $response->assertOk();

        $contentType = $response->headers->get('Content-Type');
        self::assertIsString($contentType);
        self::assertStringContainsString('text/csv', $contentType);
        self::assertStringContainsString('87283080dffffff', $response->getContent());
    }

    public function test_export_geojson_endpoint_returns_successful_response(): void
    {
        $aggregationMock = Mockery::mock(H3AggregationService::class);
        $aggregationMock->shouldReceive('aggregateByBbox')
            ->once()
            ->andReturn([
                '87283080dffffff' => [
                    'count' => 5,
                    'categories' => ['burglary' => 5],
                ],
            ]);

        $this->app->instance(H3AggregationService::class, $aggregationMock);

        $geometryMock = Mockery::mock(H3GeometryService::class);
        $geometryMock->shouldReceive('polygonCoordinates')
            ->once()
            ->with('87283080dffffff')
            ->andReturn([[0.0, 0.0], [0.0, 1.0], [1.0, 1.0], [1.0, 0.0], [0.0, 0.0]]);

        $this->app->instance(H3GeometryService::class, $geometryMock);

        $tokens = $this->issueTokensForRole(Role::Viewer);

        $response = $this->withToken($tokens['accessToken'])
            ->getJson('/api/v1/export?format=geojson');

        $response->assertOk();
        $response->assertJsonPath('type', 'FeatureCollection');
        $response->assertJsonPath('features.0.properties.h3', '87283080dffffff');

        $contentType = $response->headers->get('Content-Type');
        self::assertIsString($contentType);
        self::assertStringContainsString('application/geo+json', $contentType);
    }
}
