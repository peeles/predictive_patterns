<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class BaseController extends Controller
{
    /**
     * success response method.
     *
     * @param $result
     * @param int $code
     * @return JsonResponse
     */
    public function successResponse($result = null, int $code = 200): JsonResponse
    {
        [$data, $meta, $links] = $this->normaliseResult($result);

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

        return response()->json($payload, $code);
    }

    /**
     * return error response.
     *
     * @param $error
     * @param array $errorMessages
     * @param int $code
     * @return JsonResponse
     */
    public function errorResponse($error, array $errorMessages = [], int $code = 404): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['errors'] = $errorMessages;
        }

        return response()->json($response, $code);
    }

    protected function rateLimitResponse(string $message, ?int $retryAfter = null, string $code = 'too_many_requests'): JsonResponse
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        $response = response()->json($payload, 429);

        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', max(0, $retryAfter));
        }

        return $response;
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
