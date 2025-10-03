<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiExceptionRenderer
{
    public static function render(Throwable $exception, Request $request): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            $requestId = self::resolveRequestId($request);
            $response = response()->json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => $exception->getMessage() ?: 'The given data was invalid.',
                    'details' => [
                        'errors' => $exception->errors(),
                    ],
                    'request_id' => $requestId,
                ],
                'errors' => $exception->errors(),
            ], $exception->status ?? Response::HTTP_UNPROCESSABLE_ENTITY);

            return $response->withHeaders(['X-Request-Id' => $requestId]);
        }

        [$code, $message, $status, $details] = self::mapException($exception);

        $requestId = self::resolveRequestId($request);

        $response = response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => $requestId,
            ],
        ], $status);

        $headers = ['X-Request-Id' => $requestId];

        if ($exception instanceof HttpExceptionInterface) {
            $headers = array_merge($exception->getHeaders(), $headers);
        }

        return $response->withHeaders($headers);
    }

    private static function mapException(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof AuthenticationException => [
                'unauthenticated',
                $exception->getMessage() ?: 'Unauthenticated.',
                Response::HTTP_UNAUTHORIZED,
                null,
            ],
            $exception instanceof AuthorizationException => [
                'forbidden',
                $exception->getMessage() ?: 'This action is unauthorized.',
                Response::HTTP_FORBIDDEN,
                null,
            ],
            $exception instanceof ModelNotFoundException => [
                'not_found',
                'Resource not found.',
                Response::HTTP_NOT_FOUND,
                null,
            ],
            $exception instanceof QueryException => [
                'database_error',
                'A database error occurred.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                null,
            ],
            $exception instanceof HttpExceptionInterface => [
                self::codeForHttpStatus($exception->getStatusCode()),
                $exception->getMessage() ?: (Response::$statusTexts[$exception->getStatusCode()] ?? 'HTTP Error'),
                $exception->getStatusCode(),
                null,
            ],
            default => [
                'server_error',
                'Internal server error.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                null,
            ],
        };
    }

    private static function resolveRequestId(Request $request): string
    {
        $existing = $request->attributes->get('request_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $header = $request->headers->get('X-Request-Id');
        if (is_string($header) && $header !== '') {
            $request->attributes->set('request_id', $header);

            return $header;
        }

        $generated = (string) Str::uuid();
        $request->attributes->set('request_id', $generated);

        return $generated;
    }

    private static function codeForHttpStatus(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'bad_request',
            Response::HTTP_UNAUTHORIZED => 'unauthenticated',
            Response::HTTP_FORBIDDEN => 'forbidden',
            Response::HTTP_NOT_FOUND => 'not_found',
            Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
            Response::HTTP_CONFLICT => 'conflict',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'validation_error',
            Response::HTTP_TOO_MANY_REQUESTS => 'too_many_requests',
            default => $status >= Response::HTTP_INTERNAL_SERVER_ERROR
                ? 'server_error'
                : 'http_error',
        };
    }
}
