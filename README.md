# Domain Checking Tools

Two tools on one platform, built for the technical assessment in [CLAUDE.md](CLAUDE.md):

1. **Blacklist + DNS checker**: DNSBL/RBL status plus MX, SPF/TXT, DKIM, DMARC, A, AAAA, CNAME, NS and PTR.
2. **Mail provider detection**: Google Workspace, Microsoft 365, Other or Not Detected, with the evidence behind the verdict.

Both accept a single domain or email (answered in the request) and a CSV/TXT bulk upload (queued, processed in parallel, streamed to the browser row by row).

**Stack:** Laravel 13, PostgreSQL 16, Redis 7 (queue, cache, locks), Laravel Horizon, Laravel Reverb + Echo, React 19 with Inertia.

## Run it

Requirements: PHP 8.3 (with `pcntl`, `posix`, `pdo_pgsql`), Composer, Node 20+, Docker.

```bash
docker compose up -d                 # PostgreSQL :5432, Redis :6379
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
composer dev                         # web :8000, Horizon, Reverb :8085, logs, Vite
```

Open <http://localhost:8000> (redirects to `/blacklist`; the second tool is at `/provider`).
Horizon dashboard: <http://localhost:8000/horizon>.

Tests and static analysis (the test database must exist once: `createdb domain_checks_test`):

```bash
php artisan test        # 115 tests (Pest)
vendor/bin/phpstan analyse
vendor/bin/pint --test
npm run check && npm run types:check
```

## How it works

```
Browser ── POST /api/checks/{type}/single ──► RunDomainCheck ──► JSON result
   │
   ├──── POST /api/checks/{type}/bulk ──► parse + dedupe ──► bulk_checks + domain_checks rows
   │                                              │
   │                                              └─► Redis queue "checks" (one ProcessDomainCheck per domain)
   │                                                        │  Horizon workers, max N in parallel
   │                                                        ▼
   │                                                  RunDomainCheck
   │                                          DnsRecordFetcher → BlacklistChecker | ProviderDetector
   │                                                        │
   └◄── Reverb: "domain-check.updated" on bulk-checks.{uuid} ◄┘   (sent from the worker the moment a domain changes state)
```

- **Single check** and **bulk job** run the same `RunDomainCheck` action, so behaviour cannot drift.
- Row states are **Queued → Checking → Completed / Failed**. A retry sends the row back to Queued.
- The page keeps the batch id in the URL (`?batch=…`). On reload or after a WebSocket drop it reloads the state over HTTP, and while the socket is down it polls every 5 seconds.
- Events are applied in 150 ms batches, and the table is virtualised, so a 10,000 row batch stays responsive.

### Code map

| Concern                                                           | Where                                                                                                                                                     |
| ----------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DNS queries with hard timeout and retry                           | [app/Services/Dns/NetDnsResolver.php](app/Services/Dns/NetDnsResolver.php) behind the `DnsResolver` interface                                             |
| Record collection (MX, SPF, DKIM, DMARC, A, AAAA, CNAME, NS, PTR) | [app/Services/Dns/DnsRecordFetcher.php](app/Services/Dns/DnsRecordFetcher.php)                                                                            |
| DNSBL / RBL checks                                                | [app/Services/Blacklist/BlacklistChecker.php](app/Services/Blacklist/BlacklistChecker.php), lists in [config/domain_checks.php](config/domain_checks.php) |
| Provider detection (pure function of the DNS report)              | [app/Services/Provider/ProviderDetector.php](app/Services/Provider/ProviderDetector.php)                                                                  |
| Input cleanup and upload parsing                                  | [app/Support/DomainNormalizer.php](app/Support/DomainNormalizer.php), [app/Support/BulkUploadParser.php](app/Support/BulkUploadParser.php)                |
| Queue job                                                         | [app/Jobs/ProcessDomainCheck.php](app/Jobs/ProcessDomainCheck.php)                                                                                        |
| Broadcast events                                                  | [app/Events/DomainCheckUpdated.php](app/Events/DomainCheckUpdated.php), [app/Events/BulkCheckFinished.php](app/Events/BulkCheckFinished.php)              |
| API                                                               | [routes/api.php](routes/api.php), [app/Http/Controllers/Api/](app/Http/Controllers/Api/)                                                                  |
| Frontend                                                          | [resources/js/components/checks/](resources/js/components/checks/), [resources/js/hooks/use-bulk-check.ts](resources/js/hooks/use-bulk-check.ts)          |

