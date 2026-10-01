<?php

namespace App\Services\Dns;

/**
 * Normalised result of one DNS query.
 *
 * Record values per type: A/AAAA/NS/CNAME/PTR/TXT => string,
 * MX => array{priority:int, host:string}.
 */
final readonly class DnsAnswer
{
    /** @param list<string|array{priority:int,host:string}> $records */
    public function __construct(
        public DnsStatus $status,
        public array $records = [],
        public ?string $error = null,
    ) {}

    public static function failure(DnsStatus $status, string $error): self
    {
        return new self($status, [], $error);
    }

    /** @return list<string> the records of single-value types (A, AAAA, NS, CNAME, PTR, TXT) */
    public function strings(): array
    {
        return array_values(array_filter($this->records, is_string(...)));
    }

    public function isOk(): bool
    {
        return $this->status === DnsStatus::Ok;
    }
}
