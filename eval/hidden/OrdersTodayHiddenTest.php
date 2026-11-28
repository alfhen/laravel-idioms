<?php

namespace Tests\Hidden;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrdersTodayHiddenTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $totalCents, string $placedAtUtc): void
    {
        DB::table('orders')->insert([
            'total_cents' => $totalCents,
            'placed_at' => $placedAtUtc,
            'created_at' => $placedAtUtc,
            'updated_at' => $placedAtUtc,
        ]);
    }

    private function stats(): array
    {
        return $this->getJson('/api/stats/orders-today')->assertOk()->json();
    }

    private function signIn(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    public function test_guest_gets_json_401(): void
    {
        $response = $this->getJson('/api/stats/orders-today');

        $response->assertUnauthorized();
        $this->assertIsString($response->json('message'));
    }

    public function test_it_counts_and_sums_only_orders_placed_today_in_utc(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 23:59:30', 'UTC'));
        $this->order(9999, '2026-11-13 23:59:59');
        $this->order(100, '2026-11-14 00:00:00');
        $this->order(250, '2026-11-14 12:30:00');
        $this->order(1000, '2026-11-14 23:59:00');
        $this->order(7777, '2026-11-15 00:00:00');
        $this->signIn();

        $stats = $this->stats();

        $this->assertSame(3, $stats['count']);
        $this->assertSame(1350, $stats['total_cents']);
    }

    public function test_an_empty_day_returns_zeros(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00', 'UTC'));
        $this->order(500, '2026-11-13 12:00:00');
        $this->signIn();

        $stats = $this->stats();

        $this->assertSame(0, $stats['count']);
        $this->assertSame(0, $stats['total_cents']);
    }

    public function test_the_value_is_cached_for_sixty_seconds(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00', 'UTC'));
        $this->order(100, '2026-11-14 11:00:00');
        $this->signIn();

        $this->assertSame(['count' => 1, 'total_cents' => 100], $this->stats());

        $this->order(200, '2026-11-14 12:00:05');
        $this->travel(30)->seconds();

        $this->assertSame(['count' => 1, 'total_cents' => 100], $this->stats());

        $this->travel(31)->seconds();

        $this->assertSame(['count' => 2, 'total_cents' => 300], $this->stats());
    }
}
