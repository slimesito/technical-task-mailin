<?php

namespace App\Events;

use App\Models\BulkCheck;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BulkCheckFinished implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly BulkCheck $bulk) {}

    public function broadcastOn(): Channel
    {
        return new Channel("bulk-checks.{$this->bulk->uuid}");
    }

    public function broadcastAs(): string
    {
        return 'bulk-check.finished';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['progress' => $this->bulk->progress()];
    }
}
