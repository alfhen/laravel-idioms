<?php

namespace Tests\Hidden;

use App\Jobs\SyncSupplierStock;
use App\Models\Cart;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledReportsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private function events(): array
    {
        return app(Schedule::class)->events();
    }

    private function eventFor(string $needle): Event
    {
        foreach ($this->events() as $event) {
            if (str_contains((string) $event->command, $needle) || str_contains((string) $event->description, $needle)) {
                return $event;
            }
        }
        $this->fail("no scheduled task for {$needle}");
    }

    /** UTC minutes in [from, to) at which the event's cron expression matches, as the scheduler checks it. */
    private function dueMinutes(Event $event, string $from, string $to): array
    {
        $passes = (new \ReflectionMethod($event, 'expressionPasses'));
        $due = [];
        for ($at = Carbon::parse($from, 'UTC'); $at->lt(Carbon::parse($to, 'UTC')); $at->addMinute()) {
            Carbon::setTestNow($at);
            if ($passes->invoke($event)) {
                $due[] = $at->copy();
            }
        }
        Carbon::setTestNow();

        return $due;
    }

    public function test_the_weekly_report_runs_on_monday_at_0730_copenhagen_time_in_winter_and_summer(): void
    {
        $event = $this->eventFor('reports:unshipped');

        foreach (['2026-01-11 00:00:00', '2026-07-12 00:00:00'] as $sunday) {
            $due = $this->dueMinutes($event, $sunday, Carbon::parse($sunday, 'UTC')->addDays(2)->toDateTimeString());
            $this->assertCount(1, $due, "week of {$sunday}");
            $this->assertSame('Monday 07:30', $due[0]->setTimezone('Europe/Copenhagen')->format('l H:i'));
        }
    }

    public function test_the_hourly_supplier_sync_is_queued_not_run_inside_the_scheduler(): void
    {
        $event = $this->eventFor('SyncSupplierStock');

        $this->assertMatchesRegularExpression('/^\\d+ \\* \\* \\* \\*$/', $event->expression, 'runs once an hour');
        $this->assertTrue(is_subclass_of(SyncSupplierStock::class, ShouldQueue::class));
    }

    public function test_every_task_runs_on_only_one_of_the_servers(): void
    {
        foreach (['reports:unshipped', 'carts:prune-abandoned', 'SyncSupplierStock'] as $needle) {
            $this->assertTrue($this->eventFor($needle)->onOneServer, "{$needle} would run on every server");
        }
    }

    public function test_a_crashed_run_does_not_block_a_task_for_a_day(): void
    {
        foreach ($this->events() as $event) {
            if ($event->withoutOverlapping) {
                $this->assertLessThanOrEqual(180, $event->expiresAt, "{$event->command}{$event->description} keeps its overlap lock for {$event->expiresAt} minutes");
            }
        }
        $job = new SyncSupplierStock;
        $middleware = method_exists($job, 'middleware') ? $job->middleware() : [];
        foreach ($middleware as $item) {
            if ($item instanceof \Illuminate\Queue\Middleware\WithoutOverlapping) {
                $this->assertGreaterThan(0, $item->expiresAfter, 'job overlap lock never expires');
            }
        }
        if ($job instanceof \Illuminate\Contracts\Queue\ShouldBeUnique) {
            $uniqueFor = method_exists($job, 'uniqueFor') ? $job->uniqueFor() : ($job->uniqueFor ?? null);
            if ($uniqueFor === null) {
                $attribute = (new \ReflectionClass($job))->getAttributes(\Illuminate\Queue\Attributes\UniqueFor::class)[0] ?? null;
                $uniqueFor = $attribute?->newInstance()->uniqueFor;
            }
            $this->assertNotEmpty($uniqueFor, 'unique lock never expires');
            $this->assertLessThanOrEqual(86400, $uniqueFor);
        }
    }

    public function test_a_slow_supplier_sync_cannot_run_twice_at_the_same_time(): void
    {
        $job = new SyncSupplierStock;
        $middleware = method_exists($job, 'middleware') ? $job->middleware() : [];
        $guarded = $job instanceof \Illuminate\Contracts\Queue\ShouldBeUnique
            || collect($middleware)->contains(fn ($m) => $m instanceof \Illuminate\Queue\Middleware\WithoutOverlapping);

        $this->assertTrue($guarded, 'nothing stops a second sync starting while a 40-minute one is still running');
    }

    public function test_two_scheduler_runs_in_the_same_minute_dispatch_the_hourly_sync_once(): void
    {
        Queue::fake();
        $minute = (int) explode(' ', $this->eventFor('SyncSupplierStock')->expression)[0];
        $this->travelTo(Carbon::parse('2026-03-04 10:00:00', 'UTC')->setMinute($minute));

        Artisan::call('schedule:run');
        Artisan::call('schedule:run');

        Queue::assertPushed(SyncSupplierStock::class, 1);
    }

    public function test_the_nightly_cleanup_runs_exactly_once_a_night_across_daylight_saving_changes(): void
    {
        $event = $this->eventFor('carts:prune-abandoned');

        foreach (['2026-03-28 12:00:00', '2026-10-24 12:00:00'] as $saturday) {
            $due = $this->dueMinutes($event, $saturday, Carbon::parse($saturday, 'UTC')->addDays(2)->toDateTimeString());
            $this->assertCount(2, $due, "two nights from {$saturday} UTC should mean two runs");
        }
    }

    public function test_the_cleanup_command_deletes_every_abandoned_cart_and_keeps_recent_ones(): void
    {
        $old = now()->subDays(31);
        $rows = [];
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = Cart::factory()->make(['created_at' => $old, 'updated_at' => $old])->getAttributes();
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('carts')->insert($chunk);
        }
        $recent = Cart::factory()->count(5)->create(['updated_at' => now()->subDays(29)]);

        $this->assertSame(0, Artisan::call('carts:prune-abandoned'));

        $this->assertSame(5, Cart::count());
        $this->assertEqualsCanonicalizing($recent->modelKeys(), Cart::pluck('id')->all());
    }
}
