<?php

namespace App\Models;

use App\Enums\BlacklistStatus;
use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\MailProvider;
use Carbon\CarbonImmutable;
use Database\Factories\DomainCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $bulk_check_id
 * @property CheckType $type
 * @property string $input
 * @property string $domain
 * @property string|null $dkim_selector
 * @property CheckStatus $status
 * @property int $attempts
 * @property BlacklistStatus|null $blacklist_status
 * @property list<array{list:string, name:string, target:string, code:string}>|null $listed_on
 * @property MailProvider|null $provider
 * @property string|null $provider_status
 * @property list<string>|null $evidence
 * @property list<array{priority:int, host:string}>|null $mx_records
 * @property array<string, array{status:string, values:list<mixed>, error:string|null}>|null $dns_records
 * @property list<array{source:string, type:string, message:string}>|null $errors
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $duration_ms
 * @property-read BulkCheck|null $bulkCheck
 */
#[Guarded([])]
class DomainCheck extends Model
{
    /** @use HasFactory<DomainCheckFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CheckType::class,
            'status' => CheckStatus::class,
            'blacklist_status' => BlacklistStatus::class,
            'provider' => MailProvider::class,
            'listed_on' => 'array',
            'evidence' => 'array',
            'mx_records' => 'array',
            'dns_records' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Filters used by both the results API and the CSV export.
     *
     * @param  Builder<DomainCheck>  $query
     * @param  array{status?:?string, blacklist?:?string, provider?:?string, search?:?string}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['blacklist'] ?? null, fn ($q, $v) => $q->where('blacklist_status', $v))
            ->when($filters['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('domain', 'ilike', '%'.addcslashes($v, '%_\\').'%'));
    }

    /** @return BelongsTo<BulkCheck, $this> */
    public function bulkCheck(): BelongsTo
    {
        return $this->belongsTo(BulkCheck::class);
    }
}
