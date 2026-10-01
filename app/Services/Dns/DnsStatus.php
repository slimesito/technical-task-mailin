<?php

namespace App\Services\Dns;

enum DnsStatus: string
{
    case Ok = 'ok';
    case NoData = 'nodata';
    case NxDomain = 'nxdomain';
    case Timeout = 'timeout';
    case ServFail = 'servfail';
    case Error = 'error';

    /** The DNS server answered definitively (even if the answer is "nothing"). */
    public function isDefinitive(): bool
    {
        return in_array($this, [self::Ok, self::NoData, self::NxDomain], true);
    }

    /** A failure worth retrying: the lookup did not produce a trustworthy answer. */
    public function isTransientFailure(): bool
    {
        return ! $this->isDefinitive();
    }
}
