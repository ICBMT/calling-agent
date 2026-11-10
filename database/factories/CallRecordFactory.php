<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CallRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => Str::uuid()->toString(),
            'organization_id' => Organization::factory(),
            'direction' => 'incoming',
            'status' => 'completed',
            'started_at' => now()->subMinutes(2),
            'ended_at' => now(),
            'duration_seconds' => 120,
        ];
    }
}
