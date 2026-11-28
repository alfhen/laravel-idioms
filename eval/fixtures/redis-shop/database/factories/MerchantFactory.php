<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class MerchantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'balance_cents' => fake()->numberBetween(1_000, 500_000),
        ];
    }
}
