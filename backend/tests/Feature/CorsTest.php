<?php

namespace Tests\Feature;

use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CorsTest extends TestCase
{
    #[Test]
    public function it_adds_cors_headers_to_preflight_requests(): void
    {
        $response = $this
            ->withHeader('Origin', 'http://localhost:3000')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->options('/api/v1/auth/login');

        $response->assertStatus(Response::HTTP_NO_CONTENT);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response->assertHeader('Access-Control-Allow-Methods');
        $response->assertHeader('Access-Control-Allow-Headers');
    }
}
