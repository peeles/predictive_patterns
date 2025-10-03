<?php

namespace App\Services;

use App\Enums\ModelStatus;
use App\Events\ModelStatusUpdated;
use App\Models\PredictiveModel;
use App\Support\Broadcasting\BroadcastDispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ModelStatusService
{
    private readonly string $channel;

    public function __construct()
    {
        $this->channel = (string) config('model-status.channel', 'models:status');
    }

    public function markQueued(string $modelId, string $jobType, ?string $message = null): array
    {
        return $this->markProgress($modelId, $jobType, 0.0, $message);
    }

    public function markProgress(string $modelId, string $jobType, float $progress, ?string $message = null): array
    {
        $state = $this->normalizeJobType($jobType);
        $normalized = $this->normalizeProgress($progress);

        return $this->store($modelId, $state, $normalized, $message);
    }

    public function markIdle(string $modelId, ?string $message = null): array
    {
        return $this->store($modelId, 'idle', 100.0, $message);
    }

    public function markFailed(string $modelId, ?string $message = null): array
    {
        return $this->store($modelId, 'failed', null, $message);
    }

    public function forget(string $modelId): void
    {
        try {
            Redis::del($this->key($modelId));
        } catch (Throwable) {
            // Ignored - status information is best-effort.
        }
    }

    public function getStatus(PredictiveModel $model): array
    {
        $payload = null;

        try {
            $payload = Redis::get($this->key($model->getKey()));
        } catch (Throwable) {
            $payload = null;
        }

        if (is_string($payload) && $payload !== '') {
            /** @var array{state?: string, progress?: float|null, updated_at?: string, message?: string|null}|null $decoded */
            $decoded = json_decode($payload, true);

            if (is_array($decoded)) {
                return [
                    'state' => (string) Arr::get($decoded, 'state', 'idle'),
                    'progress' => Arr::get($decoded, 'progress'),
                    'updated_at' => (string) Arr::get($decoded, 'updated_at', now()->toIso8601String()),
                    'message' => Arr::get($decoded, 'message'),
                ];
            }
        }

        $status = $model->status instanceof ModelStatus ? $model->status->value : (string) $model->status;
        $updatedAt = $model->updated_at ?? $model->trained_at ?? $model->created_at ?? now();

        return [
            'state' => $status ?: 'idle',
            'progress' => null,
            'updated_at' => $updatedAt->toIso8601String(),
            'message' => null,
        ];
    }

    private function store(string $modelId, string $state, ?float $progress, ?string $message = null): array
    {
        $timestamp = now()->toIso8601String();

        $payload = [
            'state' => $state,
            'progress' => $progress,
            'updated_at' => $timestamp,
            'message' => $this->normalizeMessage($message),
        ];

        $encoded = json_encode($payload);
        $ttl = max((int) config('model-status.ttl', 3600), 60);

        try {
            Redis::setex($this->key($modelId), $ttl, (string) $encoded);
            Redis::publish($this->channel, json_encode([
                'model_id' => $modelId,
                'state' => $payload['state'],
                'progress' => $payload['progress'],
                'updated_at' => $payload['updated_at'],
                'message' => $payload['message'],
            ]));
        } catch (Throwable) {
            // Redis failures should not prevent the request lifecycle.
        }

        BroadcastDispatcher::dispatch(new ModelStatusUpdated(
            $modelId,
            $payload['state'],
            $payload['progress'],
            $payload['updated_at'],
            $payload['message'],
        ), [
            'model_id' => $modelId,
            'state' => $payload['state'],
        ]);

        return $payload;
    }

    private function key(string $modelId): string
    {
        return sprintf('model-status:%s', $modelId);
    }

    private function normalizeJobType(string $jobType): string
    {
        return match ($jobType) {
            'training', 'train' => 'training',
            'evaluation', 'evaluate', 'evaluating' => 'evaluating',
            default => $jobType,
        };
    }

    private function normalizeProgress(float $progress): float
    {
        if (is_nan($progress) || is_infinite($progress)) {
            return 0.0;
        }

        return round(min(100, max(0, $progress)), 2);
    }

    private function normalizeMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $trimmed = trim($message);

        return $trimmed !== '' ? $trimmed : null;
    }
}
