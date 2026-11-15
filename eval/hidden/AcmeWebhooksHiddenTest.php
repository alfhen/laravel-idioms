<?php

namespace Tests\Hidden;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AcmeWebhooksHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_hidden_test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.debug' => false,
            'services.acme.webhook_secret' => self::SECRET,
            'services.acme.api_key' => 'sk_test_hidden',
        ]);
        Http::preventStrayRequests();
    }

    private function fakeApi(string $status = 'succeeded'): void
    {
        Http::fake(['api.acmepay.test/*' => Http::response([
            'id' => 'pay_1', 'status' => $status, 'amount' => 2500, 'currency' => 'EUR', 'card_last4' => '4242',
        ])]);
    }

    private function event(string $id, string $type = 'payment.succeeded', string $at = '2026-11-14T10:00:00Z', array $data = []): string
    {
        return json_encode([
            'id' => $id,
            'type' => $type,
            'occurred_at' => $at,
            'data' => $data + ['payment_id' => 'pay_1', 'amount' => 2500, 'currency' => 'EUR'],
        ]);
    }

    private function deliver(string $raw, ?string $signature = null, ?string $secret = null): TestResponse
    {
        $signature ??= base64_encode(hash_hmac('sha256', $raw, $secret ?? self::SECRET, true));

        return $this->call('POST', '/webhooks/acme', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ACME_SIGNATURE' => $signature,
        ], $raw);
    }

    private function deliverUnsigned(string $raw): TestResponse
    {
        return $this->call('POST', '/webhooks/acme', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $raw);
    }

    private function ledger(): array
    {
        $rows = DB::table('ledger_entries')->where('payment_id', 'pay_1')->get();

        return [$rows->count(), (int) $rows->sum('amount')];
    }

    private function assertNoEffect(): void
    {
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('ledger_entries')->count());
    }

    /** Delivers while jobs are only recorded, then runs every recorded job on the sync queue, including jobs those jobs dispatch. */
    private function deliverBeforeProcessing(callable $deliveries): void
    {
        $manager = Queue::getFacadeRoot();
        $fake = Queue::fake();
        $deliveries();
        $jobs = collect($fake->pushedJobs())->flatten(1)->pluck('job')->all();
        Queue::swap($manager);

        foreach ($jobs as $job) {
            $manager->connection('sync')->push($job);
        }
    }

    public function test_a_signed_payment_event_is_applied(): void
    {
        $this->fakeApi();

        $this->deliver($this->event('evt_1'))->assertSuccessful();

        $payment = DB::table('payments')->where('acme_id', 'pay_1')->first();
        $this->assertNotNull($payment);
        $this->assertSame('succeeded', $payment->status);
        $this->assertSame('4242', (string) $payment->card_last4);
        $this->assertSame([1, 2500], $this->ledger());
    }

    public function test_successive_events_for_one_payment_are_each_applied(): void
    {
        $this->fakeApi('refunded');

        $this->deliver($this->event('evt_1', 'payment.succeeded', '2026-11-14T10:00:00Z'))->assertSuccessful();
        $this->deliver($this->event('evt_2', 'payment.refunded', '2026-11-14T11:00:00Z'))->assertSuccessful();

        $this->assertSame('refunded', DB::table('payments')->where('acme_id', 'pay_1')->value('status'));
        $this->assertSame([2, 0], $this->ledger());
    }

    public function test_the_signature_is_checked_against_the_exact_bytes_sent(): void
    {
        $this->fakeApi();
        $raw = <<<'JSON'
        {
            "id": "evt_raw",
            "type": "payment.succeeded",
            "occurred_at": "2026-11-14T10:00:00Z",
            "data": {
                "payment_id": "pay_1",
                "amount": 2500,
                "currency": "EUR",
                "fee_rate": 10.50,
                "description": "  Order 1001  ",
                "statement_descriptor": ""
            }
        }
        JSON;

        $this->deliver($raw)->assertSuccessful();

        $this->assertSame([1, 2500], $this->ledger());
    }

    public function test_a_tampered_body_is_rejected_without_effect(): void
    {
        $this->fakeApi();
        $signed = $this->event('evt_1');
        $signature = base64_encode(hash_hmac('sha256', $signed, self::SECRET, true));

        $this->deliver(str_replace('2500', '9500', $signed), $signature)->assertUnauthorized();

        $this->assertNoEffect();
    }

    public function test_a_missing_signature_is_rejected_without_effect(): void
    {
        $this->fakeApi();

        $this->deliverUnsigned($this->event('evt_1'))->assertUnauthorized();

        $this->assertNoEffect();
    }

    public function test_an_empty_configured_secret_accepts_nothing(): void
    {
        $this->fakeApi();
        config(['services.acme.webhook_secret' => '']);

        $status = $this->deliver($this->event('evt_1'), null, '')->status();

        $this->assertTrue($status === 401 || $status >= 500, "expected 401 or 5xx, got {$status}");
        $this->assertNoEffect();
    }

    public function test_the_delivery_is_acknowledged_before_processing(): void
    {
        Queue::fake();
        Http::fake();

        $this->deliver($this->event('evt_1'))->assertSuccessful();

        $queued = collect(Queue::pushedJobs())->flatten(1)->pluck('job')
            ->reject(fn ($job) => is_object($job) && ($job->connection ?? null) === 'sync');
        $this->assertNotEmpty($queued, 'nothing was queued');
        Http::assertNothingSent();
    }

    public function test_duplicate_deliveries_before_processing_are_applied_once(): void
    {
        $this->fakeApi();

        $this->deliverBeforeProcessing(function () {
            $this->deliver($this->event('evt_1'))->assertSuccessful();
            $this->deliver($this->event('evt_1'))->assertSuccessful();
        });

        $this->assertSame([1, 2500], $this->ledger());
    }

    public function test_a_redelivery_after_processing_is_applied_once(): void
    {
        $this->fakeApi();

        $this->deliver($this->event('evt_1'))->assertSuccessful();
        $this->deliver($this->event('evt_1'))->assertSuccessful();

        $this->assertSame([1, 2500], $this->ledger());
    }

    public function test_a_redelivery_after_a_deploy_cleared_the_cache_is_applied_once(): void
    {
        $this->fakeApi();

        $this->deliver($this->event('evt_1'))->assertSuccessful();
        Cache::flush();
        $this->deliver($this->event('evt_1'))->assertSuccessful();

        $this->assertSame([1, 2500], $this->ledger());
    }

    public function test_an_older_event_arriving_late_does_not_overwrite_the_newer_status(): void
    {
        $this->fakeApi('refunded');

        $this->deliver($this->event('evt_2', 'payment.refunded', '2026-11-14T11:00:00Z'))->assertSuccessful();
        $this->deliver($this->event('evt_1', 'payment.succeeded', '2026-11-14T10:00:00Z'))->assertSuccessful();

        $this->assertSame('refunded', DB::table('payments')->where('acme_id', 'pay_1')->value('status'));
    }

    public function test_an_unknown_event_type_is_acknowledged_and_ignored(): void
    {
        $this->fakeApi();
        $raw = json_encode([
            'id' => 'evt_9', 'type' => 'customer.updated', 'occurred_at' => '2026-11-14T10:00:00Z',
            'data' => ['customer_id' => 'cus_1', 'email_changed' => true],
        ]);

        $this->deliver($raw)->assertSuccessful();

        $this->assertNoEffect();
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_the_endpoint_accepts_deliveries_without_a_csrf_token(): void
    {
        $this->fakeApi();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->deliver($this->event('evt_1'))->assertSuccessful();

        $this->assertSame([1, 2500], $this->ledger());
    }
}
