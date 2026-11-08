<?php

namespace Tests\Hidden;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdersApiHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
    }

    private function token(User $user, array $abilities): string
    {
        return $user->createToken('integration', $abilities)->plainTextToken;
    }

    private function assertNoInternals($response): void
    {
        $body = $response->getContent();
        $this->assertStringNotContainsString('App\\', $body);
        $this->assertStringNotContainsString('App\\\\', $body);
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString('vendor', $body);
    }

    public function test_a_read_token_lists_only_the_owners_orders_newest_first(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $old = Order::factory()->create(['user_id' => $alice->id, 'reference' => 'A-OLD', 'created_at' => now()->subDay()]);
        $new = Order::factory()->create(['user_id' => $alice->id, 'reference' => 'A-NEW', 'created_at' => now()]);
        Order::factory()->create(['user_id' => $bob->id, 'reference' => 'B-1']);

        $response = $this->withToken($this->token($alice, ['orders:read']))->get('/api/orders');

        $response->assertOk();
        $references = collect($response->json('data'))->pluck('reference')->all();
        $this->assertSame(['A-NEW', 'A-OLD'], $references);
    }

    public function test_a_read_token_cannot_create_orders(): void
    {
        $alice = User::factory()->create();

        $response = $this->withToken($this->token($alice, ['orders:read']))
            ->post('/api/orders', ['reference' => 'A-1', 'total_cents' => 100]);

        $response->assertForbidden();
        $this->assertIsString($response->json('message'));
        $this->assertSame(0, Order::count());
    }

    public function test_a_write_token_without_read_cannot_list(): void
    {
        $alice = User::factory()->create();

        $this->withToken($this->token($alice, ['orders:write']))->get('/api/orders')->assertForbidden();
    }

    public function test_a_write_token_creates_an_order_without_an_accept_header(): void
    {
        $alice = User::factory()->create();

        $response = $this->withToken($this->token($alice, ['orders:write']))
            ->post('/api/orders', ['reference' => 'A-1', 'total_cents' => 100]);

        $response->assertCreated();
        $this->assertSame($alice->id, Order::query()->where('reference', 'A-1')->value('user_id'));
    }

    public function test_another_users_order_is_a_404_without_internals(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $bob->id]);

        $response = $this->withToken($this->token($alice, ['orders:read']))->get("/api/orders/{$order->id}");

        $response->assertNotFound();
        $this->assertNoInternals($response);
    }

    public function test_a_missing_order_is_a_404_without_internals(): void
    {
        $alice = User::factory()->create();

        $response = $this->withToken($this->token($alice, ['orders:read']))->get('/api/orders/999999');

        $response->assertNotFound();
        $this->assertIsString($response->json('message'));
        $this->assertNoInternals($response);
    }

    public function test_api_responses_do_not_start_a_session(): void
    {
        $alice = User::factory()->create();

        $this->withToken($this->token($alice, ['orders:read']))->get('/api/orders')
            ->assertOk()
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_guest_with_json_accept_gets_json_401(): void
    {
        $response = $this->getJson('/api/orders');

        $response->assertUnauthorized();
        $this->assertIsString($response->json('message'));
    }

    public function test_guest_without_accept_header_gets_json_401(): void
    {
        $response = $this->get('/api/orders');

        $response->assertUnauthorized();
        $this->assertIsString($response->json('message'));
    }
}
