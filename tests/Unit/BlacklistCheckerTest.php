<?php

use App\Enums\BlacklistStatus;
use App\Services\Blacklist\BlacklistChecker;
use App\Services\Dns\DnsReport;
use App\Services\Dns\DnsStatus;
use Tests\Support\FakeDnsResolver;

function checker(FakeDnsResolver $dns): BlacklistChecker
{
    return new BlacklistChecker($dns, [
        'domain' => ['dbl.test' => 'Test DBL'],
        'ip' => ['rbl.test' => 'Test RBL', 'rbl2.test' => 'Test RBL 2'],
        'refusal_codes' => ['rbl2.test' => ['127.255.255.254']],
    ]);
}

function reportFor(array $ips = ['1.2.3.4']): DnsReport
{
    $report = new DnsReport;
    $report->set('A', DnsStatus::Ok, $ips);
    $report->set('MX', DnsStatus::NoData);

    return $report;
}

it('is clean when every list answers NXDOMAIN', function () {
    $result = checker(new FakeDnsResolver)->check('example.com', reportFor());

    expect($result->status)->toBe(BlacklistStatus::Clean)
        ->and($result->listings)->toBeEmpty()
        ->and($result->errors)->toBeEmpty();
});

it('reports which list flagged the IP, using the reversed address', function () {
    $dns = (new FakeDnsResolver)->records('A', '4.3.2.1.rbl.test', ['127.0.0.2']);

    $result = checker($dns)->check('example.com', reportFor());

    expect($result->status)->toBe(BlacklistStatus::Listed)
        ->and($result->listings)->toHaveCount(1)
        ->and($result->listings[0])->toMatchArray(['list' => 'rbl.test', 'name' => 'Test RBL', 'target' => '1.2.3.4', 'code' => '127.0.0.2']);
});

it('reports a listed domain from a domain blacklist', function () {
    $dns = (new FakeDnsResolver)->records('A', 'example.com.dbl.test', ['127.0.1.2']);

    $result = checker($dns)->check('example.com', reportFor([]));

    expect($result->status)->toBe(BlacklistStatus::Listed)
        ->and($result->listings[0]['target'])->toBe('example.com');
});

it('never treats a refusal code as a listing', function () {
    $dns = (new FakeDnsResolver)->records('A', '4.3.2.1.rbl2.test', ['127.255.255.254']);

    $result = checker($dns)->check('example.com', reportFor());

    expect($result->status)->toBe(BlacklistStatus::Clean)
        ->and($result->listings)->toBeEmpty()
        ->and($result->errors[0])->toMatchArray(['source' => 'rbl2.test', 'type' => 'refused']);
});

it('ignores answers outside 127.0.0.0/8', function () {
    $dns = (new FakeDnsResolver)->records('A', '4.3.2.1.rbl.test', ['203.0.113.9']);

    expect(checker($dns)->check('example.com', reportFor())->listings)->toBeEmpty();
});

it('logs a timeout but still answers from the lists that responded', function () {
    $dns = (new FakeDnsResolver)->fail('A', '4.3.2.1.rbl.test');

    $result = checker($dns)->check('example.com', reportFor());

    expect($result->status)->toBe(BlacklistStatus::Clean)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0])->toMatchArray(['source' => 'rbl.test', 'type' => 'timeout']);
});

it('is unknown, not clean, when no list could be queried', function () {
    $dns = new FakeDnsResolver;
    $dns->failEverythingElse = true;

    $result = checker($dns)->check('example.com', reportFor());

    expect($result->status)->toBe(BlacklistStatus::Unknown);
});

it('stops querying a list for the rest of the check once it has failed', function () {
    $dns = (new FakeDnsResolver)->fail('A', '4.3.2.1.rbl.test');

    checker($dns)->check('example.com', reportFor(['1.2.3.4', '5.6.7.8']));

    $rblQueries = array_filter($dns->queries, fn ($q) => str_ends_with($q, '.rbl.test'));
    expect($rblQueries)->toHaveCount(1);
});
