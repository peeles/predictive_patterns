<?php

namespace App\Jobs\Middleware;

use App\Contracts\Queue\ShouldBeAuthorized;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

class EnsureJobIsAuthorized
{
    /**
     * @param mixed $job
     * @param Closure $next
     *
     * @return mixed
     * @throws AuthorizationException
     */
    public function handle($job, Closure $next): mixed
    {
        if ($job instanceof ShouldBeAuthorized && ! $job->authorize()) {
            throw new AuthorizationException();
        }

        return $next($job);
    }
}
