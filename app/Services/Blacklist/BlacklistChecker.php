<?php

namespace App\Services\Blacklist;

use App\Enums\BlacklistStatus;
use App\Services\Dns\DnsReport;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\DnsStatus;
use App\Support\CheckBudget;

/**
 * Checks a domain against domain blacklists (DBL) and the IPs it resolves to
 * (A records and MX hosts) against IP blacklists (RBL).
 *
 * Status: listed  - at least one list returned a valid listing code
 *         clean   - at least one list answered and none listed it
 *         unknown - every lookup failed or was refused, so nothing can be claimed
 */
final class BlacklistChecker
{
    private const MAX_IPS = 3;

    private const MAX_MX_HOSTS = 3;

    /**
     * @param  array{domain:array<string,string>, ip:array<string,string>, refusal_codes:array<string,list<string>>}  $config
     */
    public function __construct(
        private readonly DnsResolver $resolver,
        private readonly array $config,
    ) {}

    public function check(string $domain, DnsReport $report, ?CheckBudget $budget = null): BlacklistResult
    {
        $listings = [];
        $errors = [];
        $answered = 0;
        $queries = 0;
        $brokenZones = [];

        $lookups = [];
        foreach ($this->config['domain'] as $zone => $name) {
            $lookups[] = [$zone, $name, $domain, "{$domain}.{$zone}"];
        }
        foreach ($this->collectIps($report, $budget) as $ip) {
            foreach ($this->config['ip'] as $zone => $name) {
                $lookups[] = [$zone, $name, $ip, self::reverseIp($ip).".{$zone}"];
            }
        }

        foreach ($lookups as [$zone, $name, $target, $query]) {
            if ($budget?->exhausted()) {
                $errors[] = ['source' => $zone, 'type' => 'timeout', 'message' => 'Check time budget exhausted'];

                continue;
            }

            // Circuit breaker: a list that failed or refused us once will do so again for this check.
            if (isset($brokenZones[$zone])) {
                continue;
            }

            $queries++;
            $answer = $this->resolver->query($query, 'A');

            if ($answer->status->isTransientFailure()) {
                $brokenZones[$zone] = true;
                $errors[] = ['source' => $zone, 'type' => $answer->status->value, 'message' => $answer->error ?? 'Lookup failed'];

                continue;
            }

            if ($answer->status !== DnsStatus::Ok) {
                $answered++; // NXDOMAIN / no data: the list answered and does not list the target.

                continue;
            }

            $code = $this->listingCode($zone, $answer->strings());
            if ($code === null) {
                $brokenZones[$zone] = true;
                $errors[] = ['source' => $zone, 'type' => 'refused', 'message' => 'List refused the query or returned an invalid code ('.implode(',', $answer->strings()).')'];

                continue;
            }

            $answered++;
            $listings[] = ['list' => $zone, 'name' => $name, 'target' => $target, 'code' => $code];
        }

        $status = match (true) {
            $listings !== [] => BlacklistStatus::Listed,
            $answered > 0 => BlacklistStatus::Clean,
            default => BlacklistStatus::Unknown,
        };

        return new BlacklistResult($status, $listings, $errors, $queries);
    }

    /** @return list<string> unique IPv4 addresses of the domain and of its first MX hosts */
    private function collectIps(DnsReport $report, ?CheckBudget $budget): array
    {
        $ips = $report->ipv4();

        foreach (array_slice($report->mx(), 0, self::MAX_MX_HOSTS) as $mx) {
            if ($budget?->exhausted()) {
                break;
            }
            $answer = $this->resolver->query($mx['host'], 'A');
            if ($answer->isOk()) {
                $ips[] = $answer->strings()[0];
            }
        }

        return array_slice(array_values(array_unique($ips)), 0, self::MAX_IPS);
    }

    /**
     * @param  list<string>  $records  A-record answers from the list
     * @return string|null the first valid listing code, or null if the answer is a refusal/invalid
     */
    private function listingCode(string $zone, array $records): ?string
    {
        $refusals = $this->config['refusal_codes'][$zone] ?? [];

        foreach ($records as $code) {
            if (str_starts_with($code, '127.') && ! in_array($code, $refusals, true)) {
                return $code;
            }
        }

        return null;
    }

    private static function reverseIp(string $ip): string
    {
        return implode('.', array_reverse(explode('.', $ip)));
    }
}
