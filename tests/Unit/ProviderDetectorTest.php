<?php

use App\Enums\MailProvider;
use App\Services\Dns\DnsReport;
use App\Services\Dns\DnsStatus;
use App\Services\Provider\ProviderDetector;

function reportWith(array $mx, array $txt = [], DnsStatus $mxStatus = DnsStatus::Ok): DnsReport
{
    $report = new DnsReport;
    $report->set('MX', $mx === [] && $mxStatus === DnsStatus::Ok ? DnsStatus::NoData : $mxStatus, $mx);
    $report->set('TXT', DnsStatus::Ok, $txt);

    return $report;
}

function mx(int $priority, string $host): array
{
    return ['priority' => $priority, 'host' => $host];
}

it('detects Google Workspace from MX', function (string $host) {
    $result = (new ProviderDetector)->detect(reportWith([mx(1, $host)]));

    expect($result->provider)->toBe(MailProvider::Google)
        ->and($result->detected)->toBeTrue()
        ->and($result->evidence[0])->toContain($host);
})->with(['aspmx.l.google.com', 'alt1.aspmx.l.google.com', 'smtp.google.com', 'aspmx.googlemail.com']);

it('detects Microsoft 365 from MX', function (string $host) {
    $result = (new ProviderDetector)->detect(reportWith([mx(0, $host)]));

    expect($result->provider)->toBe(MailProvider::Microsoft)->and($result->detected)->toBeTrue();
})->with(['contoso-com.mail.protection.outlook.com', 'contoso.mx.microsoft']);

it('reports Other when MX belongs to a different provider', function () {
    $result = (new ProviderDetector)->detect(reportWith([mx(10, 'mx.zoho.com')], ['v=spf1 include:zoho.com ~all']));

    expect($result->provider)->toBe(MailProvider::Other)->and($result->detected)->toBeTrue();
});

it('reports Not Detected when there are no MX records', function () {
    $result = (new ProviderDetector)->detect(reportWith([]));

    expect($result->provider)->toBe(MailProvider::NotDetected)
        ->and($result->detected)->toBeFalse()
        ->and($result->statusValue())->toBe('not_detected');
});

it('treats a null MX (RFC 7505) as no mail', function () {
    $result = (new ProviderDetector)->detect(reportWith([mx(0, '.')]));

    expect($result->provider)->toBe(MailProvider::NotDetected);
});

it('says so when the MX lookup itself failed', function () {
    $result = (new ProviderDetector)->detect(reportWith([], mxStatus: DnsStatus::Timeout));

    expect($result->provider)->toBe(MailProvider::NotDetected)
        ->and($result->evidence[0])->toContain('failed');
});

it('uses SPF as lower-confidence evidence behind a mail gateway', function () {
    $result = (new ProviderDetector)->detect(reportWith(
        [mx(10, 'mxa.mailgw.proofpoint.com')],
        ['v=spf1 include:spf.protection.outlook.com -all'],
    ));

    expect($result->provider)->toBe(MailProvider::Microsoft)
        ->and(implode(' ', $result->evidence))->toContain('gateway')->toContain('lower confidence');
});

it('prefers the provider with the lowest MX priority when both appear', function () {
    $result = (new ProviderDetector)->detect(reportWith([
        mx(10, 'aspmx.l.google.com'),
        mx(20, 'contoso-com.mail.protection.outlook.com'),
    ]));

    expect($result->provider)->toBe(MailProvider::Google)
        ->and(implode(' ', $result->evidence))->toContain('several providers');
});
