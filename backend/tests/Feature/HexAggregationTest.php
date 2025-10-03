<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Crime;
use App\Services\H3AggregationService;
use App\Services\H3GeometryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class HexAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_aggregated_counts_for_bbox(): void
    {
        Cache::flush();

        Crime::factory()->create([
            'category' => 'burglary',
            'occurred_at' => Carbon::parse('2024-03-01 10:00:00'),
            'lat' => 53.4,
            'lng' => -2.9,
            'h3_res6' => '86052c07fffffff',
        ]);

        Crime::factory()->create([
            'category' => 'burglary',
            'occurred_at' => Carbon::parse('2024-03-01 11:00:00'),
            'lat' => 53.41,
            'lng' => -2.91,
            'h3_res6' => '86052c07fffffff',
        ]);

        Crime::factory()->create([
            'category' => 'assault',
            'occurred_at' => Carbon::parse('2024-02-10 09:00:00'),
            'lat' => 51.5,
            'lng' => -0.12,
            'h3_res6' => '8702a5fffffffff',
        ]);

        $tokens = $this->issueTokensForRole(Role::Viewer);

        $response = $this->withToken($tokens['accessToken'])
            ->getJson('/api/v1/hexes?bbox=-3,53,0,55&resolution=6&from=2024-03-01&to=2024-03-31');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'resolution' => 6,
                    'cells' => [
                        [
                            'h3' => '86052c07fffffff',
                            'count' => 2,
                            'categories' => ['burglary' => 2],
                        ],
                    ],
                ],
            ]);

        $cells = $response->json('data.cells');
        $this->assertFalse(collect($cells)->contains(fn (array $cell): bool => $cell['h3'] === '8702a5fffffffff'));
    }

    public function test_cached_results_refresh_after_cache_version_bump(): void
    {
        Cache::flush();

        Crime::factory()->create([
            'category' => 'burglary',
            'occurred_at' => Carbon::parse('2024-03-01 10:00:00'),
            'lat' => 53.4,
            'lng' => -2.9,
            'h3_res6' => '86052c07fffffff',
        ]);

        $tokens = $this->issueTokensForRole(Role::Viewer);
        $url = '/api/v1/hexes?bbox=-3,53,0,55&resolution=6';

        $this->withToken($tokens['accessToken'])
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cells.0.count', 1);

        Crime::factory()->create([
            'category' => 'burglary',
            'occurred_at' => Carbon::parse('2024-03-01 12:00:00'),
            'lat' => 53.401,
            'lng' => -2.901,
            'h3_res6' => '86052c07fffffff',
        ]);

        $this->withToken($tokens['accessToken'])
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cells.0.count', 1);

        app(H3AggregationService::class)->bumpCacheVersion();

        $this->withToken($tokens['accessToken'])
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cells.0.count', 2);
    }

    public function test_geojson_response_includes_polygon_coordinates(): void
    {
        Crime::factory()->create([
            'category' => 'theft',
            'occurred_at' => Carbon::parse('2024-01-15 10:00:00'),
            'lat' => 53.4,
            'lng' => -2.9,
            'h3_res6' => '86052c07fffffff',
        ]);

        $mock = Mockery::mock(H3GeometryService::class);
        $mock->shouldReceive('polygonCoordinates')
            ->andReturn([[0.0, 0.0], [0.0, 1.0], [1.0, 1.0], [1.0, 0.0], [0.0, 0.0]])
            ->atLeast()->once();

        $this->app->instance(H3GeometryService::class, $mock);

        $tokens = $this->issueTokensForRole(Role::Viewer);

        $response = $this->withToken($tokens['accessToken'])
            ->getJson('/api/v1/hexes/geojson?bbox=-3,53,0,55&resolution=6');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'type',
                    'features' => [
                        [
                            'type',
                            'properties' => ['h3', 'count', 'categories'],
                            'geometry' => ['type', 'coordinates'],
                        ],
                    ],
                ],
            ]);
    }

    public function test_validation_errors_are_returned_for_invalid_input(): void
    {
        $tokens = $this->issueTokensForRole(Role::Viewer);

        $this->withToken($tokens['accessToken'])
            ->getJson('/api/v1/hexes?bbox=invalid')
            ->assertStatus(422);

        $this->withToken($tokens['accessToken'])
            ->getJson('/api/v1/hexes?bbox=-3,53,0,55&resolution=2')
            ->assertStatus(422);

        $this->withToken($tokens['accessToken'])
            ->getJson('/api/v1/hexes?bbox=-3,53,0,55&from=2024-05-01&to=2024-04-01')
            ->assertStatus(422);
    }

    public function test_requests_without_token_are_rejected(): void
    {
        $this->getJson('/api/v1/hexes?bbox=-3,53,0,55')
            ->assertUnauthorized();
    }
}
