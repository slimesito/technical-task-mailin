<?php

namespace App\Actions\Checks;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Exceptions\TransientDnsException;
use App\Models\DomainCheck;
use App\Services\Blacklist\BlacklistChecker;
use App\Services\Dns\DnsRecordFetcher;
use App\Services\Dns\DnsReport;
use App\Services\Provider\ProviderDetector;
use App\Support\CheckBudget;

/**
 * Runs one domain check end to end and stores the outcome on the model.
 * Shared by the synchronous single-check endpoint and the queued job.
 */
final class RunDomainCheck
{
    public function __construct(
        private readonly DnsRecordFetcher $fetcher,
        private readonly BlacklistChecker $blacklist,
        private readonly ProviderDetector $detector,
    ) {}

    /**
     * @param  bool  $strict  Queue mode: if DNS is entirely unreachable, throw so the job is retried
     *                        instead of recording a result. Otherwise the check is stored as failed.
     *
     * @throws TransientDnsException
     */
    public function handle(DomainCheck $check, bool $strict = false): DomainCheck
    {
        $startedAt = hrtime(true);
        $budget = new CheckBudget(config('domain_checks.dns.check_budget'));

        $report = $check->type === CheckType::Blacklist
            ? $this->fetcher->fetch($check->domain, $check->dkim_selector, $budget)
            : $this->fetcher->fetchMailRecords($check->domain, $budget);

        if ($report->isTotalFailure()) {
            if ($strict) {
                throw new TransientDnsException("All DNS lookups failed for {$check->domain}");
            }

            return $this->store($check, $report, [], CheckStatus::Failed, $startedAt);
        }

        $attributes = $check->type === CheckType::Blacklist
            ? $this->blacklistAttributes($check, $report, $budget)
            : $this->providerAttributes($report);

        return $this->store($check, $report, $attributes, CheckStatus::Completed, $startedAt);
    }

    /** @return array<string, mixed> */
    private function blacklistAttributes(DomainCheck $check, DnsReport $report, CheckBudget $budget): array
    {
        $result = $this->blacklist->check($check->domain, $report, $budget);

        return [
            'blacklist_status' => $result->status,
            'listed_on' => $result->listings,
            'extra_errors' => $result->errors,
        ];
    }

    /** @return array<string, mixed> */
    private function providerAttributes(DnsReport $report): array
    {
        $result = $this->detector->detect($report);

        return [
            'provider' => $result->provider,
            'provider_status' => $result->statusValue(),
            'evidence' => $result->evidence,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function store(DomainCheck $check, DnsReport $report, array $attributes, CheckStatus $status, int $startedAt): DomainCheck
    {
        $extraErrors = $attributes['extra_errors'] ?? [];
        unset($attributes['extra_errors']);

        $check->forceFill([
            ...$attributes,
            'status' => $status,
            'mx_records' => $report->mx(),
            'dns_records' => $report->toArray(),
            'errors' => [...$report->errors(), ...$extraErrors],
            'completed_at' => now(),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ])->save();

        return $check;
    }
}
