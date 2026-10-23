<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'country' => fake()->randomElement(['DK', 'DE', 'SE']),
            'total_cents' => fake()->numberBetween(100, 50000),
        ];
    }
}
