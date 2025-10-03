<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_ok_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['status' => 'ok'],
            ]);
    }
}
