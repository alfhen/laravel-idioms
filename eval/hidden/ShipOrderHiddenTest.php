<?php

namespace Tests\Hidden;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ShipOrderHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Order::flushEventListeners();
        parent::tearDown();
    }

    /** Messages delivered through the array transport, plus any mailable left on the database queue. */
    private function mails(): array
    {
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
            ->map(fn ($m) => $m->getOriginalMessage())->all();
        $queued = DB::getSchemaBuilder()->hasTable('jobs')
            ? DB::table('jobs')->where('payload', 'like', '%OrderShipped%')->count() : 0;

        return [$sent, $queued];
    }

    private function mailCount(): int
    {
        [$sent, $queued] = $this->mails();

        return count($sent) + $queued;
    }

    private function order(User $customer, string $status = 'paid'): Order
    {
        return Order::factory()->create(['user_id' => $customer->id, 'status' => $status, 'total_cents' => 12999]);
    }

    private function points(User $user): int
    {
        return (int) DB::table('users')->where('id', $user->id)->value('points');
    }

    private function shipAsStaff(Order $order)
    {
        $staff = User::factory()->create();
        DB::table('users')->where('id', $staff->id)->update(['is_staff' => true]);
        Sanctum::actingAs($staff->fresh(), ['*']);
        $response = $this->postJson("/api/orders/{$order->id}/ship", ['carrier' => 'PostNord', 'tracking_number' => 'TRK-123456']);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    public function test_shipping_records_the_shipment_emails_the_customer_once_and_awards_points(): void
    {
        $customer = User::factory()->create(['email' => 'customer@example.test']);
        $order = $this->order($customer);

        $this->shipAsStaff($order)->assertCreated();

        $this->assertSame('shipped', DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame(1, DB::table('order_shipments')->where('order_id', $order->id)->count());
        $this->assertSame(129, $this->points($customer));
        [$sent] = $this->mails();
        $this->assertCount(1, $sent);
        $this->assertSame(['customer@example.test'], array_map(fn ($a) => $a->getAddress(), $sent[0]->getTo()));
        $this->assertStringContainsString('TRK-123456', (string) ($sent[0]->getHtmlBody() ?: $sent[0]->getTextBody()));
    }

    public function test_shipping_again_is_a_409_and_nothing_happens_twice(): void
    {
        $customer = User::factory()->create();
        $order = $this->order($customer);
        $this->shipAsStaff($order)->assertCreated();

        $this->shipAsStaff($order)->assertStatus(409);

        $this->assertSame(1, DB::table('order_shipments')->where('order_id', $order->id)->count());
        $this->assertSame(1, $this->mailCount());
        $this->assertSame(129, $this->points($customer));
    }

    public function test_a_rolled_back_transaction_sends_no_email_and_awards_no_points(): void
    {
        $customer = User::factory()->create();
        $order = $this->order($customer);

        try {
            DB::transaction(function () use ($order) {
                $order->ship('PostNord', 'TRK-ROLLBACK');
                throw new RuntimeException('back-office step failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->mailCount(), 'email sent for a rolled-back shipment');
        $this->assertSame(0, $this->points($customer));
        $this->assertSame(0, DB::table('order_shipments')->count());
    }

    public function test_a_concurrent_ship_that_lands_mid_request_is_not_repeated(): void
    {
        $customer = User::factory()->create();
        $order = $this->order($customer);
        $baseline = DB::transactionLevel();
        $flag = (object) ['fired' => false];
        Order::retrieved(function (Order $model) use ($flag, $baseline, $order) {
            if ($flag->fired || $model->getKey() !== $order->getKey() || DB::transactionLevel() !== $baseline) {
                return;
            }
            $flag->fired = true;
            DB::table('orders')->where('id', $order->id)->update(['status' => 'shipped']);
            DB::table('order_shipments')->insert(['order_id' => $order->id, 'carrier' => 'DHL', 'tracking_number' => 'OTHER', 'created_at' => now(), 'updated_at' => now()]);
        });

        $status = $this->shipAsStaff($order)->status();

        $this->assertSame(1, DB::table('order_shipments')->where('order_id', $order->id)->count());
        $this->assertNotSame(500, $status);
        if ($flag->fired) {
            $this->assertSame(409, $status);
            $this->assertSame(0, $this->mailCount());
            $this->assertSame(0, $this->points($customer));
        }
    }

    public function test_only_staff_can_ship(): void
    {
        $order = $this->order(User::factory()->create());
        Sanctum::actingAs(User::factory()->create()->fresh(), ['*']);

        $this->postJson("/api/orders/{$order->id}/ship", ['carrier' => 'PostNord', 'tracking_number' => 'T'])->assertForbidden();
        $this->assertSame(0, $this->mailCount());
    }

    public function test_guest_without_accept_header_gets_json_401(): void
    {
        $order = $this->order(User::factory()->create());

        $response = $this->post("/api/orders/{$order->id}/ship", ['carrier' => 'PostNord', 'tracking_number' => 'T']);

        $response->assertUnauthorized();
        $this->assertIsString($response->json('message'));
    }
}
