<?php

namespace Tests\Hidden;

use App\Jobs\PushStockAllocations;
use App\Models\StockAllocation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

class StockSyncDispatcherHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function readyRows(int $count, string $prefix = 'SKU'): array
    {
        $at = now()->subHour();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['sku' => "{$prefix}-{$i}", 'quantity' => 5, 'sync_started_at' => null, 'synced_at' => null, 'disabled_at' => null, 'created_at' => $at, 'updated_at' => $at];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('stock_allocations')->insert($chunk);
        }

        return StockAllocation::query()->where('sku', 'like', "{$prefix}-%")->pluck('id')->all();
    }

    /** @return list<int> every id pushed, one entry per push */
    private function pushedIds(): array
    {
        return Queue::pushed(PushStockAllocations::class)->flatMap(fn (PushStockAllocations $job) => $job->allocationIds)->map(fn ($id) => (int) $id)->values()->all();
    }

    public function test_every_ready_row_is_dispatched_exactly_once_in_groups_of_at_most_100(): void
    {
        Queue::fake();
        $ids = $this->readyRows(1050);

        $this->assertSame(0, Artisan::call('stock:dispatch-sync'));
        $this->travel(1)->minutes();
        $this->assertSame(0, Artisan::call('stock:dispatch-sync'));

        $pushed = $this->pushedIds();
        $this->assertEqualsCanonicalizing($ids, $pushed, 'every ready row exactly once across two runs');
        Queue::pushed(PushStockAllocations::class)->each(fn ($job) => $this->assertLessThanOrEqual(100, count($job->allocationIds)));
    }

    public function test_synced_and_disabled_rows_are_not_dispatched_but_changed_rows_are(): void
    {
        Queue::fake();
        $at = now()->subHour();
        $synced = StockAllocation::factory()->create(['sku' => 'S1', 'synced_at' => $at, 'updated_at' => $at->copy()->subMinute(), 'sync_started_at' => null, 'disabled_at' => null]);
        $changed = StockAllocation::factory()->create(['sku' => 'S2', 'synced_at' => $at, 'updated_at' => $at->copy()->addMinute(), 'sync_started_at' => null, 'disabled_at' => null]);
        $disabled = StockAllocation::factory()->create(['sku' => 'S3', 'synced_at' => null, 'disabled_at' => $at, 'sync_started_at' => null]);

        Artisan::call('stock:dispatch-sync');

        $this->assertSame([$changed->id], $this->pushedIds());
    }

    public function test_an_abandoned_claim_is_picked_up_again_but_a_fresh_claim_is_not(): void
    {
        Queue::fake();
        $abandoned = StockAllocation::factory()->create(['sku' => 'A1', 'synced_at' => null, 'disabled_at' => null, 'sync_started_at' => now()->subMinutes(30)]);
        $inFlight = StockAllocation::factory()->create(['sku' => 'A2', 'synced_at' => null, 'disabled_at' => null, 'sync_started_at' => now()->subMinutes(2)]);

        Artisan::call('stock:dispatch-sync');

        $this->assertSame([$abandoned->id], $this->pushedIds());
    }

    public function test_the_job_marks_sent_rows_synced_and_disables_missing_skus(): void
    {
        Http::fake(['storefront.example.test/*' => Http::response(['missing' => ['M-1']], 200)]);
        $ids = $this->readyRows(3, 'M');
        StockAllocation::query()->whereKey($ids)->update(['sync_started_at' => now()]);

        dispatch_sync(new PushStockAllocations($ids));

        $rows = StockAllocation::query()->whereKey($ids)->get()->keyBy('sku');
        $this->assertNotNull($rows['M-0']->synced_at);
        $this->assertNull($rows['M-0']->sync_started_at);
        $this->assertNotNull($rows['M-1']->disabled_at);
        $this->assertNull($rows['M-2']->disabled_at);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'storefront.example.test/api/stock'));
    }

    public function test_rows_synced_by_the_job_are_not_dispatched_again_until_they_change(): void
    {
        Http::fake(['storefront.example.test/*' => Http::response(['missing' => []], 200)]);
        $ids = $this->readyRows(3, 'D');
        Queue::fake();
        Artisan::call('stock:dispatch-sync');
        Queue::pushed(PushStockAllocations::class)->each(fn ($job) => app()->call([(new PushStockAllocations($job->allocationIds))->withFakeQueueInteractions(), 'handle']));
        $this->assertSame(3, StockAllocation::query()->whereKey($ids)->whereNotNull('synced_at')->count());

        Queue::fake();
        $this->travel(1)->minutes();
        Artisan::call('stock:dispatch-sync');
        $this->assertSame([], $this->pushedIds());

        $this->travel(1)->minutes();
        StockAllocation::query()->whereKey($ids[0])->first()->update(['quantity' => 9]);
        Artisan::call('stock:dispatch-sync');
        $this->assertSame([(int) $ids[0]], $this->pushedIds());
    }

    public function test_a_rate_limited_response_is_retried_not_swallowed_or_marked_synced(): void
    {
        Http::fake(['storefront.example.test/*' => Http::response(['message' => 'slow down'], 429)]);
        $ids = $this->readyRows(2, 'R');
        StockAllocation::query()->whereKey($ids)->update(['sync_started_at' => now()]);   // as the dispatcher's claim would
        $job = (new PushStockAllocations($ids))->withFakeQueueInteractions();

        $threw = false;
        try {
            app()->call([$job, 'handle']);
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw || $job->job->isReleased(), 'a 429 must throw or release so the job is retried');
        $this->assertFalse($job->job->hasFailed(), 'a 429 must not fail the job permanently');
        $this->assertSame(0, StockAllocation::query()->whereKey($ids)->whereNotNull('synced_at')->count());
    }

    public function test_rows_of_a_job_that_gives_up_are_dispatched_again_by_the_next_run(): void
    {
        Queue::fake();
        $ids = $this->readyRows(3, 'F');
        Artisan::call('stock:dispatch-sync');
        $job = Queue::pushed(PushStockAllocations::class)->first();

        $this->assertTrue(method_exists($job, 'failed'), 'a job that gives up must release its rows');
        $job->failed(new \RuntimeException('storefront down'));
        Queue::fake();
        $this->travel(1)->minutes();
        Artisan::call('stock:dispatch-sync');

        $this->assertEqualsCanonicalizing($ids, $this->pushedIds());
    }

    public function test_it_is_scheduled_every_minute_on_one_server_with_a_short_lived_overlap_lock(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'stock:dispatch-sync'));

        $this->assertNotNull($event, 'stock:dispatch-sync is not scheduled');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->onOneServer, 'three containers would each dispatch');
        if ($event->withoutOverlapping) {
            $this->assertLessThanOrEqual(30, $event->expiresAt, 'a killed container would block the dispatcher for '.$event->expiresAt.' minutes');
        }
    }
}
