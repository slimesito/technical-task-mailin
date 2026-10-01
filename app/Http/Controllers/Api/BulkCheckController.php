<?php

namespace App\Http\Controllers\Api;

use App\Enums\BulkStatus;
use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkUploadRequest;
use App\Http\Resources\DomainCheckResource;
use App\Jobs\ProcessDomainCheck;
use App\Models\BulkCheck;
use App\Models\DomainCheck;
use App\Support\BulkUploadParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BulkCheckController extends Controller
{
    private const INSERT_CHUNK = 500;

    private const MAX_INVALID_REPORTED = 100;

    public function store(CheckType $type, BulkUploadRequest $request, BulkUploadParser $parser): JsonResponse
    {
        $parsed = $parser->parse($request->file('file')->getRealPath(), config('domain_checks.bulk.max_rows'));

        if ($parsed['valid'] === []) {
            throw ValidationException::withMessages(['file' => 'The file does not contain any valid domain or email.']);
        }

        $dkim = $type === CheckType::Blacklist ? $request->validated('dkim_selector') : null;

        $bulk = DB::transaction(function () use ($type, $request, $parsed, $dkim) {
            $bulk = BulkCheck::create([
                'uuid' => (string) Str::uuid(),
                'type' => $type,
                'original_filename' => $request->file('file')->getClientOriginalName(),
                'dkim_selector' => $dkim,
                'total' => count($parsed['valid']),
                'status' => BulkStatus::Pending,
            ]);

            $now = now();
            foreach (array_chunk($parsed['valid'], self::INSERT_CHUNK) as $chunk) {
                DomainCheck::insert(array_map(fn (array $row) => [
                    'bulk_check_id' => $bulk->id,
                    'type' => $type->value,
                    'input' => $row['input'],
                    'domain' => $row['domain'],
                    'dkim_selector' => $dkim,
                    'status' => CheckStatus::Queued->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            return $bulk;
        });

        // Push to Redis only after the rows are committed, in pipelined chunks.
        // Queue::bulk ignores the job's own onQueue(), so the queue is passed explicitly.
        $queue = config('domain_checks.bulk.queue');
        $bulk->domainChecks()->select('id', 'type')->chunkById(self::INSERT_CHUNK, function ($checks) use ($queue) {
            Queue::bulk($checks->map(fn (DomainCheck $check) => new ProcessDomainCheck($check))->all(), '', $queue);
        });

        return response()->json([
            'uuid' => $bulk->uuid,
            'total' => $bulk->total,
            'duplicates' => $parsed['duplicates'],
            'truncated' => $parsed['truncated'],
            'invalid_count' => count($parsed['invalid']),
            'invalid' => array_slice($parsed['invalid'], 0, self::MAX_INVALID_REPORTED),
        ], 201);
    }

    /** Current state of a batch: used on page load and after a WebSocket reconnect. */
    public function show(BulkCheck $bulkCheck): JsonResponse
    {
        $checks = $bulkCheck->domainChecks()
            ->orderBy('id')
            ->get()
            ->map(fn (DomainCheck $check) => DomainCheckResource::make($check)->compact()->resolve());

        return response()->json([
            'bulk' => [
                'uuid' => $bulkCheck->uuid,
                'type' => $bulkCheck->type->value,
                'filename' => $bulkCheck->original_filename,
                'progress' => $bulkCheck->progress(),
            ],
            'checks' => $checks,
        ]);
    }

    /** Full record (including raw DNS data) for one row, loaded when a table row is expanded. */
    public function check(BulkCheck $bulkCheck, int $check): DomainCheckResource
    {
        return DomainCheckResource::make($bulkCheck->domainChecks()->findOrFail($check));
    }
}
