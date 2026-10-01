<?php

namespace App\Services\Dns;

interface DnsResolver
{
    /**
     * Run one query. Must never throw for expected DNS failures
     * (NXDOMAIN, SERVFAIL, timeouts): those are reported in the DnsAnswer.
     *
     * @param  string  $type  A, AAAA, MX, NS, TXT, CNAME or PTR
     */
    public function query(string $name, string $type): DnsAnswer;
}
