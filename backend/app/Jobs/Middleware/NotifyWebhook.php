<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotifyWebhook
{
    /**
     * @param  object  $job
     * @param  Closure(object): mixed  $next
     */
    public function handle($job, Closure $next)
    {
        $result = $next($job);

        $webhookUrl = method_exists($job, 'getWebhookUrl') ? $job->getWebhookUrl() : null;

        if ($webhookUrl !== null) {
            try {
                Http::asJson()->post($webhookUrl, [
                    'job' => $job::class,
                    'status' => 'completed',
                    'completed_at' => now()->toIso8601String(),
                ]);
            } catch (Throwable $exception) {
                Log::warning('Webhook notification failed', [
                    'job' => $job::class,
                    'webhook_url' => $webhookUrl,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }
}