### Data model

- `bulk_checks`: one per upload (`uuid`, type, `total`, `processed_count`, `failed_count`, status).
- `domain_checks`: one per domain, single or bulk. Filterable columns (`status`, `blacklist_status`, `provider`) are indexed. The variable parts (`dns_records`, `listed_on`, `evidence`, `errors`) are `jsonb`.

## Error handling, timeouts and retries

Three nested timeouts, so nothing can hang a worker:

1. **Per DNS query: 2 s.** `dns_get_record()` has no timeout and can block a worker for a long time, so the app uses Net_DNS2 with a socket timeout. A failed query is retried once.
2. **Per check: 30 s budget.** Once spent, remaining lookups are skipped and recorded as timeouts.
3. **Per job: 45 s** (`$timeout`, `$failOnTimeout`). If anything still hangs, the worker is killed and the row is marked Failed. Horizon's supervisor timeout (60 s) is higher, so the job always fails first.

What counts as an error:

| Situation                                                                 | Result                                                                                   |
| ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| NXDOMAIN or no records                                                    | A valid answer, not an error                                                             |
| One record type or one blacklist times out                                | The check still completes; the problem is stored in `errors`, shown in the UI and logged |
| A blacklist refuses the query (for example Spamhaus on a public resolver) | Recorded as `refused`; never reported as "listed"                                        |
| Every blacklist failed or refused                                         | Status is **Unknown**, never Clean                                                       |
| All base lookups failed (DNS unreachable)                                 | The job throws and is retried                                                            |

Retries: up to **3 failures** with backoff of 5, 15 and 30 s, within a 15 minute window. After that `failed()` marks the row Failed with the reason, and the batch still completes. Releases caused by the concurrency limit do not use up retries (`maxExceptions` plus `retryUntil` instead of `$tries`).

Logging: the `dns` channel (`storage/logs/dns-*.log`) records every failed query (name, type, status, attempt, nameservers) and every retry or permanent failure.

Progress counters are recomputed from the rows in a single SQL statement rather than incremented, so a redelivered job can never double count. The batch is closed by a conditional `UPDATE`, so exactly one worker announces completion.

## Concurrency and rate limiting

- **Workers:** Horizon `maxProcesses` (`QUEUE_MAX_PROCESSES`, default 10 in production, 5 locally).
- **DNSBL pressure:** a Redis funnel ([LimitDnsblConcurrency](app/Jobs/Middleware/LimitDnsblConcurrency.php)) caps concurrent blacklist checks across all servers (`DNSBL_CONCURRENCY`, default 20).
- **Per-list circuit breaker:** within one check, a blacklist that fails or refuses is not queried again.
- **API:** `throttle` on single (30/min) and bulk (10/min). Uploads are capped at 10,000 unique domains and 5 MB.

## Configuration

Everything is in [config/domain_checks.php](config/domain_checks.php) and can be set through `.env`: `DNS_NAMESERVERS`, `DNS_TIMEOUT`, `DNS_QUERY_RETRIES`, `DNS_CHECK_BUDGET`, `DNSBL_CONCURRENCY`, `BULK_MAX_ROWS`, `BULK_MAX_FILE_KB`, `QUEUE_MAX_PROCESSES`.

## Known limitations

- **Use your own resolver in production.** Spamhaus, URIBL and others refuse queries from large public resolvers (1.1.1.1, 8.8.8.8). The app reports those as `refused` and says the result is partial. Point `DNS_NAMESERVERS` at a local recursive resolver for full coverage.
- **Lookups within one check are sequential.** A blacklist check issues about 25 queries, so a single check takes seconds when resolver latency is high (about 8 s with 200 ms round trips). Bulk throughput scales with workers instead.
- **Channels are public.** The batch UUID is the only secret, which fits an MVP without accounts. Add authentication and private channels before exposing real data.
- **Provider detection is MX-first.** Behind a mail gateway it falls back to SPF/TXT hints and labels that as lower confidence. It does not probe `autodiscover` records.
- **Batches are queued during the upload request.** At the 10,000 row limit this takes a few seconds; moving it into a dispatcher job is the next step for larger files.
