<?php

namespace Database\Factories;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Offer> */
class OfferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'price_cents' => fake()->numberBetween(100, 100000),
            'accepted' => false,
            'tags' => [],
        ];
    }

    public function accepted(): static
    {
        return $this->state(['accepted' => true]);
    }
}
