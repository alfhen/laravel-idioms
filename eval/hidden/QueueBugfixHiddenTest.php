<?php

namespace Tests\Hidden;

use App\Jobs\SyncSubscriberToCrm;
use App\Models\Subscriber;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

class QueueBugfixHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function subscribers(int $count, array $attributes = []): array
    {
        $at = now()->subDay();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['email' => "  User{$i}@Example.COM ", 'name' => "User {$i}", 'email_normalized' => false, 'created_at' => $at, 'updated_at' => $at, ...$attributes];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('subscribers')->insert($chunk);
        }

        return Subscriber::query()->pluck('id')->all();
    }

    /** @return list<int> one subscriber id per pushed job */
    private function pushedIds(): array
    {
        return Queue::pushed(SyncSubscriberToCrm::class)->map(fn ($job) => (int) $job->subscriber->id)->values()->all();
    }

    public function test_a_successful_sync_still_marks_the_subscriber_synced(): void
    {
        Http::fake(['crm.example.test/*' => Http::response(['id' => 'c-1'], 201)]);
        $subscriber = Subscriber::factory()->create();

        try {
            dispatch_sync(new SyncSubscriberToCrm($subscriber));
        } catch (Throwable) {
        }

        $this->assertNotNull($subscriber->fresh()->crm_synced_at);
    }

    public function test_a_crm_outage_is_retried_by_the_queue_instead_of_being_swallowed(): void
    {
        Http::fake(['crm.example.test/*' => Http::response(['message' => 'down'], 503)]);
        $subscriber = Subscriber::factory()->create();
        $job = (new SyncSubscriberToCrm($subscriber))->withFakeQueueInteractions();

        $threw = false;
        try {
            app()->call([$job, 'handle']);
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw || $job->job->isReleased(), 'the failure must reach the queue (retry, then failed_jobs)');
        $this->assertNull($subscriber->fresh()->crm_synced_at);
    }

    public function test_the_dispatcher_queues_each_unsynced_subscriber_once_while_the_queue_is_backed_up(): void
    {
        Queue::fake();
        $ids = $this->subscribers(250);

        Artisan::call('crm:dispatch-sync');
        $this->assertEqualsCanonicalizing($ids, $this->pushedIds(), 'one run queues every pending subscriber');

        foreach ([2, 3] as $tick) {
            $this->travel(1)->minutes();
            Artisan::call('crm:dispatch-sync');
        }

        $this->assertEqualsCanonicalizing($ids, $this->pushedIds(), 'later runs do not queue them again');
    }

    public function test_a_subscriber_whose_sync_finally_failed_is_queued_again_later(): void
    {
        Queue::fake();
        $subscriber = Subscriber::factory()->create();
        Artisan::call('crm:dispatch-sync');
        $job = Queue::pushed(SyncSubscriberToCrm::class)->first();
        $this->assertNotNull($job);
        if (method_exists($job, 'failed')) {
            $job->failed(new \RuntimeException('CRM down for an hour'));
        }

        Queue::fake();
        $this->travel(3)->hours();
        Artisan::call('crm:dispatch-sync');

        $this->assertSame([(int) $subscriber->id], $this->pushedIds());
    }

    public function test_the_nightly_normalisation_handles_every_row(): void
    {
        $this->subscribers(450);

        $this->assertSame(0, Artisan::call('subscribers:normalize-emails'));

        $this->assertSame(0, Subscriber::query()->where('email_normalized', false)->count());
        $this->assertSame(450, Subscriber::query()->where('email', 'like', 'user%@example.com')->count());
    }

    public function test_locks_left_by_a_killed_process_expire_quickly(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, 'crm:dispatch-sync') && $event->withoutOverlapping) {
                $this->assertLessThanOrEqual(30, $event->expiresAt, 'the dispatcher stays blocked for '.$event->expiresAt.' minutes');
            }
        }

        $job = new SyncSubscriberToCrm(Subscriber::factory()->create());
        foreach ($job->middleware() as $middleware) {
            if ($middleware instanceof WithoutOverlapping) {
                $this->assertGreaterThan(0, $middleware->expiresAfter, 'the per-subscriber lock never expires');
            }
        }
    }
}
