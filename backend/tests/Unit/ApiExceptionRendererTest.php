<?php

namespace Tests\Unit;

use App\Exceptions\ApiExceptionRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ApiExceptionRendererTest extends TestCase
{
    public function test_renders_validation_exception_with_errors(): void
    {
        $request = Request::create('/api/test', 'POST');
        $request->headers->set('X-Request-Id', 'validation-request-id');

        $validator = Validator::make(['count' => 'foo'], ['count' => ['integer']]);
        $exception = new ValidationException($validator);

        $response = ApiExceptionRenderer::render($exception, $request);

        $payload = $response->getData(true);

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('validation_error', data_get($payload, 'error.code'));
        $this->assertSame('validation-request-id', data_get($payload, 'error.request_id'));
        $this->assertSame('validation-request-id', $response->headers->get('X-Request-Id'));
        $this->assertArrayHasKey('errors', data_get($payload, 'error.details'));
    }

    public function test_renders_authentication_exception(): void
    {
        $request = Request::create('/api/test', 'GET');

        $exception = new AuthenticationException();

        $response = ApiExceptionRenderer::render($exception, $request);
        $payload = $response->getData(true);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertSame('unauthenticated', data_get($payload, 'error.code'));
        $this->assertSame('Unauthenticated.', data_get($payload, 'error.message'));
    }

    public function test_renders_authorization_exception(): void
    {
        $request = Request::create('/api/test', 'GET');

        $exception = new AuthorizationException();

        $response = ApiExceptionRenderer::render($exception, $request);
        $payload = $response->getData(true);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame('forbidden', data_get($payload, 'error.code'));
    }

    public function test_renders_model_not_found_exception(): void
    {
        $request = Request::create('/api/test', 'GET');

        $exception = new ModelNotFoundException();

        $response = ApiExceptionRenderer::render($exception, $request);
        $payload = $response->getData(true);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame('not_found', data_get($payload, 'error.code'));
        $this->assertSame('Resource not found.', data_get($payload, 'error.message'));
    }

    public function test_renders_query_exception(): void
    {
        $request = Request::create('/api/test', 'GET');

        $exception = new QueryException('mysql','select 1', [], new RuntimeException('SQLSTATE[HY000]'));

        $response = ApiExceptionRenderer::render($exception, $request);
        $payload = $response->getData(true);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertSame('database_error', data_get($payload, 'error.code'));
        $this->assertSame('A database error occurred.', data_get($payload, 'error.message'));
    }

    public function test_renders_http_exception_with_status_specific_code(): void
    {
        $request = Request::create('/api/test', 'GET');

        $exception = new NotFoundHttpException();

        $response = ApiExceptionRenderer::render($exception, $request);
        $payload = $response->getData(true);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame('not_found', data_get($payload, 'error.code'));
        $this->assertSame('Not Found', data_get($payload, 'error.message'));
    }

    public function test_generates_request_id_when_missing(): void
    {
        $request = Request::create('/api/test', 'GET');

        $response = ApiExceptionRenderer::render(new RuntimeException('boom'), $request);
        $payload = $response->getData(true);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertSame('server_error', data_get($payload, 'error.code'));
        $this->assertNotEmpty(data_get($payload, 'error.request_id'));
        $this->assertSame(data_get($payload, 'error.request_id'), $response->headers->get('X-Request-Id'));
    }
}
