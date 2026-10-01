<?php

use App\Services\Dns\DnsResolver;
use Tests\Support\FakeDnsResolver;

beforeEach(function () {
    $this->dns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->dns);
});

it('detects the provider for an email address synchronously', function () {
    $this->dns->records('MX', 'acme.com', [['priority' => 1, 'host' => 'aspmx.l.google.com']]);

    $this->postJson('/api/checks/provider/single', ['input' => 'jane@Acme.com'])
        ->assertCreated()
        ->assertJsonPath('domain', 'acme.com')
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('provider', 'Google Workspace')
        ->assertJsonPath('provider_status', 'detected')
        ->assertJsonPath('mx_records.0.host', 'aspmx.l.google.com');

    $this->assertDatabaseHas('domain_checks', ['domain' => 'acme.com', 'bulk_check_id' => null]);
});

it('returns blacklist status, listing source and DNS records', function () {
    $this->dns
        ->records('A', 'acme.com', ['1.2.3.4'])
        ->records('A', '4.3.2.1.zen.spamhaus.org', ['127.0.0.2'])
        ->records('TXT', 'acme.com', ['v=spf1 -all'])
        ->records('TXT', 'sel._domainkey.acme.com', ['v=DKIM1; p=abc']);

    $this->postJson('/api/checks/blacklist/single', ['input' => 'acme.com', 'dkim_selector' => 'sel'])
        ->assertCreated()
        ->assertJsonPath('blacklist_status', 'listed')
        ->assertJsonPath('listed_on.0.name', 'Spamhaus ZEN')
        ->assertJsonPath('dns_records.SPF.values.0', 'v=spf1 -all')
        ->assertJsonPath('dns_records.DKIM.status', 'ok')
        ->assertJsonPath('dns_summary.A', 'ok');
});

it('stores the check as failed when DNS is completely unreachable', function () {
    $this->dns->failEverythingElse = true;

    $this->postJson('/api/checks/provider/single', ['input' => 'acme.com'])
        ->assertCreated()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('errors.0.type', 'timeout');
});

it('rejects invalid input with a validation error', function (array $payload) {
    $this->postJson('/api/checks/provider/single', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('input');
})->with([
    'missing' => [[]],
    'not a domain' => [['input' => 'not a domain']],
    'ip address' => [['input' => '10.0.0.1']],
]);

it('returns 404 for an unknown tool type', function () {
    $this->postJson('/api/checks/nope/single', ['input' => 'acme.com'])->assertNotFound();
});
