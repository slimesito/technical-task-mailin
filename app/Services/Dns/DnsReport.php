<?php

namespace App\Services\Dns;

/**
 * All DNS data collected for one domain, keyed by record type
 * (A, AAAA, CNAME, NS, MX, TXT, SPF, DMARC, DKIM, PTR).
 */
final class DnsReport
{
    /** Record types whose joint failure means "DNS is not working", not "domain has no records". */
    private const BASE_TYPES = ['A', 'MX', 'NS', 'TXT'];

    /** @var array<string, array{status:string, values:list<mixed>, error:?string}> */
    private array $records = [];

    /** @param list<mixed> $values */
    public function set(string $type, DnsStatus $status, array $values = [], ?string $error = null): void
    {
        $this->records[$type] = ['status' => $status->value, 'values' => $values, 'error' => $error];
    }

    public function setFromAnswer(string $type, DnsAnswer $answer): void
    {
        $this->set($type, $answer->status, $answer->records, $answer->error);
    }

    public function status(string $type): ?DnsStatus
    {
        return isset($this->records[$type]) ? DnsStatus::from($this->records[$type]['status']) : null;
    }

    /** @return list<mixed> */
    public function values(string $type): array
    {
        return $this->records[$type]['values'] ?? [];
    }

    /** @return list<array{priority:int,host:string}> */
    public function mx(): array
    {
        return $this->values('MX');
    }

    /** @return list<string> */
    public function ipv4(): array
    {
        return $this->values('A');
    }

    /** @return array<string, array{status:string, values:list<mixed>, error:?string}> */
    public function toArray(): array
    {
        return $this->records;
    }

    /** @return list<array{source:string, type:string, message:string}> */
    public function errors(): array
    {
        $errors = [];
        foreach ($this->records as $type => $record) {
            if (DnsStatus::from($record['status'])->isTransientFailure()) {
                $errors[] = ['source' => $type, 'type' => $record['status'], 'message' => $record['error'] ?? 'Lookup failed'];
            }
        }

        return $errors;
    }

    /** True when every base lookup that was run failed to get any definitive answer. */
    public function isTotalFailure(): bool
    {
        $checked = array_filter(self::BASE_TYPES, fn (string $type) => $this->status($type) !== null);

        if ($checked === []) {
            return false;
        }

        foreach ($checked as $type) {
            if ($this->status($type)->isDefinitive()) {
                return false;
            }
        }

        return true;
    }
}
