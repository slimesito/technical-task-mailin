<?php

namespace App\Services\Provider;

use App\Enums\MailProvider;
use App\Services\Dns\DnsReport;

/**
 * Pure function of a DnsReport: decides which mail platform hosts the domain.
 *
 * Primary signal: MX hosts. Secondary signals (SPF includes, verification TXT
 * records) are only used when MX points at a third-party gateway, and are
 * labelled as such in the evidence.
 */
final class ProviderDetector
{
    /** MX host suffixes per provider. */
    private const MX_SUFFIXES = [
        'google' => ['.google.com', '.googlemail.com'],
        'microsoft' => ['.mail.protection.outlook.com', '.protection.outlook.com', '.mx.microsoft'],
    ];

    /** SPF / TXT fragments per provider. */
    private const TXT_SIGNALS = [
        'google' => ['include:_spf.google.com', 'include:_netblocks.google.com', 'google-site-verification='],
        'microsoft' => ['include:spf.protection.outlook.com', 'ms=ms'],
    ];

    public function detect(DnsReport $report): ProviderResult
    {
        $mxHosts = $this->usableMxHosts($report);

        if ($mxHosts === []) {
            $reason = $report->status('MX')?->isTransientFailure()
                ? 'MX lookup failed, so the provider could not be determined'
                : 'No MX records found';

            return new ProviderResult(MailProvider::NotDetected, false, [$reason]);
        }

        $evidence = [];
        $matches = [];

        foreach ($mxHosts as $mx) {
            foreach (self::MX_SUFFIXES as $key => $suffixes) {
                foreach ($suffixes as $suffix) {
                    if (str_ends_with($mx['host'], $suffix)) {
                        $matches[$key][] = $mx;
                        $evidence[] = "MX {$mx['host']} (priority {$mx['priority']}) matches {$this->label($key)}";
                        break;
                    }
                }
            }
        }

        if ($matches !== []) {
            // Mixed MX (e.g. mid-migration): the best-priority (lowest number) match wins.
            $winner = $this->bestPriority($matches);
            if (count($matches) > 1) {
                $evidence[] = "MX records point at several providers; {$this->label($winner)} has the lowest priority value";
            }

            return new ProviderResult($this->enum($winner), true, $evidence);
        }

        $signalled = $this->secondarySignals($report);
        if ($signalled !== null) {
            [$key, $reasons] = $signalled;
            $gateway = $mxHosts[0]['host'];

            return new ProviderResult($this->enum($key), true, [
                "MX {$gateway} is not a {$this->label($key)} host (likely a mail gateway)",
                ...$reasons,
                'Detected from SPF/TXT only; lower confidence than an MX match',
            ]);
        }

        return new ProviderResult(MailProvider::Other, true, [
            'MX hosts do not belong to Google Workspace or Microsoft 365: '.implode(', ', array_column($mxHosts, 'host')),
        ]);
    }

    /** @return list<array{priority:int,host:string}> MX entries sorted by priority, excluding "null MX" (RFC 7505). */
    private function usableMxHosts(DnsReport $report): array
    {
        $hosts = array_values(array_filter(
            $report->mx(),
            fn (array $mx) => $mx['host'] !== '' && $mx['host'] !== '.',
        ));

        usort($hosts, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return $hosts;
    }

    /** @return array{0:string, 1:list<string>}|null */
    private function secondarySignals(DnsReport $report): ?array
    {
        $txt = array_map('strtolower', $report->values('TXT'));

        foreach (self::TXT_SIGNALS as $key => $needles) {
            $reasons = [];
            foreach ($needles as $needle) {
                foreach ($txt as $record) {
                    if (str_contains($record, $needle)) {
                        $reasons[] = "TXT record contains \"{$needle}\"";
                        break;
                    }
                }
            }
            if ($reasons !== []) {
                return [$key, $reasons];
            }
        }

        return null;
    }

    /** @param array<string, list<array{priority:int,host:string}>> $matches */
    private function bestPriority(array $matches): string
    {
        $best = null;
        $bestPriority = PHP_INT_MAX;

        foreach ($matches as $key => $mxs) {
            foreach ($mxs as $mx) {
                if ($mx['priority'] < $bestPriority) {
                    $best = $key;
                    $bestPriority = $mx['priority'];
                }
            }
        }

        return (string) $best;
    }

    private function enum(string $key): MailProvider
    {
        return $key === 'google' ? MailProvider::Google : MailProvider::Microsoft;
    }

    private function label(string $key): string
    {
        return $this->enum($key)->value;
    }
}
