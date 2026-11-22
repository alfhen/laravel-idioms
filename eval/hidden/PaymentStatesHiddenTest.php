<?php

namespace Tests\Hidden;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentStatesHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'mail.default' => 'array']);
    }

    protected function tearDown(): void
    {
        Payment::flushEventListeners();
        parent::tearDown();
    }

    /**
     * Mail delivered through the array transport, plus any mail or notification left on a database queue.
     * The real transport is used instead of Mail::fake(), because the fake records queued mail
     * at once, even mail that is held until the transaction commits.
     */
    private function sentMessages(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
            ->map(fn ($m) => $m->getOriginalMessage())->values()->all();
    }

    private function mailCount(): int
    {
        $queued = Schema::hasTable('jobs')
            ? DB::table('jobs')->where(fn ($q) => $q->where('payload', 'like', '%Mail%')->orWhere('payload', 'like', '%Notification%'))->count()
            : 0;

        return count($this->sentMessages()) + $queued;
    }

    private function payment(User $owner, string $state = 'pending', int $amount = 4999): int
    {
        return DB::table('payments')->insertGetId([
            'user_id' => $owner->id, 'amount' => $amount, 'currency' => 'DKK', 'state' => $state,
        ]);
    }

    private function state(int $payment): ?string
    {
        return DB::table('payments')->where('id', $payment)->value('state');
    }

    private function ledger(int $payment): array
    {
        return DB::table('ledger_entries')->where('payment_id', $payment)->pluck('amount')->map(fn ($a) => (int) $a)->all();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        DB::table('users')->where('id', $admin->id)->update(['is_admin' => true]);

        return $admin->fresh();
    }

    private function as(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        Sanctum::actingAs($user, ['*']);
        $response = $this->json($method, $uri, $data);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function pay(User $owner, int $payment): TestResponse
    {
        return $this->as($owner, 'POST', "/api/payments/{$payment}/pay");
    }

    public function test_paying_a_pending_payment_marks_it_paid_records_one_ledger_entry_and_mails_one_receipt(): void
    {
        $owner = User::factory()->create(['email' => 'payer@example.test']);
        $payment = $this->payment($owner);

        $this->pay($owner, $payment)->assertSuccessful();

        $this->assertSame('paid', $this->state($payment));
        $this->assertSame([4999], $this->ledger($payment));
        $sent = $this->sentMessages();
        $this->assertCount(1, $sent, 'expected exactly one receipt');
        $this->assertSame(['payer@example.test'], array_map(fn ($a) => $a->getAddress(), $sent[0]->getTo()));
    }

    public function test_paying_twice_records_one_ledger_entry_and_one_receipt(): void
    {
        $owner = User::factory()->create();
        $payment = $this->payment($owner);
        $this->pay($owner, $payment)->assertSuccessful();

        $second = $this->pay($owner, $payment)->status();

        $this->assertTrue(in_array($second, [409, 422], true) || ($second >= 200 && $second < 300), "second pay answered {$second}");
        $this->assertSame('paid', $this->state($payment));
        $this->assertSame([4999], $this->ledger($payment));
        $this->assertSame(1, $this->mailCount());
    }

    public function test_a_payment_paid_by_a_concurrent_request_after_it_was_read_is_not_paid_again(): void
    {
        $owner = User::factory()->create();
        $payment = $this->payment($owner);
        $baseline = DB::transactionLevel();
        $flag = (object) ['fired' => false];
        // The first time this request reads the payment, another request (on another server) pays it,
        // so this request holds a stale "pending" copy from then on.
        Payment::retrieved(function (Payment $model) use ($flag, $baseline, $payment) {
            if ($flag->fired || (int) $model->getKey() !== $payment || DB::transactionLevel() !== $baseline) {
                return;
            }
            $flag->fired = true;
            DB::table('payments')->where('id', $payment)->update(['state' => 'paid']);
        });

        $status = $this->pay($owner, $payment)->status();

        $this->assertLessThan(500, $status, "pay answered {$status}");
        $this->assertSame('paid', $this->state($payment));
        if ($flag->fired) {
            $this->assertTrue(in_array($status, [409, 422], true) || ($status >= 200 && $status < 300), "stale pay answered {$status}");
            $this->assertSame([], $this->ledger($payment), 'the stale request paid the payment a second time');
            $this->assertSame(0, $this->mailCount(), 'the stale request sent a second receipt');
        } else {
            $this->assertSame([4999], $this->ledger($payment));
            $this->assertSame(1, $this->mailCount());
        }
    }

    public function test_a_failing_ledger_write_leaves_the_payment_pending_and_sends_no_receipt(): void
    {
        $owner = User::factory()->create();
        $payment = $this->payment($owner);
        DB::statement("CREATE TRIGGER hidden_ledger_down BEFORE INSERT ON ledger_entries BEGIN SELECT RAISE(ABORT, 'ledger unavailable'); END");

        $response = $this->pay($owner, $payment);

        DB::statement('DROP TRIGGER hidden_ledger_down');
        $this->assertFalse($response->isSuccessful(), 'pay reported success although the ledger write failed');
        $this->assertSame(0, $this->mailCount(), 'a receipt went out for a payment that was not recorded');
        $this->assertSame('pending', $this->state($payment));
        $this->assertSame([], $this->ledger($payment));
    }

    public function test_refunding_a_paid_payment_records_a_negative_ledger_entry(): void
    {
        $payment = $this->payment(User::factory()->create(), 'paid');

        $this->as($this->admin(), 'POST', "/api/payments/{$payment}/refund")->assertSuccessful();

        $this->assertSame('refunded', $this->state($payment));
        $this->assertSame([-4999], $this->ledger($payment));
    }

    public function test_refunding_a_pending_payment_is_rejected_and_changes_nothing(): void
    {
        $payment = $this->payment(User::factory()->create());

        $status = $this->as($this->admin(), 'POST', "/api/payments/{$payment}/refund")->status();

        $this->assertContains($status, [409, 422]);
        $this->assertSame('pending', $this->state($payment));
        $this->assertSame([], $this->ledger($payment));
    }

    public function test_admin_can_set_any_known_state_by_name_without_side_effects(): void
    {
        $admin = $this->admin();
        $payment = $this->payment(User::factory()->create());

        foreach (['refunded', 'paid', 'pending'] as $state) {
            $this->as($admin, 'PUT', "/api/admin/payments/{$payment}/state", ['state' => $state])->assertSuccessful();
            $this->assertSame($state, $this->state($payment));
        }

        $this->assertSame([], $this->ledger($payment));
        $this->assertSame(0, $this->mailCount());
    }

    public function test_admin_endpoint_rejects_unknown_state_names_with_a_client_error_not_a_crash(): void
    {
        $admin = $this->admin();
        $payment = $this->payment(User::factory()->create(), 'paid');

        $inputs = ['bogus', 'App\\Models\\User', 'Spatie\\ModelStates\\State', 'stdClass', 'paid; drop', '', null];
        foreach ($inputs as $input) {
            $status = $this->as($admin, 'PUT', "/api/admin/payments/{$payment}/state", ['state' => $input])->status();

            $this->assertContains($status, [400, 422], 'state '.json_encode($input)." answered {$status}");
            $this->assertSame('paid', $this->state($payment));
        }
        $this->assertSame([], $this->ledger($payment));
    }

    public function test_non_admin_cannot_set_a_state(): void
    {
        $owner = User::factory()->create();
        $payment = $this->payment($owner);

        $status = $this->as($owner, 'PUT', "/api/admin/payments/{$payment}/state", ['state' => 'paid'])->status();

        $this->assertContains($status, [403, 404]);

        $this->assertSame('pending', $this->state($payment));
    }
}
