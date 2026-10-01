<?php

namespace App\Models;

use App\Enums\BulkStatus;
use App\Enums\CheckStatus;
use App\Enums\CheckType;
use Carbon\CarbonImmutable;
use Database\Factories\BulkCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $uuid
 * @property CheckType $type
 * @property string|null $original_filename
 * @property string|null $dkim_selector
 * @property int $total
 * @property int $processed_count
 * @property int $failed_count
 * @property BulkStatus $status
 * @property CarbonImmutable|null $finished_at
 */
#[Fillable([
    'uuid', 'type', 'original_filename', 'dkim_selector',
    'total', 'processed_count', 'failed_count', 'status', 'finished_at',
])]
class BulkCheck extends Model
{
    /** @use HasFactory<BulkCheckFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CheckType::class,
            'status' => BulkStatus::class,
            'finished_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<DomainCheck, $this> */
    public function domainChecks(): HasMany
    {
        return $this->hasMany(DomainCheck::class);
    }

    /** Moves the batch from pending to processing the first time a worker picks up a domain. */
    public function markProcessing(): void
    {
        static::query()
            ->whereKey($this->id)
            ->where('status', BulkStatus::Pending)
            ->update(['status' => BulkStatus::Processing]);
    }

    /**
     * Recomputes the counters from the domain rows in one statement. Counting
     * (instead of incrementing) makes this idempotent: a retried job can never
     * double-count, and a lost update heals on the next call.
     */
    public function refreshProgress(): void
    {
        $count = fn (CheckStatus $status) => DB::raw(
            "(select count(*) from domain_checks where domain_checks.bulk_check_id = bulk_checks.id and domain_checks.status = '{$status->value}')"
        );

        static::query()->whereKey($this->id)->update([
            'processed_count' => $count(CheckStatus::Completed),
            'failed_count' => $count(CheckStatus::Failed),
        ]);

        $this->refresh();
    }

    /** Marks the batch completed once every domain is done. True only for the call that flipped it. */
    public function completeIfDone(): bool
    {
        return static::query()
            ->whereKey($this->id)
            ->where('status', '!=', BulkStatus::Completed)
            ->whereRaw('processed_count + failed_count >= total')
            ->update(['status' => BulkStatus::Completed, 'finished_at' => now()]) === 1;
    }

    /** @return array{total:int, processed:int, failed:int, status:string} */
    public function progress(): array
    {
        return [
            'total' => $this->total,
            'processed' => $this->processed_count,
            'failed' => $this->failed_count,
            'status' => $this->status->value,
        ];
    }
}
