<?php

namespace Tests\Feature;

use App\Actions\PlaceOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_a_customers_orders_filtered_by_status(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['email' => 'jane@example.com']);
        Order::factory()->count(2)->create(['customer_email' => 'jane@example.com', 'status' => 'paid']);
        Order::factory()->create(['customer_email' => 'jane@example.com', 'status' => 'pending']);
        Order::factory()->create(['customer_email' => 'other@example.com', 'status' => 'paid']);

        $this->getJson("/api/customers/{$customer->id}/orders?status=paid")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_placing_an_order_records_the_customer(): void
    {
        $customer = Customer::factory()->create(['email' => 'jane@example.com']);

        $order = app(PlaceOrder::class)($customer, 2500);

        $this->assertSame('jane@example.com', $order->customer_email);
        $this->assertSame('pending', $order->status);
    }
}
