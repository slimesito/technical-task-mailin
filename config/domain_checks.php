<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DNS resolution
    |--------------------------------------------------------------------------
    | Nameservers used for every lookup. Public resolvers such as 8.8.8.8 are
    | rate-limited or refused by several DNSBLs (notably Spamhaus), so point
    | this at your own recursive resolver in production.
    */
    'dns' => [
        'nameservers' => array_filter(explode(',', (string) env('DNS_NAMESERVERS', '1.1.1.1,8.8.8.8'))),
        'timeout' => (float) env('DNS_TIMEOUT', 2),
        'query_retries' => (int) env('DNS_QUERY_RETRIES', 1),
        // Maximum wall-clock seconds one domain check may spend before aborting.
        'check_budget' => (int) env('DNS_CHECK_BUDGET', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | DNSBL / RBL providers
    |--------------------------------------------------------------------------
    | "domain" zones are queried with the domain name, "ip" zones with the
    | reversed IPv4 address of the domain's A and MX hosts.
    */
    'blacklists' => [
        'domain' => [
            'dbl.spamhaus.org' => 'Spamhaus DBL',
            'multi.surbl.org' => 'SURBL',
            'multi.uribl.com' => 'URIBL',
        ],
        'ip' => [
            'zen.spamhaus.org' => 'Spamhaus ZEN',
            'bl.spamcop.net' => 'SpamCop',
            'b.barracudacentral.org' => 'Barracuda',
            'psbl.surriel.com' => 'PSBL',
            'dnsbl-1.uceprotect.net' => 'UCEProtect L1',
        ],
        // Answers in these ranges mean "query refused / resolver blocked", not "listed".
        'refusal_codes' => [
            'dbl.spamhaus.org' => ['127.255.255.252', '127.255.255.254', '127.255.255.255'],
            'zen.spamhaus.org' => ['127.255.255.252', '127.255.255.254', '127.255.255.255'],
            'multi.uribl.com' => ['127.0.0.1'],
            'multi.surbl.org' => ['127.0.0.1'],
        ],
        // Max concurrent DNSBL lookups across ALL workers (Redis funnel).
        'concurrency' => (int) env('DNSBL_CONCURRENCY', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk uploads
    |--------------------------------------------------------------------------
    */
    'bulk' => [
        'max_rows' => (int) env('BULK_MAX_ROWS', 10000),
        'max_file_kb' => (int) env('BULK_MAX_FILE_KB', 5120),
        'queue' => 'checks',
    ],

];
