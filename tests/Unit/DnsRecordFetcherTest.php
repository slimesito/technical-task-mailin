<?php

use App\Services\Dns\DnsRecordFetcher;
use App\Services\Dns\DnsStatus;
use Tests\Support\FakeDnsResolver;

it('extracts SPF, DMARC and DKIM from TXT records', function () {
    $dns = (new FakeDnsResolver)
        ->records('TXT', 'example.com', ['v=spf1 include:_spf.google.com ~all', 'site-verification=abc'])
        ->records('TXT', '_dmarc.example.com', ['v=DMARC1; p=reject'])
        ->records('TXT', 'google._domainkey.example.com', ['v=DKIM1; k=rsa; p=MIGf'])
        ->records('A', 'example.com', ['93.184.216.34'])
        ->records('PTR', '34.216.184.93.in-addr.arpa', ['host.example.net']);

    $report = (new DnsRecordFetcher($dns))->fetch('example.com', 'google');

    expect($report->values('SPF'))->toBe(['v=spf1 include:_spf.google.com ~all'])
        ->and($report->values('DMARC'))->toBe(['v=DMARC1; p=reject'])
        ->and($report->values('DKIM'))->toBe(['v=DKIM1; k=rsa; p=MIGf'])
        ->and($report->values('PTR'))->toBe([['ip' => '93.184.216.34', 'host' => 'host.example.net']])
        ->and($report->status('MX'))->toBe(DnsStatus::NxDomain);
});

it('does not query DKIM when no selector is given', function () {
    $dns = new FakeDnsResolver;

    $report = (new DnsRecordFetcher($dns))->fetch('example.com');

    expect($report->status('DKIM'))->toBeNull()
        ->and(implode(' ', $dns->queries))->not->toContain('_domainkey');
});

it('marks SPF as missing when TXT exists without an SPF record', function () {
    $dns = (new FakeDnsResolver)->records('TXT', 'example.com', ['something-else']);

    $report = (new DnsRecordFetcher($dns))->fetch('example.com');

    expect($report->status('SPF'))->toBe(DnsStatus::NoData);
});

it('flags a total DNS failure only when every base lookup failed', function () {
    $dead = new FakeDnsResolver;
    $dead->failEverythingElse = true;
    expect((new DnsRecordFetcher($dead))->fetch('example.com')->isTotalFailure())->toBeTrue();

    $partial = (new FakeDnsResolver)->fail('MX', 'example.com');
    $partial->failEverythingElse = false;
    expect((new DnsRecordFetcher($partial))->fetch('example.com')->isTotalFailure())->toBeFalse();
});

it('records errors for failed lookups so they can be shown and logged', function () {
    $dns = (new FakeDnsResolver)->fail('MX', 'example.com');

    $errors = (new DnsRecordFetcher($dns))->fetch('example.com')->errors();

    expect($errors)->toHaveCount(1)->and($errors[0])->toMatchArray(['source' => 'MX', 'type' => 'timeout']);
});
