<?php

namespace Tests\Hidden;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SubscriptionRenewalsHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
    }

    private function body(TestResponse $response): array
    {
        $json = $response->json();

        return is_array($json['data'] ?? null) && ! isset($json['id']) ? $json['data'] : $json;
    }

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    private function user(string $timezone): User
    {
        return User::factory()->create(['timezone' => $timezone]);
    }

    private function day(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }

    private function subscribe(User $user, string $startedOn): array
    {
        $response = $this->postJson("/api/users/{$user->id}/subscriptions", ['started_on' => $startedOn]);
        $response->assertCreated();

        return $this->body($response);
    }

    private function renew(array $subscription): array
    {
        $response = $this->postJson("/api/subscriptions/{$subscription['id']}/renew");
        $response->assertSuccessful();

        return $this->body($response);
    }

    private function placeOrder(User $user, string $reference, string $utc): array
    {
        $this->at($utc);
        $response = $this->postJson("/api/users/{$user->id}/orders", ['reference' => $reference]);
        $response->assertCreated();

        return $this->body($response);
    }

    /** @return list<array<string, mixed>> */
    private function ordersToday(User $user, string $utc): array
    {
        $this->at($utc);
        $response = $this->getJson("/api/users/{$user->id}/orders/today");
        $response->assertOk();
        $json = $response->json();

        return array_values($json['data'] ?? $json);
    }

    /** @return list<string> */
    private function referencesToday(User $user, string $utc): array
    {
        $references = array_column($this->ordersToday($user, $utc), 'reference');
        sort($references);

        return $references;
    }

    private function assertUtcInstant(string $expectedUtc, mixed $value, string $label): void
    {
        $this->assertIsString($value, $label);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|[+-]00:?00)$/', $value, $label);
        $parsed = CarbonImmutable::parse($value);
        $this->assertSame(0, $parsed->getOffset(), $label);
        $this->assertTrue($parsed->equalTo(CarbonImmutable::parse($expectedUtc, 'UTC')), "{$label}: {$value} is not {$expectedUtc} UTC");
    }

    public function test_a_subscription_is_created_with_its_first_renewal_date(): void
    {
        $subscription = $this->subscribe($this->user('Europe/Copenhagen'), '2026-03-15');

        $this->assertSame('2026-03-15', $this->day($subscription['started_on']));
        $this->assertSame('2026-04-15', $this->day($subscription['renews_on']));
        $this->assertSame(1, DB::table('subscriptions')->count());
    }

    public function test_a_subscription_started_on_the_31st_first_renews_on_the_last_day_of_february(): void
    {
        $subscription = $this->subscribe($this->user('Europe/Copenhagen'), '2026-01-31');

        $this->assertSame('2026-02-28', $this->day($subscription['renews_on']));
    }

    public function test_renewals_return_to_the_start_day_after_a_short_month(): void
    {
        $subscription = $this->subscribe($this->user('Europe/Copenhagen'), '2026-01-31');

        $renewals = [];
        foreach (range(1, 3) as $ignored) {
            $subscription = $this->renew($subscription);
            $renewals[] = $this->day($subscription['renews_on']);
        }

        $this->assertSame(['2026-03-31', '2026-04-30', '2026-05-31'], $renewals);
    }

    public function test_a_leap_year_start_renews_on_february_29_and_then_the_31st(): void
    {
        $subscription = $this->subscribe($this->user('Europe/Copenhagen'), '2024-01-31');
        $this->assertSame('2024-02-29', $this->day($subscription['renews_on']));

        $subscription = $this->renew($subscription);
        $this->assertSame('2024-03-31', $this->day($subscription['renews_on']));
    }

    public function test_started_on_is_unchanged_by_creating_and_renewing(): void
    {
        $subscription = $this->subscribe($this->user('Europe/Copenhagen'), '2026-01-31');
        $this->assertSame('2026-01-31', $this->day($subscription['started_on']));

        $subscription = $this->renew($subscription);
        $subscription = $this->renew($subscription);

        $this->assertSame('2026-01-31', $this->day($subscription['started_on']));
    }

    public function test_orders_today_lists_only_that_users_orders(): void
    {
        $alice = $this->user('UTC');
        $bob = $this->user('UTC');
        $this->placeOrder($alice, 'A-1', '2026-11-14 09:00:00');
        $this->placeOrder($bob, 'B-1', '2026-11-14 09:05:00');

        $this->assertSame(['A-1'], $this->referencesToday($alice, '2026-11-14 10:00:00'));
    }

    public function test_orders_today_follows_the_users_day_in_auckland(): void
    {
        $user = $this->user('Pacific/Auckland');
        $this->placeOrder($user, 'LATE-14TH', '2026-11-14 10:30:00');
        $this->placeOrder($user, 'EARLY-15TH', '2026-11-14 12:30:00');
        $this->placeOrder($user, 'EARLY-16TH', '2026-11-15 11:30:00');

        $this->assertSame(['EARLY-15TH'], $this->referencesToday($user, '2026-11-14 12:45:00'));
    }

    public function test_orders_today_follows_the_users_day_in_los_angeles(): void
    {
        $user = $this->user('America/Los_Angeles');
        $this->placeOrder($user, 'MORNING-14TH', '2026-11-14 12:30:00');

        $this->assertSame(['MORNING-14TH'], $this->referencesToday($user, '2026-11-14 12:45:00'));
        $this->assertSame(['MORNING-14TH'], $this->referencesToday($user, '2026-11-15 07:30:00'));
        $this->assertSame([], $this->referencesToday($user, '2026-11-15 09:00:00'));
    }

    public function test_orders_today_handles_the_copenhagen_daylight_saving_day(): void
    {
        $user = $this->user('Europe/Copenhagen');
        $this->placeOrder($user, 'LATE-28TH', '2026-03-28 22:30:00');
        $this->placeOrder($user, 'EARLY-29TH', '2026-03-28 23:30:00');
        $this->placeOrder($user, 'LATE-29TH', '2026-03-29 21:30:00');
        $this->placeOrder($user, 'EARLY-30TH', '2026-03-29 22:30:00');

        $this->assertSame(['EARLY-29TH', 'LATE-29TH'], $this->referencesToday($user, '2026-03-29 12:00:00'));
    }

    public function test_placed_at_is_the_utc_instant_the_order_was_placed(): void
    {
        $user = $this->user('Pacific/Auckland');
        $order = $this->placeOrder($user, 'NZ-1', '2026-11-14 12:30:00');

        $this->assertUtcInstant('2026-11-14 12:30:00', $order['placed_at'] ?? null, 'create response');

        $listed = $this->ordersToday($user, '2026-11-14 12:45:00');
        $this->assertCount(1, $listed);
        $this->assertUtcInstant('2026-11-14 12:30:00', $listed[0]['placed_at'] ?? null, 'today list');
    }

    public function test_a_valid_iana_timezone_is_saved(): void
    {
        $user = $this->user('UTC');

        $this->patchJson("/api/users/{$user->id}", ['timezone' => 'America/Los_Angeles'])->assertSuccessful();

        $this->assertSame('America/Los_Angeles', $user->fresh()->timezone);
    }

    public function test_clearly_invalid_timezones_are_rejected(): void
    {
        $user = $this->user('Europe/Copenhagen');

        foreach (['Mars/Olympus', 'Not a timezone', ''] as $timezone) {
            $this->patchJson("/api/users/{$user->id}", ['timezone' => $timezone])
                ->assertStatus(422);
        }

        $this->assertSame('Europe/Copenhagen', $user->fresh()->timezone);
    }
}
