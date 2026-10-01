<?php

use App\Jobs\Middleware\LimitDnsblConcurrency;
use App\Jobs\ProcessDomainCheck;
use App\Models\DomainCheck;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis is not available.');
    }
});

it('runs the job through the funnel and passes the job to the next step', function () {
    $job = new ProcessDomainCheck(DomainCheck::factory()->create());
    $received = null;

    (new LimitDnsblConcurrency)->handle($job, function ($passed) use (&$received) {
        $received = $passed;
    });

    expect($received)->toBe($job);
});

it('releases the job back to the queue when the funnel is full', function () {
    config(['domain_checks.blacklists.concurrency' => 1]);

    $job = new class(DomainCheck::factory()->create()) extends ProcessDomainCheck
    {
        public ?int $releasedFor = null;

        public function release($delay = 0)
        {
            $this->releasedFor = $delay;
        }
    };

    $ran = false;
    // Occupy the only slot, then try to enter from inside it.
    (new LimitDnsblConcurrency)->handle($job, function () use ($job, &$ran) {
        (new LimitDnsblConcurrency)->handle($job, function () use (&$ran) {
            $ran = true;
        });
    });

    expect($ran)->toBeFalse()->and($job->releasedFor)->toBe(5);
});
