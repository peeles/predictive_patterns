<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ApiExceptionHandlingTest extends TestCase
{
    public function test_model_not_found_exception_is_returned_as_json(): void
    {
        Route::middleware('api')->get('/api/test/model-not-found', function (): void {
            throw new ModelNotFoundException();
        });

        $response = $this->getJson('/api/test/model-not-found');

        $response->assertStatus(Response::HTTP_NOT_FOUND)
            ->assertJsonStructure(['code', 'message', 'errors', 'request_id'])
            ->assertJsonPath('code', 'not_found');

        $this->assertNotEmpty($response->json('request_id'));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
    }

    public function test_validation_exception_includes_details_and_request_id(): void
    {
        Route::middleware('api')->post('/api/test/validation', function (): void {
            throw ValidationException::withMessages([
                'name' => ['The name field is required.'],
            ]);
        });

        $response = $this->withHeader('X-Request-Id', 'feature-test-request-id')
            ->postJson('/api/test/validation');

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'validation_error')
            ->assertJsonPath('errors.name.0', 'The name field is required.');

        $this->assertSame('feature-test-request-id', $response->headers->get('X-Request-Id'));
    }

    public function test_unhandled_exception_returns_json_payload(): void
    {
        Route::middleware('api')->get('/api/test/unhandled', function (): void {
            throw new RuntimeException('Boom');
        });

        $response = $this->getJson('/api/test/unhandled');

        $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR)
            ->assertJsonPath('code', 'server_error')
            ->assertJsonPath('message', 'Internal server error.')
            ->assertJsonStructure(['code', 'message', 'errors', 'request_id']);
    }
}
