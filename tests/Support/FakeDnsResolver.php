<?php

namespace Tests\Support;

use App\Services\Dns\DnsAnswer;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\DnsStatus;

/** In-memory resolver: unknown names answer NXDOMAIN, like a real DNS would. */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, DnsAnswer> */
    private array $answers = [];

    /** @var list<string> */
    public array $queries = [];

    /** @param list<mixed> $records */
    public function records(string $type, string $name, array $records): self
    {
        $this->answers["{$type} {$name}"] = new DnsAnswer(DnsStatus::Ok, $records);

        return $this;
    }

    public function fail(string $type, string $name, DnsStatus $status = DnsStatus::Timeout): self
    {
        $this->answers["{$type} {$name}"] = DnsAnswer::failure($status, "simulated {$status->value}");

        return $this;
    }

    /** Make every query that has no explicit answer fail (simulates an unreachable DNS). */
    public bool $failEverythingElse = false;

    public function query(string $name, string $type): DnsAnswer
    {
        $this->queries[] = "{$type} {$name}";

        return $this->answers["{$type} {$name}"]
            ?? ($this->failEverythingElse
                ? DnsAnswer::failure(DnsStatus::Timeout, 'simulated timeout')
                : new DnsAnswer(DnsStatus::NxDomain));
    }
}
