<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckType;
use App\Http\Controllers\Controller;
use App\Models\BulkCheck;
use App\Models\DomainCheck;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __invoke(Request $request, BulkCheck $bulkCheck): StreamedResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:queued,checking,completed,failed'],
            'blacklist' => ['nullable', 'in:clean,listed,unknown'],
            'provider' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $isBlacklist = $bulkCheck->type === CheckType::Blacklist;
        $filename = "{$bulkCheck->type->value}-check-{$bulkCheck->uuid}.csv";

        return response()->streamDownload(function () use ($bulkCheck, $filters, $isBlacklist) {
            $out = fopen('php://output', 'w') ?: throw new \RuntimeException('Cannot open output stream');
            fputcsv($out, $isBlacklist ? $this->blacklistHeader() : $this->providerHeader());

            $bulkCheck->domainChecks()->filter($filters)->lazyById(500)->each(
                fn (DomainCheck $c) => fputcsv($out, array_map(
                    $this->safe(...),
                    $isBlacklist ? $this->blacklistRow($c) : $this->providerRow($c),
                )),
            );

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return list<string> */
    private function blacklistHeader(): array
    {
        return ['domain', 'status', 'blacklist_status', 'listed_on', 'mx', 'spf', 'dmarc', 'dkim', 'nameservers', 'a', 'aaaa', 'cname', 'ptr', 'errors'];
    }

    /** @return list<string> */
    private function providerHeader(): array
    {
        return ['domain', 'status', 'provider', 'provider_status', 'mx', 'evidence', 'errors'];
    }

    /** @return list<string> */
    private function blacklistRow(DomainCheck $c): array
    {
        $dns = $c->dns_records ?? [];

        return [
            $c->domain,
            $c->status->value,
            $c->blacklist_status->value ?? '',
            implode('; ', array_map(fn ($l) => "{$l['name']} ({$l['target']})", $c->listed_on ?? [])),
            $this->mx($c),
            $this->values($dns, 'SPF'),
            $this->values($dns, 'DMARC'),
            $this->values($dns, 'DKIM'),
            $this->values($dns, 'NS'),
            $this->values($dns, 'A'),
            $this->values($dns, 'AAAA'),
            $this->values($dns, 'CNAME'),
            implode('; ', array_map(fn ($p) => "{$p['ip']}={$p['host']}", $dns['PTR']['values'] ?? [])),
            $this->errors($c),
        ];
    }

    /** @return list<string> */
    private function providerRow(DomainCheck $c): array
    {
        return [
            $c->domain,
            $c->status->value,
            $c->provider->value ?? '',
            $c->provider_status ?? '',
            $this->mx($c),
            implode(' | ', $c->evidence ?? []),
            $this->errors($c),
        ];
    }

    private function mx(DomainCheck $c): string
    {
        return implode('; ', array_map(fn ($mx) => "{$mx['priority']} {$mx['host']}", $c->mx_records ?? []));
    }

    /** @param array<string, array{values:list<string>}> $dns */
    private function values(array $dns, string $type): string
    {
        return implode('; ', $dns[$type]['values'] ?? []);
    }

    private function errors(DomainCheck $c): string
    {
        return implode('; ', array_map(fn ($e) => "{$e['source']}: {$e['type']}", $c->errors ?? []));
    }

    /** Neutralise spreadsheet formula injection from DNS-controlled text. */
    private function safe(string $value): string
    {
        return $value !== '' && str_contains('=+-@', $value[0]) ? "'{$value}" : $value;
    }
}
