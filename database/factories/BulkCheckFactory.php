<?php

namespace Database\Factories;

use App\Enums\BulkStatus;
use App\Enums\CheckType;
use App\Models\BulkCheck;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BulkCheck> */
class BulkCheckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'type' => CheckType::Blacklist,
            'original_filename' => 'domains.csv',
            'total' => 1,
            'status' => BulkStatus::Pending,
        ];
    }
}
