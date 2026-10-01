<?php

namespace App\Services\Provider;

use App\Enums\MailProvider;

final readonly class ProviderResult
{
    /** @param list<string> $evidence human-readable reasons behind the verdict */
    public function __construct(
        public MailProvider $provider,
        public bool $detected,
        public array $evidence,
    ) {}

    /** "detected" when a mail provider could be identified (including "Other"); otherwise "not_detected". */
    public function statusValue(): string
    {
        return $this->detected ? 'detected' : 'not_detected';
    }
}
