<?php

namespace App\Jobs;

use App\Actions\Checks\RunDomainCheck;
use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Events\BulkCheckFinished;
use App\Events\DomainCheckUpdated;
use App\Exceptions\TransientDnsException;
use App\Jobs\Middleware\LimitDnsblConcurrency;
use App\Models\DomainCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks one domain of a bulk upload.
 *
 * Failure strategy:
 *  - Per-query DNS timeouts are enforced inside the resolver (2s), never by PHP's own hanging functions.
 *  - Partial failures (one DNSBL down, no DMARC) do not fail the job; they are stored in `errors`.
 *  - If DNS is entirely unreachable the job throws and is retried with backoff, at most 3 real failures.
 *    Throttling releases (funnel full) do not count towards that limit.
 *  - $timeout is the last line of defence: if anything hangs, the worker is killed and the job fails.
 */
class ProcessDomainCheck implements ShouldQueue
{
    use Queueable;

    /** Retries are bounded by time and by exceptions, so funnel releases never burn an attempt. */
    public int $maxExceptions = 3;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public DomainCheck $check)
    {
        $this->onQueue(config('domain_checks.bulk.queue'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return $this->check->type === CheckType::Blacklist ? [new LimitDnsblConcurrency] : [];
    }

    public function handle(RunDomainCheck $action): void
    {
        $check = $this->check->fresh();

        // Already settled (e.g. redelivered after a worker crash): nothing to do.
        if ($check === null || in_array($check->status, [CheckStatus::Completed, CheckStatus::Failed], true)) {
            return;
        }

        $bulk = $check->bulkCheck;
        $bulk->markProcessing();

        $check->update([
            'status' => CheckStatus::Checking,
            'started_at' => now(),
            'attempts' => $this->attempts(),
        ]);
        DomainCheckUpdated::dispatch($check, $bulk->refresh());

        try {
            $action->handle($check, strict: true);
        } catch (TransientDnsException $e) {
            Log::channel('dns')->warning('Domain check will be retried', [
                'domain' => $check->domain,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            $check->update(['status' => CheckStatus::Queued]);
            DomainCheckUpdated::dispatch($check, $bulk->refresh());

            throw $e;
        }

        $this->settle($check);
    }

    /** Called once the job has permanently failed (retries exhausted, timeout or unexpected error). */
    public function failed(Throwable $exception): void
    {
        $check = $this->check->fresh();

        if ($check === null || $check->status === CheckStatus::Completed) {
            return;
        }

        Log::channel('dns')->error('Domain check failed permanently', [
            'domain' => $check->domain,
            'error' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);

        $check->forceFill([
            'status' => CheckStatus::Failed,
            'completed_at' => now(),
            'errors' => [[
                'source' => 'job',
                'type' => class_basename($exception),
                'message' => $exception->getMessage(),
            ]],
        ])->save();

        $this->settle($check);
    }

    /** Refreshes the batch counters, tells the UI, and closes the batch when it was the last domain. */
    private function settle(DomainCheck $check): void
    {
        $bulk = $check->bulkCheck;
        $bulk->refreshProgress();

        DomainCheckUpdated::dispatch($check, $bulk);

        if ($bulk->completeIfDone()) {
            BulkCheckFinished::dispatch($bulk->refresh());
        }
    }
}
