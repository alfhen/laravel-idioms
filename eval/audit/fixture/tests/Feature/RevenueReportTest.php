<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sums_revenue_per_country(): void
    {
        Order::factory()->create(['country' => 'SE', 'total_cents' => 500]);
        Order::factory()->create(['country' => 'DK', 'total_cents' => 1000]);
        Order::factory()->create(['country' => 'DK', 'total_cents' => 250]);

        $this->getJson('/api/reports/revenue-by-country')->assertExactJson(['DK' => 1250, 'SE' => 500]);
    }
}
