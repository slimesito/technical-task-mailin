import {
    BlacklistBadge,
    DnsChip,
    ProviderBadge,
    StatusBadge,
} from '@/components/checks/badges';
import { CheckDetail } from '@/components/checks/check-detail';
import type { DomainCheck } from '@/types/checks';

export function SingleResult({ check }: { check: DomainCheck }) {
    return (
        <div className="space-y-4 rounded-xl border bg-card p-5">
            <div className="flex flex-wrap items-center gap-3">
                <h2 className="text-lg font-semibold break-all">
                    {check.domain}
                </h2>
                <StatusBadge status={check.status} />
                {check.type === 'blacklist' ? (
                    <BlacklistBadge status={check.blacklist_status} />
                ) : (
                    <>
                        <ProviderBadge provider={check.provider} />
                        <span className="text-sm text-muted-foreground">
                            {check.provider_status === 'detected'
                                ? 'Active / Detected'
                                : 'Not Detected'}
                        </span>
                    </>
                )}
                {check.duration_ms !== null && (
                    <span className="ml-auto text-xs text-muted-foreground">
                        {(check.duration_ms / 1000).toFixed(1)}s
                    </span>
                )}
            </div>

            {check.type === 'blacklist' && (
                <div className="flex flex-wrap gap-1.5">
                    {[
                        'MX',
                        'SPF',
                        'DMARC',
                        'DKIM',
                        'NS',
                        'A',
                        'AAAA',
                        'CNAME',
                        'PTR',
                    ].map((t) => (
                        <DnsChip
                            key={t}
                            label={t}
                            status={check.dns_summary[t]}
                        />
                    ))}
                </div>
            )}

            <CheckDetail check={check} />
        </div>
    );
}
