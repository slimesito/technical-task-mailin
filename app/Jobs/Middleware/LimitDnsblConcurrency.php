<?php

namespace App\Jobs\Middleware;

use App\Jobs\ProcessDomainCheck;
use Closure;
use Illuminate\Support\Facades\Redis;

/**
 * Caps how many blacklist checks run at once across ALL workers and servers
 * (Redis funnel). Horizon's maxProcesses limits one supervisor; this keeps the
 * DNSBL providers safe even if workers are scaled out.
 */
class LimitDnsblConcurrency
{
    public function handle(ProcessDomainCheck $job, Closure $next): void
    {
        Redis::funnel('dnsbl-checks')
            ->limit(config('domain_checks.blacklists.concurrency'))
            ->releaseAfter(90)
            ->block(2)
            ->then(fn () => $next($job), fn () => $job->release(5));
    }
}
