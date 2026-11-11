<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->firstName(),
            'purpose' => 'receptionist',
            'personality' => 'friendly',
            'language' => 'en',
            'instructions' => 'You are a helpful business receptionist. Answer questions using verified business information.',
            'status' => 'draft',
        ];
    }
}
