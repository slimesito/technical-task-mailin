<?php

use App\Enums\BulkStatus;
use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Events\BulkCheckFinished;
use App\Events\DomainCheckUpdated;
use App\Exceptions\TransientDnsException;
use App\Jobs\ProcessDomainCheck;
use App\Models\BulkCheck;
use App\Models\DomainCheck;
use App\Services\Dns\DnsResolver;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeDnsResolver;

beforeEach(function () {
    $this->dns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->dns);
    Event::fake([BulkCheckFinished::class]);

    // Capture each broadcast at dispatch time: the event holds a live model that keeps changing.
    $this->updates = [];
    Event::listen(DomainCheckUpdated::class, function (DomainCheckUpdated $e) {
        $this->updates[] = [
            'status' => $e->check->status->value,
            'channel' => $e->broadcastOn()->name,
            'as' => $e->broadcastAs(),
            'payload' => $e->broadcastWith(),
        ];
    });
});

function queuedCheck(BulkCheck $bulk, string $domain, CheckType $type = CheckType::Provider): DomainCheck
{
    return DomainCheck::factory()->for($bulk)->create(['type' => $type, 'domain' => $domain]);
}

function runJob(DomainCheck $check): void
{
    app()->call([new ProcessDomainCheck($check), 'handle']);
}

it('processes a domain, stores the result and broadcasts checking then completed', function () {
    $this->dns->records('MX', 'acme.com', [['priority' => 1, 'host' => 'aspmx.l.google.com']]);
    $bulk = BulkCheck::factory()->create(['type' => CheckType::Provider, 'total' => 2]);
    $check = queuedCheck($bulk, 'acme.com');
    queuedCheck($bulk, 'other.com');

    runJob($check);

    $check->refresh();
    expect($check->status)->toBe(CheckStatus::Completed)
        ->and($check->provider->value)->toBe('Google Workspace')
        ->and($check->duration_ms)->not->toBeNull();

    expect(array_column($this->updates, 'status'))->toBe(['checking', 'completed']);

    $bulk->refresh();
    expect($bulk->processed_count)->toBe(1)->and($bulk->status)->toBe(BulkStatus::Processing);
    Event::assertNotDispatched(BulkCheckFinished::class);
});

it('broadcasts on the public channel of its batch with a compact row and progress', function () {
    $bulk = BulkCheck::factory()->create(['type' => CheckType::Provider, 'total' => 1]);
    $check = queuedCheck($bulk, 'acme.com');

    runJob($check);

    $update = $this->updates[1];
    expect($update['channel'])->toBe("bulk-checks.{$bulk->uuid}")
        ->and($update['as'])->toBe('domain-check.updated')
        ->and($update['payload']['check']['domain'])->toBe('acme.com')
        ->and($update['payload']['check'])->not->toHaveKey('dns_records')
        ->and($update['payload']['progress'])->toMatchArray(['total' => 1, 'processed' => 1, 'failed' => 0]);
});

it('completes the batch and announces it when the last domain finishes', function () {
    $bulk = BulkCheck::factory()->create(['type' => CheckType::Provider, 'total' => 2]);
    $first = queuedCheck($bulk, 'a.com');
    $second = queuedCheck($bulk, 'b.com');

    runJob($first);
    runJob($second);

    $bulk->refresh();
    expect($bulk->status)->toBe(BulkStatus::Completed)
        ->and($bulk->processed_count)->toBe(2)
        ->and($bulk->finished_at)->not->toBeNull();
    Event::assertDispatchedTimes(BulkCheckFinished::class, 1);
});

it('is idempotent when a settled domain is delivered again', function () {
    $bulk = BulkCheck::factory()->create(['type' => CheckType::Provider, 'total' => 1]);
    $check = queuedCheck($bulk, 'a.com');

    runJob($check);
    runJob($check);

    expect($bulk->refresh()->processed_count)->toBe(1);
    Event::assertDispatchedTimes(BulkCheckFinished::class, 1);
});

it('throws and returns the domain to the queue when DNS is entirely unreachable', function () {
    $this->dns->failEverythingElse = true;
    $bulk = BulkCheck::factory()->create(['type' => CheckType::Provider, 'total' => 1]);
    $check = queuedCheck($bulk, 'a.com');

    expect(fn () => runJob($check))->toThrow(TransientDnsException::class);

    expect($check->refresh()->status)->toBe(CheckStatus::Queued)
        ->and($bulk->refresh()->processed_count)->toBe(0)
        ->and($bulk->failed_count)->toBe(0);
});

it('marks the domain as failed and counts it once retries are exhausted', function () {
    $bulk = BulkCheck::factory()->create(['type' => CheckType::Provider, 'total' => 1]);
    $check = queuedCheck($bulk, 'a.com');

    (new ProcessDomainCheck($check))->failed(new TransientDnsException('All DNS lookups failed for a.com'));

    $check->refresh();
    expect($check->status)->toBe(CheckStatus::Failed)
        ->and($check->errors[0]['type'])->toBe('TransientDnsException')
        ->and($check->errors[0]['message'])->toContain('a.com');

    $bulk->refresh();
    expect($bulk->failed_count)->toBe(1)->and($bulk->status)->toBe(BulkStatus::Completed);
    Event::assertDispatched(BulkCheckFinished::class);
});

it('does not turn a completed domain into a failure', function () {
    $bulk = BulkCheck::factory()->create(['type' => CheckType::Provider, 'total' => 1]);
    $check = queuedCheck($bulk, 'a.com');
    runJob($check);

    (new ProcessDomainCheck($check))->failed(new RuntimeException('late failure'));

    expect($check->refresh()->status)->toBe(CheckStatus::Completed);
});

it('keeps partial blacklist failures as logged errors without failing the job', function () {
    $this->dns->records('A', 'a.com', ['1.2.3.4'])->fail('A', 'a.com.dbl.spamhaus.org');
    $bulk = BulkCheck::factory()->create(['type' => CheckType::Blacklist, 'total' => 1]);
    $check = queuedCheck($bulk, 'a.com', CheckType::Blacklist);

    runJob($check);

    $check->refresh();
    expect($check->status)->toBe(CheckStatus::Completed)
        ->and(collect($check->errors)->pluck('source'))->toContain('dbl.spamhaus.org');
});

it('uses the dedicated queue, retry backoff and a bounded timeout', function () {
    $job = new ProcessDomainCheck(DomainCheck::factory()->create());

    expect($job->queue)->toBe('checks')
        ->and($job->backoff())->toBe([5, 15, 30])
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->timeout)->toBe(45)
        ->and($job->failOnTimeout)->toBeTrue();
});
