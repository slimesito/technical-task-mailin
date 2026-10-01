<?php

namespace App\Http\Resources;

use App\Models\DomainCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DomainCheck */
class DomainCheckResource extends JsonResource
{
    public static $wrap = null;

    private bool $compact = false;

    /** Omit the heavy per-record DNS detail (used for lists and broadcasts). */
    public function compact(): static
    {
        $this->compact = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type->value,
            'input' => $this->input,
            'domain' => $this->domain,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'blacklist_status' => $this->blacklist_status?->value,
            'listed_on' => $this->listed_on ?? [],
            'provider' => $this->provider?->value,
            'provider_status' => $this->provider_status,
            'evidence' => $this->evidence ?? [],
            'mx_records' => $this->mx_records ?? [],
            'errors' => $this->errors ?? [],
            // Status of every DNS record type, so lists can show chips without the full record values.
            'dns_summary' => (object) array_map(fn (array $r) => $r['status'], $this->dns_records ?? []),
            'duration_ms' => $this->duration_ms,
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];

        if (! $this->compact) {
            $data['dns_records'] = $this->dns_records ?? (object) [];
        }

        return $data;
    }
}
