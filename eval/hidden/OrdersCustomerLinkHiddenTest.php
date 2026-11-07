<?php

namespace Tests\Hidden;

use App\Actions\PlaceOrder;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrdersCustomerLinkHiddenTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $email): int
    {
        return DB::table('customers')->insertGetId(['name' => 'C', 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function legacyOrder(string $email, string $status = 'paid', string $at = '2025-06-01 12:00:00'): int
    {
        static $n = 0;
        $n++;

        return DB::table('orders')->insertGetId([
            'reference' => "LEG-{$n}", 'customer_email' => $email, 'status' => $status, 'total_cents' => 1000,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function test_customer_id_is_a_nullable_foreign_key_and_customer_email_stays(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'customer_email'));
        $column = collect(Schema::getColumns('orders'))->firstWhere('name', 'customer_id');
        $this->assertNotNull($column, 'orders.customer_id missing');
        $this->assertTrue($column['nullable'], 'customer_id must be nullable while old code still inserts orders');

        $fk = collect(Schema::getForeignKeys('orders'))->first(fn ($fk) => $fk['columns'] === ['customer_id']);
        $this->assertNotNull($fk, 'no foreign key on customer_id');
        $this->assertSame('customers', $fk['foreign_table']);
    }

    public function test_deleting_a_customer_keeps_their_orders_detached(): void
    {
        $customer = $this->customer('jane@example.com');
        $order = $this->legacyOrder('jane@example.com');
        DB::table('orders')->where('id', $order)->update(['customer_id' => $customer]);

        DB::table('customers')->where('id', $customer)->delete();

        $this->assertNull(DB::table('orders')->where('id', $order)->value('customer_id'));
    }

    public function test_the_backfill_links_every_matching_order_and_leaves_the_rest(): void
    {
        $jane = $this->customer('jane@example.com');
        $omar = $this->customer('omar@example.com');
        $variants = ['jane@example.com', ' Jane@Example.com', 'JANE@EXAMPLE.COM ', 'omar@example.com', 'Omar@example.com', 'nobody@example.com'];
        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = ['reference' => "BF-{$i}", 'customer_email' => $variants[$i % 6], 'status' => 'paid', 'total_cents' => 100,
                'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00'];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        $this->artisan('orders:link-customers')->assertSuccessful();

        $byEmail = DB::table('orders')->get()->groupBy(fn ($o) => strtolower(trim($o->customer_email)));
        $this->assertTrue($byEmail['jane@example.com']->every(fn ($o) => (int) $o->customer_id === $jane), 'jane orders not all linked');
        $this->assertTrue($byEmail['omar@example.com']->every(fn ($o) => (int) $o->customer_id === $omar), 'omar orders not all linked');
        $this->assertTrue($byEmail['nobody@example.com']->every(fn ($o) => $o->customer_id === null), 'unmatched orders were changed');
        $this->assertSame(2500, DB::table('orders')->where('updated_at', '2025-01-01 00:00:00')->count(), 'updated_at changed');

        $this->artisan('orders:link-customers')->assertSuccessful();
        $this->assertSame(0, DB::table('orders')->whereNull('customer_id')->where('customer_email', 'not like', '%nobody%')->count());
    }

    public function test_an_index_serves_the_customer_orders_query(): void
    {
        $index = collect(Schema::getIndexes('orders'))
            ->first(fn ($i) => ($i['columns'][0] ?? null) === 'customer_id' && in_array('status', $i['columns'], true));

        $this->assertNotNull($index, 'no index leading with customer_id that covers status');
    }

    public function test_placing_an_order_sets_both_columns(): void
    {
        $customer = Customer::query()->findOrFail($this->customer('jane@example.com'));

        $order = app(PlaceOrder::class)($customer, 2500);

        $row = DB::table('orders')->find($order->id);
        $this->assertSame($customer->id, (int) $row->customer_id);
        $this->assertSame('jane@example.com', $row->customer_email);
    }

    public function test_the_endpoint_uses_the_linked_customer(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $jane = $this->customer('jane@example.com');
        $linkedOld = $this->legacyOrder(' JANE@example.com', 'paid', '2025-06-01 12:00:00');
        $linkedNew = $this->legacyOrder('Jane@Example.com', 'paid', '2025-07-01 12:00:00');
        $pending = $this->legacyOrder('jane@example.com', 'pending');
        DB::table('orders')->whereIn('id', [$linkedOld, $linkedNew, $pending])->update(['customer_id' => $jane]);

        $response = $this->getJson("/api/customers/{$jane}/orders?status=paid");

        $response->assertOk();
        $this->assertSame([$linkedNew, $linkedOld], collect($response->json('data'))->pluck('id')->all());
    }
}
