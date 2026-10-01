import { useConnectionStatus, useEchoPublic } from '@laravel/echo-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { fetchBulk } from '@/lib/checks-api';
import type { DomainCheck, Progress } from '@/types/checks';

type UpdatedEvent = { check: DomainCheck; progress: Progress };
type FinishedEvent = { progress: Progress };

const FLUSH_MS = 150;
const POLL_MS = 5000;
const RANK = { queued: 0, checking: 1, completed: 2, failed: 2 } as const;

/**
 * Live state of one bulk batch.
 *
 * - Subscribes to the batch's public channel and applies each domain update as it arrives.
 * - Loads the current state over HTTP too (page load, WebSocket reconnect), without ever
 *   overwriting a row with an older state than the one a WebSocket event already delivered.
 * - Events are buffered and flushed every 150ms, so a burst of results costs one re-render.
 * - If the socket is down, falls back to polling until it recovers.
 */
export function useBulkCheck(uuid: string) {
    const rows = useRef(new Map<number, DomainCheck>());
    const [version, setVersion] = useState(0);
    const [progress, setProgress] = useState<Progress | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const flushTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    // Progress is buffered together with the rows so the counter never runs ahead of the table.
    const pendingProgress = useRef<Progress | null>(null);

    const scheduleFlush = useCallback(() => {
        if (flushTimer.current !== null) return;
        flushTimer.current = setTimeout(() => {
            flushTimer.current = null;
            const next = pendingProgress.current;
            pendingProgress.current = null;
            if (next) setProgress((prev) => newest(prev, next));
            setVersion((v) => v + 1);
        }, FLUSH_MS);
    }, []);

    const queueProgress = useCallback((next: Progress) => {
        pendingProgress.current = newest(pendingProgress.current, next);
    }, []);

    const load = useCallback(async () => {
        try {
            const data = await fetchBulk(uuid);
            for (const check of data.checks) {
                const current = rows.current.get(check.id);
                if (!current || RANK[check.status] >= RANK[current.status]) {
                    rows.current.set(check.id, check);
                }
            }
            queueProgress(data.bulk.progress);
            setLoadError(null);
            scheduleFlush();
        } catch (e) {
            setLoadError(
                e instanceof Error ? e.message : 'Could not load results',
            );
        }
    }, [uuid, scheduleFlush, queueProgress]);

    useEffect(() => {
        rows.current = new Map();
        pendingProgress.current = null;
        setProgress(null);
        setVersion((v) => v + 1);
        void load();

        return () => {
            if (flushTimer.current !== null) clearTimeout(flushTimer.current);
            flushTimer.current = null;
        };
    }, [uuid, load]);

    useEchoPublic<UpdatedEvent | FinishedEvent>(
        `bulk-checks.${uuid}`,
        ['.domain-check.updated', '.bulk-check.finished'],
        (event) => {
            if ('check' in event) {
                rows.current.set(event.check.id, event.check);
            }
            queueProgress(event.progress);
            scheduleFlush();
        },
        [uuid, queueProgress, scheduleFlush],
    );

    // Recover from socket drops: refetch when it reconnects, poll while it is down.
    const connection = useConnectionStatus();
    const wasConnected = useRef(false);
    useEffect(() => {
        if (connection === 'connected') {
            if (wasConnected.current) void load();
            wasConnected.current = true;
        }
    }, [connection, load]);

    const done = progress?.status === 'completed';
    useEffect(() => {
        if (done || connection === 'connected') return;
        const timer = setInterval(() => void load(), POLL_MS);

        return () => clearInterval(timer);
    }, [done, connection, load]);

    // `version` is the change signal for the mutable Map.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    const checks = useMemo(() => Array.from(rows.current.values()), [version]);

    return { checks, progress, loadError, connection };
}

/** Counters only move forward; ignore a stale snapshot that arrives after a newer one. */
function newest(prev: Progress | null, next: Progress): Progress {
    if (!prev) return next;
    if (prev.status === 'completed' && next.status !== 'completed') return prev;

    return prev.processed + prev.failed > next.processed + next.failed
        ? prev
        : next;
}
