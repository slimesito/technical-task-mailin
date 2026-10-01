<?php

namespace App\Services\Dns;

use NetDNS2\ENUM\Error;
use NetDNS2\Exception as NetDnsException;
use NetDNS2\Resolver;
use NetDNS2\RR;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * DnsResolver backed by pear/net_dns2.
 *
 * Unlike dns_get_record(), every query has a hard socket timeout, so a dead
 * nameserver can never hang a queue worker. Timeouts/errors are retried
 * `$retries` times before being reported as a failed answer.
 */
final class NetDnsResolver implements DnsResolver
{
    /** @param list<string> $nameservers */
    public function __construct(
        private readonly array $nameservers,
        private readonly float $timeout,
        private readonly int $retries,
        private readonly LoggerInterface $logger,
    ) {}

    public function query(string $name, string $type): DnsAnswer
    {
        $attempt = 0;

        do {
            $attempt++;
            $answer = $this->attempt($name, $type);

            if ($answer->status->isDefinitive()) {
                return $answer;
            }

            $this->logger->warning('DNS query failed', [
                'name' => $name,
                'type' => $type,
                'status' => $answer->status->value,
                'error' => $answer->error,
                'attempt' => $attempt,
                'nameservers' => $this->nameservers,
            ]);
        } while ($attempt <= $this->retries);

        return $answer;
    }

    private function attempt(string $name, string $type): DnsAnswer
    {
        try {
            $resolver = new Resolver([
                'nameservers' => $this->nameservers,
                'timeout' => $this->timeout,
                'ns_random' => true,
            ]);

            $response = $resolver->query($name, $type);
        } catch (NetDnsException $e) {
            return $this->fromException($e);
        } catch (Throwable $e) {
            return DnsAnswer::failure(DnsStatus::Error, $e->getMessage());
        }

        $records = [];
        foreach ($response->answer as $rr) {
            // A CNAME chain can precede the records we asked for; keep only the requested type.
            if ($rr->type->name !== $type) {
                continue;
            }
            $records[] = $this->normalise($type, $rr);
        }

        return $records === []
            ? new DnsAnswer(DnsStatus::NoData)
            : new DnsAnswer(DnsStatus::Ok, $records);
    }

    private function fromException(NetDnsException $e): DnsAnswer
    {
        $code = $e->getCode();

        if ($code === Error::DNS_NXDOMAIN->value) {
            return new DnsAnswer(DnsStatus::NxDomain);
        }

        if ($code === Error::DNS_SERVFAIL->value) {
            return DnsAnswer::failure(DnsStatus::ServFail, $e->getMessage());
        }

        if (in_array($code, [Error::INT_FAILED_SOCKET->value, Error::INT_FAILED_NAMESERVER->value], true)) {
            return DnsAnswer::failure(DnsStatus::Timeout, $e->getMessage());
        }

        return DnsAnswer::failure(DnsStatus::Error, $e->getMessage());
    }

    /** @return string|array{priority:int, host:string} */
    private function normalise(string $type, RR $rr): string|array
    {
        return match ($type) {
            'A', 'AAAA' => (string) $rr->address,
            'MX' => ['priority' => (int) $rr->preference, 'host' => strtolower((string) $rr->exchange)],
            'NS' => strtolower((string) $rr->nsdname),
            'CNAME' => strtolower((string) $rr->cname),
            'PTR' => strtolower((string) $rr->ptrdname),
            'TXT' => implode('', array_map(fn ($chunk) => $chunk->value(), $rr->text)),
            default => (string) $rr,
        };
    }
}
