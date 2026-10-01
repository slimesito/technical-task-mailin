import { useVirtualizer } from '@tanstack/react-virtual';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    BlacklistBadge,
    DnsChip,
    ProviderBadge,
    StatusBadge,
} from '@/components/checks/badges';
import { CheckDetail } from '@/components/checks/check-detail';
import { fetchCheckDetail } from '@/lib/checks-api';
import type { CheckType, DomainCheck } from '@/types/checks';

const DNS_CHIPS = ['MX', 'SPF', 'DMARC', 'DKIM', 'NS'];

const GRID: Record<CheckType, string> = {
    blacklist:
        'grid-cols-[1.5rem_minmax(10rem,2fr)_8rem_6rem_minmax(8rem,1.5fr)_minmax(10rem,2fr)]',
    provider:
        'grid-cols-[1.5rem_minmax(10rem,2fr)_8rem_9rem_minmax(10rem,2fr)_minmax(10rem,2fr)]',
};

const HEADERS: Record<CheckType, string[]> = {
    blacklist: [
        '',
        'Domain',
        'Status',
        'Blacklist',
        'Listed on',
        'DNS records',
    ],
    provider: ['', 'Domain', 'Status', 'Provider', 'MX records', 'Evidence'],
};

type Props = { type: CheckType; uuid: string; checks: DomainCheck[] };

/** Virtualised so a 10,000-row batch only renders the rows on screen. */
export function ResultsTable({ type, uuid, checks }: Props) {
    const scroller = useRef<HTMLDivElement>(null);
    const [expanded, setExpanded] = useState<Set<number>>(new Set());

    const virtualizer = useVirtualizer({
        count: checks.length,
        getScrollElement: () => scroller.current,
        estimateSize: () => 48,
        overscan: 8,
    });

    function toggle(id: number) {
        setExpanded((prev) => {
            const next = new Set(prev);
            if (!next.delete(id)) next.add(id);

            return next;
        });
    }

    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            <div
                className={`grid ${GRID[type]} gap-3 border-b bg-muted/50 px-3 py-2 text-xs font-semibold text-muted-foreground uppercase`}
            >
                {HEADERS[type].map((h, i) => (
                    <div key={i}>{h}</div>
                ))}
            </div>

            <div ref={scroller} className="max-h-[32rem] overflow-auto">
                {checks.length === 0 ? (
                    <p className="p-8 text-center text-sm text-muted-foreground">
                        No results match the current filters.
                    </p>
                ) : (
                    <div
                        style={{
                            height: virtualizer.getTotalSize(),
                            position: 'relative',
                        }}
                    >
                        {virtualizer.getVirtualItems().map((item) => {
                            const check = checks[item.index];
                            const open = expanded.has(check.id);

                            return (
                                <div
                                    key={check.id}
                                    ref={virtualizer.measureElement}
                                    data-index={item.index}
                                    className="absolute top-0 left-0 w-full border-b last:border-b-0"
                                    style={{
                                        transform: `translateY(${item.start}px)`,
                                    }}
                                >
                                    <div
                                        className={`grid ${GRID[type]} cursor-pointer items-center gap-3 px-3 py-2.5 text-sm hover:bg-muted/40`}
                                        onClick={() => toggle(check.id)}
                                    >
                                        <button
                                            type="button"
                                            aria-label={
                                                open
                                                    ? 'Collapse row'
                                                    : 'Expand row'
                                            }
                                            aria-expanded={open}
                                            className="text-muted-foreground"
                                        >
                                            {open ? (
                                                <ChevronDown className="size-4" />
                                            ) : (
                                                <ChevronRight className="size-4" />
                                            )}
                                        </button>
                                        <div
                                            className="truncate font-medium"
                                            title={check.domain}
                                        >
                                            {check.domain}
                                        </div>
                                        <div>
                                            <StatusBadge
                                                status={check.status}
                                            />
                                        </div>
                                        {type === 'blacklist' ? (
                                            <BlacklistCells check={check} />
                                        ) : (
                                            <ProviderCells check={check} />
                                        )}
                                    </div>
                                    {open && (
                                        <ExpandedRow
                                            type={type}
                                            uuid={uuid}
                                            check={check}
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}

function BlacklistCells({ check }: { check: DomainCheck }) {
    return (
        <>
            <div>
                <BlacklistBadge status={check.blacklist_status} />
            </div>
            <div
                className="truncate text-muted-foreground"
                title={check.listed_on.map((l) => l.name).join(', ')}
            >
                {check.listed_on.length > 0
                    ? [...new Set(check.listed_on.map((l) => l.name))].join(
                          ', ',
                      )
                    : '—'}
            </div>
            <div className="flex flex-wrap gap-1">
                {DNS_CHIPS.map((t) => (
                    <DnsChip key={t} label={t} status={check.dns_summary[t]} />
                ))}
            </div>
        </>
    );
}

function ProviderCells({ check }: { check: DomainCheck }) {
    const mx = check.mx_records.map((m) => m.host).join(', ');

    return (
        <>
            <div>
                <ProviderBadge provider={check.provider} />
            </div>
            <div
                className="truncate font-mono text-xs text-muted-foreground"
                title={mx}
            >
                {mx || '—'}
            </div>
            <div
                className="truncate text-muted-foreground"
                title={check.evidence.join(' | ')}
            >
                {check.evidence[0] ?? '—'}
            </div>
        </>
    );
}

/** Blacklist rows load their raw DNS values on demand; the list payload only carries a summary. */
function ExpandedRow({
    type,
    uuid,
    check,
}: {
    type: CheckType;
    uuid: string;
    check: DomainCheck;
}) {
    const [full, setFull] = useState<DomainCheck | null>(null);
    const [error, setError] = useState<string | null>(null);
    const finished = check.status === 'completed' || check.status === 'failed';

    useEffect(() => {
        if (type !== 'blacklist' || !finished) return;
        let cancelled = false;
        fetchCheckDetail(uuid, check.id)
            .then((data) => !cancelled && setFull(data))
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
    }, [type, uuid, check.id, finished]);

    return (
        <div className="border-t bg-muted/30 px-4 py-4">
            {!finished ? (
                <p className="text-sm text-muted-foreground">
                    Waiting for this domain to finish…
                </p>
            ) : error ? (
                <p className="text-sm text-destructive">{error}</p>
            ) : type === 'blacklist' && !full ? (
                <p className="text-sm text-muted-foreground">
                    Loading details…
                </p>
            ) : (
                <CheckDetail check={full ?? check} />
            )}
        </div>
    );
}
