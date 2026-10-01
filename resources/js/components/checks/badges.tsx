import { CheckCircle2, CircleDashed, Loader2, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { BlacklistStatus, CheckStatus } from '@/types/checks';

const tone = {
    green: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    red: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    amber: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    blue: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    gray: 'border-transparent bg-muted text-muted-foreground',
} as const;

export function StatusBadge({ status }: { status: CheckStatus }) {
    switch (status) {
        case 'queued':
            return (
                <Badge className={tone.gray}>
                    <CircleDashed /> Queued
                </Badge>
            );
        case 'checking':
            return (
                <Badge className={tone.blue}>
                    <Loader2 className="animate-spin" /> Checking
                </Badge>
            );
        case 'completed':
            return (
                <Badge className={tone.green}>
                    <CheckCircle2 /> Completed
                </Badge>
            );
        case 'failed':
            return (
                <Badge className={tone.red}>
                    <XCircle /> Failed
                </Badge>
            );
    }
}

export function BlacklistBadge({ status }: { status: BlacklistStatus | null }) {
    if (!status) return <span className="text-muted-foreground">—</span>;

    const map = {
        clean: [tone.green, 'Clean'],
        listed: [tone.red, 'Listed'],
        unknown: [tone.amber, 'Unknown'],
    } as const;
    const [cls, label] = map[status];

    return <Badge className={cls}>{label}</Badge>;
}

export function ProviderBadge({ provider }: { provider: string | null }) {
    if (!provider) return <span className="text-muted-foreground">—</span>;

    const cls =
        provider === 'Google Workspace'
            ? tone.blue
            : provider === 'Microsoft 365'
              ? tone.green
              : provider === 'Other'
                ? tone.amber
                : tone.gray;

    return <Badge className={cls}>{provider}</Badge>;
}

/** Small chip for one DNS record type: green = found, grey = absent, red = lookup failed. */
export function DnsChip({ label, status }: { label: string; status?: string }) {
    if (!status) return null;

    const cls =
        status === 'ok'
            ? tone.green
            : status === 'nodata' || status === 'nxdomain'
              ? tone.gray
              : tone.red;

    return (
        <span
            className={cn('rounded px-1.5 py-0.5 text-[11px] font-medium', cls)}
            title={`${label}: ${status}`}
        >
            {label}
        </span>
    );
}
