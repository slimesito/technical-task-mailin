<?php

namespace App\Support;

/** Wall-clock budget for one domain check, so a slow DNS path cannot run unbounded. */
final class CheckBudget
{
    private readonly float $deadline;

    public function __construct(int|float $seconds)
    {
        $this->deadline = microtime(true) + $seconds;
    }

    public function exhausted(): bool
    {
        return microtime(true) >= $this->deadline;
    }
}
