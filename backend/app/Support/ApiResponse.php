<?php

namespace App\Support;

use BadMethodCallException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

trait ApiResponse
{
    /**
     * Helper to return a JSON response with data, meta, and links.
     *
     * @param array $data
     * @param int $status
     * @param array $meta
     *
     * @return JsonResponse
     */
    protected function respondWithData(array $data, int $status = Response::HTTP_OK, array $meta = []): JsonResponse
    {
        return $this->respondWithSuccess($data, $status, $meta);
    }

    /**
     * Helper to return a JSON response with a message and optional additional data.
     *
     * @param string $message
     * @param int $status
     * @param array $additional
     *
     * @return JsonResponse
     */
    protected function respondWithMessage(string $message, int $status = Response::HTTP_OK, array $additional = []): JsonResponse
    {
        return response()->json(array_merge(['success' => true, 'message' => $message], $additional), $status);
    }

    /**
     * Helper to return a JSON response with a JsonResource, extracting data, meta, and links.
     *
     * @param JsonResource $resource
     * @param int $status
     *
     * @return JsonResponse
     */
    protected function respondWithResource(JsonResource $resource, int $status = Response::HTTP_OK): JsonResponse
    {
        [$data, $meta, $links] = $this->normaliseResult($resource);

        return $this->respondWithSuccess($data, $status, $meta, $links);
    }

    /**
     * Helper to return a JSON response with paginated data, using a transformer callable.
     *
     * @param LengthAwarePaginator $paginator
     * @param callable $transformer
     *
     * @return JsonResponse
     */
    protected function respondWithPagination(LengthAwarePaginator $paginator, callable $transformer): JsonResponse
    {
        if (!method_exists($this, 'formatPaginatedResponse')) {
            throw new BadMethodCallException('formatPaginatedResponse method not available on class using ApiResponse trait.');
        }

        /** @var callable $formatter */
        $formatter = [$this, 'formatPaginatedResponse'];
        $response = $formatter($paginator, $transformer);

        if (!is_array($response)) {
            throw new UnexpectedValueException('formatPaginatedResponse must return an array.');
        }

        $data = $response['data'] ?? $response;
        $meta = $response['meta'] ?? [];
        $links = $response['links'] ?? [];

        return $this->respondWithSuccess($data, Response::HTTP_OK, $meta, $links);
    }

    protected function respondWithSuccess($data = null, int $status = Response::HTTP_OK, array $meta = [], array $links = []): JsonResponse
    {
        $payload = ['success' => true];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        if ($links !== []) {
            $payload['links'] = $links;
        }

        return response()->json($payload, $status);
    }

    protected function respondWithError(string $message, array $errors = [], int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * @return array{0:mixed,1:array,2:array}
     */
    protected function normaliseResult($result): array
    {
        $meta = [];
        $links = [];

        if ($result instanceof JsonResource) {
            $response = $result->response()->getData(true);

            $data = Arr::get($response, 'data', $response);

            if (is_array($response) && array_key_exists('meta', $response) && is_array($response['meta'])) {
                $meta = $response['meta'];
            }

            if (is_array($response) && array_key_exists('links', $response) && is_array($response['links'])) {
                $links = $response['links'];
            }

            return [$data, $meta, $links];
        }

        if ($result instanceof Arrayable) {
            return [$result->toArray(), $meta, $links];
        }

        return [$result, $meta, $links];
    }
}
