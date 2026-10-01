import { useMemo, useState } from 'react';
import { BulkProgress } from '@/components/checks/bulk-progress';
import { CheckInputForm } from '@/components/checks/check-input-form';
import { ResultsFilters } from '@/components/checks/results-filters';
import { ResultsTable } from '@/components/checks/results-table';
import { SingleResult } from '@/components/checks/single-result';
import { useBulkCheck } from '@/hooks/use-bulk-check';
import { ApiError, checkSingle, exportUrl, uploadBulk } from '@/lib/checks-api';
import type {
    BulkUploadResponse,
    CheckType,
    DomainCheck,
    Filters,
} from '@/types/checks';

const BATCH_PARAM = 'batch';

type Props = { type: CheckType; title: string; description: string };

export function CheckTool({ type, title, description }: Props) {
    const [single, setSingle] = useState<DomainCheck | null>(null);
    const [upload, setUpload] = useState<BulkUploadResponse | null>(null);
    // The batch id lives in the URL so a page reload resumes the live view.
    const [batch, setBatch] = useState<string | null>(() =>
        new URLSearchParams(window.location.search).get(BATCH_PARAM),
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function pushBatch(uuid: string | null) {
        const url = new URL(window.location.href);
        if (uuid) url.searchParams.set(BATCH_PARAM, uuid);
        else url.searchParams.delete(BATCH_PARAM);
        window.history.replaceState(null, '', url);
        setBatch(uuid);
    }

    async function run<T>(
        action: () => Promise<T>,
        onDone: (result: T) => void,
    ) {
        setBusy(true);
        setError(null);
        try {
            onDone(await action());
        } catch (e) {
            const fields =
                e instanceof ApiError ? Object.values(e.fields).flat() : [];
            setError(
                fields[0] ??
                    (e instanceof Error ? e.message : 'Something went wrong'),
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-8">
            <header className="space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {title}
                </h1>
                <p className="text-muted-foreground">{description}</p>
            </header>

            <CheckInputForm
                type={type}
                busy={busy}
                error={error}
                onSingle={(input, selector) =>
                    run(
                        () => checkSingle(type, input, selector),
                        (result) => {
                            pushBatch(null);
                            setUpload(null);
                            setSingle(result);
                        },
                    )
                }
                onBulk={(file, selector) =>
                    run(
                        () => uploadBulk(type, file, selector),
                        (result) => {
                            setSingle(null);
                            setUpload(result);
                            pushBatch(result.uuid);
                        },
                    )
                }
            />

            {single && <SingleResult check={single} />}

            {upload && upload.invalid_count + upload.duplicates > 0 && (
                <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    {upload.invalid_count > 0 && (
                        <>{upload.invalid_count} invalid line(s) skipped. </>
                    )}
                    {upload.duplicates > 0 && (
                        <>{upload.duplicates} duplicate(s) removed. </>
                    )}
                    {upload.truncated && (
                        <>File was truncated to the row limit.</>
                    )}
                </p>
            )}

            {batch && <BulkResults key={batch} uuid={batch} type={type} />}
        </div>
    );
}

function BulkResults({ uuid, type }: { uuid: string; type: CheckType }) {
    const { checks, progress, loadError, connection } = useBulkCheck(uuid);
    const [filters, setFilters] = useState<Filters>({
        search: '',
        status: '',
        result: '',
    });

    const visible = useMemo(() => {
        const search = filters.search.trim().toLowerCase();

        return checks.filter((c) => {
            if (search && !c.domain.includes(search)) return false;
            if (filters.status && c.status !== filters.status) return false;
            if (filters.result) {
                const value =
                    type === 'blacklist' ? c.blacklist_status : c.provider;
                if (value !== filters.result) return false;
            }

            return true;
        });
    }, [checks, filters, type]);

    return (
        <section className="space-y-4">
            <BulkProgress progress={progress} />

            {connection !== 'connected' && progress?.status !== 'completed' && (
                <p className="text-xs text-muted-foreground">
                    Live connection unavailable ({connection}). Refreshing every
                    few seconds instead.
                </p>
            )}
            {loadError && (
                <p className="text-sm text-destructive">{loadError}</p>
            )}

            <ResultsFilters
                type={type}
                filters={filters}
                onChange={setFilters}
                exportHref={exportUrl(uuid, type, filters)}
                shown={visible.length}
                total={checks.length}
            />
            <ResultsTable type={type} uuid={uuid} checks={visible} />
        </section>
    );
}
