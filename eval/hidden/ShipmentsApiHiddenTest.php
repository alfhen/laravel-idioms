<?php

namespace Tests\Hidden;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentsApiHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'LABELSECRETz8Qv';

    private User $user;

    private User $colleague;

    private User $outsider;

    private User $homeless;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        foreach ([1 => 'North', 2 => 'South'] as $id => $name) {
            DB::table('warehouses')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->user = User::factory()->create(['id' => 1, 'warehouse_id' => 1]);
        $this->colleague = User::factory()->create(['id' => 10, 'warehouse_id' => 1]);
        $this->outsider = User::factory()->create(['id' => 5, 'warehouse_id' => 2]);
        $this->homeless = User::factory()->create(['id' => 7, 'warehouse_id' => null]);

        foreach ([1 => 1, 2 => 2, 3 => null] as $id => $warehouse) {
            DB::table('carriers')->insert(['id' => $id, 'warehouse_id' => $warehouse, 'name' => "Carrier {$id}", 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function shipment(string $code, array $attributes = []): void
    {
        $warehouse = array_key_exists('warehouse_id', $attributes) ? $attributes['warehouse_id'] : 1;
        $status = $attributes['status'] ?? 'unpaid';

        DB::table('shipments')->insert([
            'warehouse_id' => $warehouse,
            'user_id' => $this->user->id,
            'carrier_id' => $warehouse === 2 ? 2 : ($warehouse === null ? 3 : 1),
            'code' => $code,
            'status' => $status,
            'paid' => $status !== 'unpaid',
            'label_secret' => null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    private function list(string $query = '', ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->user, ['*']);

        return $this->getJson('/api/shipments'.($query === '' ? '' : '?'.$query));
    }

    private function codes(TestResponse $response): array
    {
        $this->assertSame(200, $response->status(), 'list answered '.$response->status());

        return collect($response->json('data'))->pluck('code')->all();
    }

    private function assertSameSet(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, $message);
    }

    private function assertClientError(TestResponse $response, string $query): void
    {
        $this->assertContains($response->status(), [400, 422], "?{$query} answered {$response->status()}");
    }

    private function create(array $body, ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->user, ['*']);

        return $this->postJson('/api/shipments', $body);
    }

    private function assertMalformedCarrierRejected(mixed $carrier): void
    {
        $response = $this->create(['carrier_id' => $carrier, 'code' => 'BAD-1']);

        $this->assertSame(422, $response->status(), 'carrier_id '.json_encode($carrier).' answered '.$response->status());
        $this->assertSame(0, DB::table('shipments')->count());
    }

    public function test_the_list_shows_only_the_users_warehouse(): void
    {
        $this->shipment('NORTH-1');
        $this->shipment('NORTH-2', ['user_id' => $this->colleague->id]);
        $this->shipment('SOUTH-1', ['warehouse_id' => 2, 'user_id' => $this->outsider->id]);
        $this->shipment('LOOSE-1', ['warehouse_id' => null, 'user_id' => $this->homeless->id]);

        $this->assertSameSet(['NORTH-1', 'NORTH-2'], $this->codes($this->list()));
    }

    public function test_the_user_id_filter_does_not_match_other_ids_containing_the_digits(): void
    {
        $this->shipment('BY-ONE', ['user_id' => 1]);
        $this->shipment('BY-TEN', ['user_id' => 10]);

        $this->assertSame(['BY-ONE'], $this->codes($this->list('filter[user_id]=1')));
        $this->assertSame(['BY-TEN'], $this->codes($this->list('filter[user_id]=10')));
    }

    public function test_the_status_filter_paid_never_returns_unpaid_shipments(): void
    {
        $this->shipment('S-PAID', ['status' => 'paid']);
        $this->shipment('S-UNPAID', ['status' => 'unpaid']);
        $this->shipment('S-SHIPPED', ['status' => 'shipped']);

        $this->assertSame(['S-PAID'], $this->codes($this->list('filter[status]=paid')));
        $this->assertSame(['S-UNPAID'], $this->codes($this->list('filter[status]=unpaid')));
    }

    public function test_the_paid_flag_zero_or_false_returns_only_unpaid_shipments(): void
    {
        $this->shipment('F-UNPAID-1', ['status' => 'unpaid']);
        $this->shipment('F-UNPAID-2', ['status' => 'unpaid']);
        $this->shipment('F-PAID', ['status' => 'paid']);
        $this->shipment('F-SHIPPED', ['status' => 'shipped']);

        $this->assertSameSet(['F-UNPAID-1', 'F-UNPAID-2'], $this->codes($this->list('filter[paid]=0')), 'filter[paid]=0');
        $this->assertSameSet(['F-UNPAID-1', 'F-UNPAID-2'], $this->codes($this->list('filter[paid]=false')), 'filter[paid]=false');
    }

    public function test_the_paid_flag_one_or_true_returns_only_paid_shipments(): void
    {
        $this->shipment('T-UNPAID', ['status' => 'unpaid']);
        $this->shipment('T-PAID', ['status' => 'paid']);
        $this->shipment('T-SHIPPED', ['status' => 'shipped']);

        $this->assertSameSet(['T-PAID', 'T-SHIPPED'], $this->codes($this->list('filter[paid]=1')), 'filter[paid]=1');
        $this->assertSameSet(['T-PAID', 'T-SHIPPED'], $this->codes($this->list('filter[paid]=true')), 'filter[paid]=true');
    }

    public function test_unknown_or_internal_filters_are_rejected(): void
    {
        $this->shipment('X-1', ['label_secret' => 'aaa']);
        $this->shipment('X-2', ['label_secret' => 'zzz']);

        foreach (['filter[label_secret]=aaa', 'filter[nonexistent]=1', 'filter[warehouse_id]=2'] as $query) {
            $this->assertClientError($this->list($query), $query);
        }
    }

    public function test_unknown_or_internal_sorts_are_rejected(): void
    {
        $this->shipment('X-1', ['label_secret' => 'zzz']);
        $this->shipment('X-2', ['label_secret' => 'aaa']);

        foreach (['sort=label_secret', 'sort=-label_secret', 'sort=nonexistent', 'sort=code,label_secret', 'sort='.urlencode('code; drop table shipments')] as $query) {
            $this->assertClientError($this->list($query), $query);
        }
        $this->assertSame(2, DB::table('shipments')->count());
    }

    public function test_the_list_sorts_on_public_fields(): void
    {
        $this->shipment('B-CODE', ['created_at' => Carbon::parse('2026-01-01 10:00:00')]);
        $this->shipment('C-CODE', ['created_at' => Carbon::parse('2026-01-02 10:00:00')]);
        $this->shipment('A-CODE', ['created_at' => Carbon::parse('2026-01-03 10:00:00')]);

        $this->assertSame(['A-CODE', 'B-CODE', 'C-CODE'], $this->codes($this->list('sort=code')));
        $this->assertSame(['C-CODE', 'B-CODE', 'A-CODE'], $this->codes($this->list('sort=-code')));
        $this->assertSame(['B-CODE', 'C-CODE', 'A-CODE'], $this->codes($this->list('sort=created_at')));
    }

    public function test_the_list_never_returns_the_internal_column(): void
    {
        $this->shipment('HIDDEN-1', ['label_secret' => self::SECRET]);

        $response = $this->list();

        $this->assertSame(['HIDDEN-1'], $this->codes($response));
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_a_user_without_a_warehouse_never_sees_unscoped_shipments(): void
    {
        $this->shipment('LOOSE-1', ['warehouse_id' => null, 'user_id' => $this->homeless->id]);
        $this->shipment('LOOSE-2', ['warehouse_id' => null, 'user_id' => $this->user->id]);
        $this->shipment('NORTH-1');

        $response = $this->list('', $this->homeless);

        $this->assertContains($response->status(), [200, 403], 'answered '.$response->status());
        if ($response->status() === 200) {
            $this->assertSame([], $response->json('data'));
        }
        foreach (['LOOSE-1', 'LOOSE-2', 'NORTH-1'] as $code) {
            $this->assertStringNotContainsString($code, $response->getContent());
        }
    }

    public function test_creating_a_shipment_uses_the_users_warehouse_and_identity(): void
    {
        $response = $this->create(['carrier_id' => 1, 'code' => 'NEW-1', 'warehouse_id' => 2, 'user_id' => 10]);

        $this->assertSame(201, $response->status(), 'answered '.$response->status());
        $row = DB::table('shipments')->where('code', 'NEW-1')->sole();
        $this->assertSame(1, (int) $row->warehouse_id);
        $this->assertSame(1, (int) $row->user_id);
        $this->assertSame(1, (int) $row->carrier_id);
    }

    public function test_creating_with_another_warehouses_carrier_is_refused(): void
    {
        $response = $this->create(['carrier_id' => 2, 'code' => 'NEW-2']);

        $this->assertContains($response->status(), [403, 404, 422], 'answered '.$response->status());
        $this->assertSame(0, DB::table('shipments')->count());
    }

    public function test_a_user_without_a_warehouse_cannot_create_with_an_unscoped_carrier(): void
    {
        $response = $this->create(['carrier_id' => 3, 'code' => 'NEW-3'], $this->homeless);

        $this->assertContains($response->status(), [403, 404, 422], 'answered '.$response->status());
        $this->assertSame(0, DB::table('shipments')->count());
    }

    public function test_an_array_carrier_id_is_a_validation_error(): void
    {
        $this->assertMalformedCarrierRejected([1]);
    }

    public function test_a_zero_carrier_id_is_a_validation_error(): void
    {
        $this->assertMalformedCarrierRejected(0);
    }

    public function test_a_false_carrier_id_is_a_validation_error(): void
    {
        $this->assertMalformedCarrierRejected(false);
    }

    public function test_an_injected_string_carrier_id_is_a_validation_error(): void
    {
        $this->assertMalformedCarrierRejected('1 OR 1');
    }
}
