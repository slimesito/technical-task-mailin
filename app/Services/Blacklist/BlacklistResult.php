<?php

namespace App\Services\Blacklist;

use App\Enums\BlacklistStatus;

final readonly class BlacklistResult
{
    /**
     * @param  list<array{list:string, name:string, target:string, code:string}>  $listings
     * @param  list<array{source:string, type:string, message:string}>  $errors
     */
    public function __construct(
        public BlacklistStatus $status,
        public array $listings = [],
        public array $errors = [],
        public int $queries = 0,
    ) {}
}
