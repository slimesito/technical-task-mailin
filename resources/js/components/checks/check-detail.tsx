import { AlertTriangle } from 'lucide-react';
import type { DomainCheck } from '@/types/checks';

const RECORD_ORDER = [
    'MX',
    'SPF',
    'DMARC',
    'DKIM',
    'NS',
    'A',
    'AAAA',
    'CNAME',
    'PTR',
    'TXT',
];

function formatValue(value: unknown): string {
    if (typeof value === 'string') return value;
    if (value && typeof value === 'object') {
        const v = value as { priority?: number; ip?: string; host?: string };
        if (v.priority !== undefined && v.host)
            return `${v.priority} ${v.host}`;
        if (v.ip && v.host) return `${v.ip} → ${v.host}`;
    }

    return JSON.stringify(value);
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <h4 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h4>
            {children}
        </div>
    );
}

/** Full breakdown of one check: listings, evidence, DNS record values and errors. */
export function CheckDetail({ check }: { check: DomainCheck }) {
    const records = check.dns_records;

    return (
        <div className="grid gap-5 text-sm md:grid-cols-2">
            {check.type === 'blacklist' && (
                <Section title="Blacklists">
                    {check.listed_on.length > 0 ? (
                        <ul className="space-y-1">
                            {check.listed_on.map((l) => (
                                <li
                                    key={`${l.list}-${l.target}`}
                                    className="text-red-700 dark:text-red-400"
                                >
                                    <strong>{l.name}</strong> listed{' '}
                                    <code>{l.target}</code> (code {l.code})
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground">
                            {check.blacklist_status === 'unknown'
                                ? 'No blacklist could be queried, so the result is inconclusive.'
                                : 'Not listed on any queried blacklist.'}
                        </p>
                    )}
                </Section>
            )}

            {check.type === 'provider' && (
                <>
                    <Section title="Detection evidence">
                        <ul className="list-disc space-y-1 pl-5">
                            {check.evidence.map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ul>
                    </Section>
                    <Section title="MX records">
                        {check.mx_records.length > 0 ? (
                            <ul className="space-y-0.5 font-mono text-xs">
                                {check.mx_records.map((mx) => (
                                    <li key={`${mx.priority}-${mx.host}`}>
                                        {mx.priority} {mx.host}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-muted-foreground">None</p>
                        )}
                    </Section>
                </>
            )}

            {records && (
                <Section title="DNS records">
                    <dl className="space-y-1.5">
                        {RECORD_ORDER.filter((type) => records[type]).map(
                            (type) => {
                                const rec = records[type];

                                return (
                                    <div
                                        key={type}
                                        className="grid grid-cols-[4.5rem_1fr] gap-2"
                                    >
                                        <dt className="font-medium">{type}</dt>
                                        <dd className="min-w-0 font-mono text-xs break-words">
                                            {rec.values.length > 0 ? (
                                                rec.values.map((v, i) => (
                                                    <div key={i}>
                                                        {formatValue(v)}
                                                    </div>
                                                ))
                                            ) : (
                                                <span className="font-sans text-muted-foreground">
                                                    {rec.status === 'ok'
                                                        ? ''
                                                        : rec.status}
                                                    {rec.error
                                                        ? ` — ${rec.error}`
                                                        : ''}
                                                </span>
                                            )}
                                        </dd>
                                    </div>
                                );
                            },
                        )}
                    </dl>
                </Section>
            )}

            {check.errors.length > 0 && (
                <Section title="Errors and timeouts">
                    <ul className="space-y-1">
                        {check.errors.map((e, i) => (
                            <li
                                key={i}
                                className="flex gap-1.5 text-amber-700 dark:text-amber-400"
                            >
                                <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                                <span>
                                    <strong>{e.source}</strong> ({e.type}):{' '}
                                    {e.message}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
        </div>
    );
}
