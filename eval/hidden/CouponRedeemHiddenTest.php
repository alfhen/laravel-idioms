<?php

namespace Tests\Hidden;

use App\Events\CouponRedeemed;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CouponRedeemHiddenTest extends TestCase
{
    use RefreshDatabase;

    private function coupon(int $max, int $redeemed = 0): object
    {
        $id = DB::table('coupons')->insertGetId([
            'code' => 'SPRING', 'max_redemptions' => $max, 'redeemed_count' => $redeemed,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('coupons')->find($id);
    }

    private function redeemAs(User $user)
    {
        Sanctum::actingAs($user, ['*']);
        $response = $this->postJson('/api/coupons/SPRING/redeem');
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function state(int $couponId): array
    {
        return [
            (int) DB::table('coupons')->where('id', $couponId)->value('redeemed_count'),
            DB::table('coupon_redemptions')->where('coupon_id', $couponId)->count(),
        ];
    }

    /**
     * Writes a competing redemption the first time the coupon is loaded outside the app's own transaction,
     * as a request on another server would between this request's read and its write.
     */
    private function competeOnFirstRead(int $couponId, int $userId): object
    {
        $baseline = DB::transactionLevel();
        $flag = (object) ['fired' => false];
        Coupon::retrieved(function () use ($flag, $baseline, $couponId, $userId) {
            if ($flag->fired || DB::transactionLevel() !== $baseline) {
                return;
            }
            $flag->fired = true;
            DB::table('coupon_redemptions')->insert(['coupon_id' => $couponId, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('coupons')->where('id', $couponId)->increment('redeemed_count');
        });

        return $flag;
    }

    protected function tearDown(): void
    {
        Coupon::flushEventListeners();
        parent::tearDown();
    }

    public function test_redeeming_twice_returns_the_same_redemption_and_dispatches_once(): void
    {
        Event::fake([CouponRedeemed::class]);
        $coupon = $this->coupon(10);
        $user = User::factory()->create();

        $first = $this->redeemAs($user)->assertCreated();
        $second = $this->redeemAs($user)->assertOk();

        $this->assertSame($first->json('redemption_id'), $second->json('redemption_id'));
        $this->assertSame(1, $second->json('redeemed_count'));
        $this->assertSame([1, 1], $this->state($coupon->id));
        Event::assertDispatchedTimes(CouponRedeemed::class, 1);
    }

    public function test_a_used_up_coupon_returns_409_and_stays_at_its_cap(): void
    {
        Event::fake([CouponRedeemed::class]);
        $coupon = $this->coupon(2);
        [$a, $b, $c] = User::factory()->count(3)->create()->all();

        $this->redeemAs($a)->assertCreated();
        $this->redeemAs($b)->assertCreated();
        $response = $this->redeemAs($c)->assertStatus(409);

        $this->assertIsString($response->json('message'));
        $this->assertSame([2, 2], $this->state($coupon->id));
        Event::assertDispatchedTimes(CouponRedeemed::class, 2);
    }

    public function test_the_cap_holds_when_another_server_redeems_the_last_one_mid_request(): void
    {
        Event::fake([CouponRedeemed::class]);
        $coupon = $this->coupon(1);
        $other = User::factory()->create();
        $user = User::factory()->create();
        $this->competeOnFirstRead($coupon->id, $other->id);

        $status = $this->redeemAs($user)->status();

        [$count, $rows] = $this->state($coupon->id);
        $this->assertLessThanOrEqual(1, $rows, 'more redemptions than max_redemptions');
        $this->assertSame($rows, $count, 'redeemed_count disagrees with the redemption rows');
        $this->assertNotSame(500, $status);
    }

    public function test_a_double_submit_that_lands_mid_request_returns_the_existing_redemption(): void
    {
        Event::fake([CouponRedeemed::class]);
        $coupon = $this->coupon(5);
        $user = User::factory()->create();
        $competitor = $this->competeOnFirstRead($coupon->id, $user->id);

        $response = $this->redeemAs($user);

        [$count, $rows] = $this->state($coupon->id);
        $this->assertSame(1, $rows, 'the same user redeemed twice');
        $this->assertSame(1, $count);
        if ($competitor->fired) {
            $response->assertOk();
            Event::assertNotDispatched(CouponRedeemed::class);
        } else {
            $response->assertCreated();
        }
    }

    public function test_the_database_rejects_a_second_redemption_by_the_same_user(): void
    {
        $coupon = $this->coupon(5);
        $user = User::factory()->create();
        $row = ['coupon_id' => $coupon->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('coupon_redemptions')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('coupon_redemptions')->insert($row);
    }

    public function test_guest_without_accept_header_gets_json_401(): void
    {
        $this->coupon(5);

        $response = $this->post('/api/coupons/SPRING/redeem');

        $response->assertUnauthorized();
        $this->assertIsString($response->json('message'));
    }
}
