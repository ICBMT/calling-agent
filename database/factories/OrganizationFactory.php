<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'slug' => Str::uuid()->toString(), 'timezone' => 'UTC', 'default_language' => 'en'];
    }
}
