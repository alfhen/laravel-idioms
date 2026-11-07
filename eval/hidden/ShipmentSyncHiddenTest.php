<?php

namespace Tests\Hidden;

use App\Jobs\SendOrderToWarehouse;
use App\Mail\ShipmentCreated;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class ShipmentSyncHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.warehouse.url' => 'https://warehouse.example.test/api', 'services.warehouse.token' => 'test-token']);
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function paidOrder(): Order
    {
        return Order::factory()->create([
            'customer_email' => 'buyer@example.test',
            'shipping_address' => '1 Main Street, Copenhagen',
            'total_cents' => 12500,
            'status' => 'paid',
            'shipment_status' => 'pending',
            'shipment_reference' => null,
            'shipment_failure_reason' => null,
        ]);
    }

    private function postCount(): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'POST')->count();
    }

    private function mailCount(): int
    {
        return Mail::sent(ShipmentCreated::class)->count() + Mail::queued(ShipmentCreated::class)->count();
    }

    /** Runs the job's handle() without middleware; returns [threw, released, failed]. */
    private function runHandle(SendOrderToWarehouse $job): array
    {
        $job->withFakeQueueInteractions();
        $threw = false;
        try {
            app()->call([$job, 'handle']);
        } catch (Throwable) {
            $threw = true;
        }

        return [$threw, $job->job->isReleased(), $job->job->hasFailed()];
    }

    public function test_marking_an_order_paid_queues_one_shipment_job(): void
    {
        Queue::fake();
        $order = Order::factory()->create(['status' => 'pending', 'shipment_status' => null, 'customer_email' => 'buyer@example.test', 'shipping_address' => 'x', 'total_cents' => 100]);

        $order->markAsPaid();

        $this->assertSame('paid', $order->fresh()->status instanceof \BackedEnum ? $order->fresh()->status->value : $order->fresh()->status);
        Queue::assertPushed(SendOrderToWarehouse::class, 1);
    }

    public function test_running_the_job_twice_creates_one_shipment_and_one_email(): void
    {
        Http::fake(['warehouse.example.test/*' => Http::response(['id' => 'SHP-1'], 201)]);
        $order = $this->paidOrder();

        foreach ([1, 2] as $attempt) {
            try {
                dispatch_sync(new SendOrderToWarehouse($order->fresh()));
            } catch (Throwable) {
            }
        }

        $order->refresh();
        $this->assertSame(1, $this->postCount());
        $this->assertSame('SHP-1', $order->shipment_reference);
        $this->assertSame('sent', $order->shipment_status instanceof \BackedEnum ? $order->shipment_status->value : $order->shipment_status);
        $this->assertSame(1, $this->mailCount());
    }

    public function test_invalid_address_marks_the_shipment_failed_without_retrying(): void
    {
        Http::fake(['warehouse.example.test/*' => Http::response(['message' => 'Invalid address'], 422)]);
        $order = $this->paidOrder();

        [, $released] = $this->runHandle(new SendOrderToWarehouse($order));
        $order->refresh();
        $failedViaHandler = ($order->shipment_status instanceof \BackedEnum ? $order->shipment_status->value : $order->shipment_status) === 'failed';
        if (! $failedViaHandler) {
            // A design that fails the job (fail() / FailOnException) records the reason in failed(); run the queue path.
            try {
                dispatch_sync(new SendOrderToWarehouse($order->fresh()));
            } catch (Throwable) {
            }
            $order->refresh();
        }

        $this->assertFalse($released, 'a 422 must not be retried');
        $this->assertSame('failed', $order->shipment_status instanceof \BackedEnum ? $order->shipment_status->value : $order->shipment_status);
        $this->assertNotEmpty($order->shipment_failure_reason);
        $this->assertSame(0, $this->mailCount());
    }

    public static function transientStatuses(): array
    {
        return ['rate limited' => [429], 'server error' => [503]];
    }

    #[DataProvider('transientStatuses')]
    public function test_transient_errors_are_retried_not_swallowed(int $status): void
    {
        Http::fake(['warehouse.example.test/*' => Http::response(['message' => 'try later'], $status)]);
        $order = $this->paidOrder();

        [$threw, $released, $failed] = $this->runHandle(new SendOrderToWarehouse($order));

        $this->assertTrue($threw || $released, 'a transient error must throw or release so the queue retries it');
        $this->assertFalse($failed, 'a transient error must not fail the job permanently');
        $this->assertNotSame('sent', $order->fresh()->shipment_status instanceof \BackedEnum ? $order->fresh()->shipment_status->value : $order->fresh()->shipment_status);
        $this->assertSame(0, $this->mailCount());
    }

    public function test_a_job_that_finally_fails_records_failed_status_and_reason(): void
    {
        $order = $this->paidOrder();
        $job = new SendOrderToWarehouse($order);

        $this->assertTrue(method_exists($job, 'failed'), 'final failures must be recorded on the order');
        $job->failed(new \RuntimeException('Warehouse unreachable'));

        $order->refresh();
        $this->assertSame('failed', $order->shipment_status instanceof \BackedEnum ? $order->shipment_status->value : $order->shipment_status);
        $this->assertNotEmpty($order->shipment_failure_reason);
    }

    public function test_an_order_cancelled_before_the_job_runs_is_never_shipped(): void
    {
        Http::fake(['warehouse.example.test/*' => Http::response(['id' => 'SHP-2'], 201)]);
        $order = $this->paidOrder();
        $job = new SendOrderToWarehouse($order);
        $order->delete();

        try {
            dispatch_sync($job);
        } catch (Throwable) {
        }

        $this->assertSame(0, $this->postCount());
        $this->assertSame(0, $this->mailCount());
    }
}
