<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class QueueManager
{
    public function getQueueSize(string $queue = 'default'): int
    {
        return Redis::llen("queues:{$queue}");
    }

    public function clearQueue(string $queue = 'default'): void
    {
        Redis::del("queues:{$queue}");
    }

    public function pauseQueue(string $queue = 'default'): void
    {
        Cache::put("queue.paused.{$queue}", true, 3600);
    }

    public function resumeQueue(string $queue = 'default'): void
    {
        Cache::forget("queue.paused.{$queue}");
    }

    public function isPaused(string $queue = 'default'): bool
    {
        return Cache::get("queue.paused.{$queue}", false);
    }
}
