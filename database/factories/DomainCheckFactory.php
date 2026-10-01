<?php

namespace Database\Factories;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Models\BulkCheck;
use App\Models\DomainCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DomainCheck> */
class DomainCheckFactory extends Factory
{
    public function definition(): array
    {
        $domain = fake()->unique()->domainName();

        return [
            'bulk_check_id' => BulkCheck::factory(),
            'type' => CheckType::Blacklist,
            'input' => $domain,
            'domain' => $domain,
            'status' => CheckStatus::Queued,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => CheckStatus::Completed,
            'mx_records' => [['priority' => 10, 'host' => 'mx.example.net']],
            'dns_records' => ['MX' => ['status' => 'ok', 'values' => [['priority' => 10, 'host' => 'mx.example.net']], 'error' => null]],
            'errors' => [],
            'completed_at' => now(),
            'duration_ms' => 120,
        ]);
    }
}
