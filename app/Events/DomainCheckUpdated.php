<?php

namespace App\Events;

use App\Http\Resources\DomainCheckResource;
use App\Models\BulkCheck;
use App\Models\DomainCheck;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast every time a domain changes state (checking, completed, failed).
 * Sent synchronously (ShouldBroadcastNow) from inside the worker so the UI
 * is updated the moment the domain finishes, without a second queue hop.
 */
class DomainCheckUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DomainCheck $check,
        public readonly BulkCheck $bulk,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("bulk-checks.{$this->bulk->uuid}");
    }

    public function broadcastAs(): string
    {
        return 'domain-check.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'check' => DomainCheckResource::make($this->check)->compact()->resolve(),
            'progress' => $this->bulk->progress(),
        ];
    }
}
