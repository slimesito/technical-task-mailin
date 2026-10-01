import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { CheckType, Filters } from '@/types/checks';

const selectClass =
    'h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 outline-none';

const RESULT_OPTIONS: Record<
    CheckType,
    { label: string; options: [string, string][] }
> = {
    blacklist: {
        label: 'All results',
        options: [
            ['clean', 'Clean'],
            ['listed', 'Listed'],
            ['unknown', 'Unknown'],
        ],
    },
    provider: {
        label: 'All providers',
        options: [
            ['Google Workspace', 'Google Workspace'],
            ['Microsoft 365', 'Microsoft 365'],
            ['Other', 'Other'],
            ['Not Detected', 'Not Detected'],
        ],
    },
};

type Props = {
    type: CheckType;
    filters: Filters;
    onChange: (filters: Filters) => void;
    exportHref: string;
    shown: number;
    total: number;
};

export function ResultsFilters({
    type,
    filters,
    onChange,
    exportHref,
    shown,
    total,
}: Props) {
    const result = RESULT_OPTIONS[type];

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Input
                value={filters.search}
                onChange={(e) =>
                    onChange({ ...filters, search: e.target.value })
                }
                placeholder="Search domain…"
                className="h-9 w-56"
                aria-label="Search domain"
            />
            <select
                className={selectClass}
                value={filters.status}
                onChange={(e) =>
                    onChange({
                        ...filters,
                        status: e.target.value as Filters['status'],
                    })
                }
                aria-label="Filter by status"
            >
                <option value="">All statuses</option>
                <option value="queued">Queued</option>
                <option value="checking">Checking</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
            </select>
            <select
                className={selectClass}
                value={filters.result}
                onChange={(e) =>
                    onChange({ ...filters, result: e.target.value })
                }
                aria-label="Filter by result"
            >
                <option value="">{result.label}</option>
                {result.options.map(([value, label]) => (
                    <option key={value} value={value}>
                        {label}
                    </option>
                ))}
            </select>
            <span className="text-sm text-muted-foreground">
                {shown.toLocaleString()} of {total.toLocaleString()} shown
            </span>
            <Button asChild variant="outline" size="sm" className="ml-auto">
                <a href={exportHref} download>
                    <Download /> Export CSV
                </a>
            </Button>
        </div>
    );
}
