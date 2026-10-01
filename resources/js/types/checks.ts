export type CheckType = 'blacklist' | 'provider';
export type CheckStatus = 'queued' | 'checking' | 'completed' | 'failed';
export type BlacklistStatus = 'clean' | 'listed' | 'unknown';
export type BulkStatus = 'pending' | 'processing' | 'completed';

export type DnsRecordResult = {
    status: string;
    values: unknown[];
    error: string | null;
};

export type Listing = {
    list: string;
    name: string;
    target: string;
    code: string;
};
export type MxRecord = { priority: number; host: string };
export type CheckError = { source: string; type: string; message: string };

export type DomainCheck = {
    id: number;
    type: CheckType;
    input: string;
    domain: string;
    status: CheckStatus;
    attempts: number;
    blacklist_status: BlacklistStatus | null;
    listed_on: Listing[];
    provider: string | null;
    provider_status: 'detected' | 'not_detected' | null;
    evidence: string[];
    mx_records: MxRecord[];
    errors: CheckError[];
    dns_summary: Record<string, string>;
    duration_ms: number | null;
    completed_at: string | null;
    /** Only present on full (non-compact) responses. */
    dns_records?: Record<string, DnsRecordResult>;
};

export type Progress = {
    total: number;
    processed: number;
    failed: number;
    status: BulkStatus;
};

export type BulkUploadResponse = {
    uuid: string;
    total: number;
    duplicates: number;
    truncated: boolean;
    invalid_count: number;
    invalid: { input: string; reason: string }[];
};

export type Filters = {
    search: string;
    status: '' | CheckStatus;
    /** Blacklist status for blacklist checks, provider name for provider checks. */
    result: string;
};
