import type { Progress } from '@/types/checks';

export function BulkProgress({ progress }: { progress: Progress | null }) {
    if (!progress) {
        return <div className="h-14 animate-pulse rounded-xl border bg-card" />;
    }

    const done = progress.processed + progress.failed;
    const pct =
        progress.total === 0 ? 0 : Math.round((done / progress.total) * 100);
    const fmt = (n: number) => n.toLocaleString();

    return (
        <div className="space-y-2 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                <span className="font-medium">
                    {fmt(done)} / {fmt(progress.total)} checked
                </span>
                <span className="text-muted-foreground">
                    {progress.status === 'completed'
                        ? 'Finished'
                        : 'Processing…'}
                    {progress.failed > 0 && (
                        <span className="ml-2 text-destructive">
                            {fmt(progress.failed)} failed
                        </span>
                    )}
                </span>
            </div>
            <div
                role="progressbar"
                aria-valuenow={pct}
                aria-valuemin={0}
                aria-valuemax={100}
                className="h-2 overflow-hidden rounded-full bg-muted"
            >
                <div
                    className="h-full bg-primary transition-[width] duration-300"
                    style={{ width: `${pct}%` }}
                />
            </div>
        </div>
    );
}
