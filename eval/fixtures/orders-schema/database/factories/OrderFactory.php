<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'ORD-'.Str::upper(Str::random(10)),
            'customer_email' => fake()->safeEmail(),
            'status' => fake()->randomElement(['pending', 'paid', 'shipped']),
            'total_cents' => fake()->numberBetween(500, 50000),
        ];
    }
}
