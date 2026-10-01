<?php

namespace App\Services\Dns;

use App\Support\CheckBudget;

/** Collects MX, SPF/TXT, DKIM, DMARC, A, AAAA, CNAME, NS and PTR for a domain. */
final class DnsRecordFetcher
{
    private const MAX_PTR_LOOKUPS = 3;

    public function __construct(private readonly DnsResolver $resolver) {}

    public function fetch(string $domain, ?string $dkimSelector = null, ?CheckBudget $budget = null): DnsReport
    {
        $report = new DnsReport;

        foreach (['A', 'AAAA', 'CNAME', 'NS', 'MX'] as $type) {
            $this->lookup($report, $type, $domain, $type, $budget);
        }

        $this->lookup($report, 'TXT', $domain, 'TXT', $budget);
        $this->deriveSpf($report);

        $this->lookup($report, 'DMARC', "_dmarc.{$domain}", 'TXT', $budget);
        $this->filterTxt($report, 'DMARC', 'v=dmarc1');

        if ($dkimSelector !== null && $dkimSelector !== '') {
            $this->lookup($report, 'DKIM', "{$dkimSelector}._domainkey.{$domain}", 'TXT', $budget);
            $this->filterTxt($report, 'DKIM', 'v=dkim1');
        }

        $this->lookupPtr($report, $budget);

        return $report;
    }

    /** Lighter fetch for provider detection: only MX and TXT/SPF are needed. */
    public function fetchMailRecords(string $domain, ?CheckBudget $budget = null): DnsReport
    {
        $report = new DnsReport;

        $this->lookup($report, 'MX', $domain, 'MX', $budget);
        $this->lookup($report, 'TXT', $domain, 'TXT', $budget);
        $this->deriveSpf($report);

        return $report;
    }

    private function lookup(DnsReport $report, string $key, string $name, string $type, ?CheckBudget $budget): void
    {
        if ($budget?->exhausted()) {
            $report->set($key, DnsStatus::Timeout, [], 'Check time budget exhausted');

            return;
        }

        $report->setFromAnswer($key, $this->resolver->query($name, $type));
    }

    private function deriveSpf(DnsReport $report): void
    {
        $txtStatus = $report->status('TXT');

        if ($txtStatus === null || $txtStatus->isTransientFailure()) {
            $report->set('SPF', $txtStatus ?? DnsStatus::Error, [], 'TXT lookup failed');

            return;
        }

        $this->filterTxt($report, 'SPF', 'v=spf1', source: 'TXT');
    }

    /** Keep only TXT values that start with $prefix (case-insensitive); empty => nodata. */
    private function filterTxt(DnsReport $report, string $key, string $prefix, ?string $source = null): void
    {
        $source ??= $key;
        $status = $report->status($source);

        if ($status === null || $status->isTransientFailure()) {
            return;
        }

        $matches = array_values(array_filter(
            $report->values($source),
            fn (string $txt) => str_starts_with(strtolower(trim($txt)), $prefix),
        ));

        $report->set($key, $matches === [] ? DnsStatus::NoData : DnsStatus::Ok, $matches);
    }

    private function lookupPtr(DnsReport $report, ?CheckBudget $budget): void
    {
        $ips = array_slice($report->ipv4(), 0, self::MAX_PTR_LOOKUPS);

        if ($ips === []) {
            $report->set('PTR', DnsStatus::NoData);

            return;
        }

        $values = [];
        $failure = null;

        foreach ($ips as $ip) {
            if ($budget?->exhausted()) {
                $failure = DnsAnswer::failure(DnsStatus::Timeout, 'Check time budget exhausted');
                break;
            }

            $answer = $this->resolver->query(self::reverseIpv4($ip), 'PTR');
            foreach ($answer->strings() as $host) {
                $values[] = ['ip' => $ip, 'host' => $host];
            }
            if ($answer->status->isTransientFailure()) {
                $failure = $answer;
            }
        }

        if ($values !== []) {
            $report->set('PTR', DnsStatus::Ok, $values);
        } elseif ($failure !== null) {
            $report->setFromAnswer('PTR', $failure);
        } else {
            $report->set('PTR', DnsStatus::NoData);
        }
    }

    public static function reverseIpv4(string $ip): string
    {
        return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
    }
}
