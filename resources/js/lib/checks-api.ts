import type {
    BulkUploadResponse,
    CheckType,
    DomainCheck,
    Filters,
    Progress,
} from '@/types/checks';

/** Laravel validation errors (422), keyed by field. */
export class ApiError extends Error {
    constructor(
        message: string,
        public fields: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

async function request<T>(url: string, init?: RequestInit): Promise<T> {
    const headers = new Headers(init?.headers);
    headers.set('Accept', 'application/json');

    const response = await fetch(url, { ...init, headers });

    if (response.ok) {
        return (await response.json()) as T;
    }

    const body = (await response.json().catch(() => ({}))) as {
        message?: string;
        errors?: Record<string, string[]>;
    };

    if (response.status === 429) {
        throw new ApiError(
            'Too many requests. Please wait a moment and try again.',
        );
    }

    throw new ApiError(
        body.message ?? `Request failed (${response.status})`,
        body.errors,
    );
}

export function checkSingle(
    type: CheckType,
    input: string,
    dkimSelector: string,
): Promise<DomainCheck> {
    return request(`/api/checks/${type}/single`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ input, dkim_selector: dkimSelector || null }),
    });
}

export function uploadBulk(
    type: CheckType,
    file: File,
    dkimSelector: string,
): Promise<BulkUploadResponse> {
    const form = new FormData();
    form.append('file', file);
    if (dkimSelector) {
        form.append('dkim_selector', dkimSelector);
    }

    return request(`/api/checks/${type}/bulk`, { method: 'POST', body: form });
}

export function fetchBulk(uuid: string): Promise<{
    bulk: {
        uuid: string;
        type: CheckType;
        filename: string | null;
        progress: Progress;
    };
    checks: DomainCheck[];
}> {
    return request(`/api/bulk-checks/${uuid}`);
}

export function fetchCheckDetail(
    uuid: string,
    id: number,
): Promise<DomainCheck> {
    return request(`/api/bulk-checks/${uuid}/checks/${id}`);
}

/** Same filters as the table, so the CSV matches what the user is looking at. */
export function exportUrl(
    uuid: string,
    type: CheckType,
    filters: Filters,
): string {
    const params = new URLSearchParams();
    if (filters.search) params.set('search', filters.search);
    if (filters.status) params.set('status', filters.status);
    if (filters.result)
        params.set(
            type === 'blacklist' ? 'blacklist' : 'provider',
            filters.result,
        );

    const query = params.toString();

    return `/api/bulk-checks/${uuid}/export${query ? `?${query}` : ''}`;
}
