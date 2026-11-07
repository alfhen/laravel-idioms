<?php

namespace Tests\Hidden;

use App\Actions\StartStatementRun;
use App\Mail\StatementReady;
use App\Mail\StatementRunSummary;
use App\Models\BillingAccount;
use App\Models\StatementRun;
use App\Services\StatementGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class StatementBatchHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false,
            'queue.batching.database' => config('database.default'), 'queue.failed.database' => config('database.default'),
            'billing.finance_email' => 'finance@example.test']);
        foreach (['sqs', 'redis'] as $name) {
            config(["queue.connections.$name" => config('queue.connections.database')]);
        }
        Storage::fake('local');
        Mail::fake();
    }

    /** Works every queue the app pushed to until nothing is left, moving the clock past any backoff. */
    private function drainQueues(): void
    {
        for ($round = 0; $round < 40; $round++) {
            $queues = DB::table('jobs')->distinct()->pluck('queue')->all();
            if ($queues === []) {
                return;
            }
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => implode(',', $queues),
                '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 3]);
            $this->travel(1)->hours();
        }
        $this->fail('queue never drained');
    }

    private function mailsTo(string $class, string $email): int
    {
        $match = fn ($mail) => $mail->hasTo($email);

        return Mail::sent($class, $match)->count() + Mail::queued($class, $match)->count();
    }

    private function statusOf(StatementRun $run): string
    {
        $status = $run->fresh()->status;

        return $status instanceof \BackedEnum ? $status->value : (string) $status;
    }

    public function test_every_account_gets_a_stored_statement_and_the_run_completes(): void
    {
        $accounts = BillingAccount::factory()->count(5)->create(['active' => true]);
        BillingAccount::factory()->create(['active' => false, 'email' => 'inactive@example.test']);

        $run = app(StartStatementRun::class)();
        $this->drainQueues();

        foreach ($accounts as $account) {
            Storage::disk('local')->assertExists("statements/{$run->id}/{$account->id}.pdf");
            $this->assertSame(1, $this->mailsTo(StatementReady::class, $account->email));
        }
        $this->assertSame(0, $this->mailsTo(StatementReady::class, 'inactive@example.test'));
        $this->assertSame('completed', $this->statusOf($run));
        $this->assertSame(0, (int) $run->fresh()->failed_accounts);
        $this->assertNotNull($run->fresh()->completed_at);
        $this->assertSame(1, $this->mailsTo(StatementRunSummary::class, 'finance@example.test'));
    }

    public function test_one_broken_account_does_not_stop_the_others_and_the_run_still_finishes(): void
    {
        $accounts = BillingAccount::factory()->count(6)->create(['active' => true]);
        $broken = $accounts[2];
        $this->app->instance(StatementGenerator::class, new class($broken->id) extends StatementGenerator
        {
            public function __construct(private int $brokenId) {}

            public function generate(BillingAccount $account): string
            {
                if ($account->id === $this->brokenId) {
                    throw new RuntimeException('Template error');
                }

                return '%PDF-1.4 test';
            }
        });

        $run = app(StartStatementRun::class)();
        $this->drainQueues();

        foreach ($accounts as $account) {
            if ($account->is($broken)) {
                Storage::disk('local')->assertMissing("statements/{$run->id}/{$account->id}.pdf");
                $this->assertSame(0, $this->mailsTo(StatementReady::class, $account->email));

                continue;
            }
            Storage::disk('local')->assertExists("statements/{$run->id}/{$account->id}.pdf");
            $this->assertSame(1, $this->mailsTo(StatementReady::class, $account->email));
        }
        $this->assertSame('completed_with_errors', $this->statusOf($run));
        $this->assertSame(1, (int) $run->fresh()->failed_accounts);
        $this->assertNotNull($run->fresh()->completed_at);
        $this->assertSame(1, $this->mailsTo(StatementRunSummary::class, 'finance@example.test'));
    }

    public function test_starting_a_run_returns_before_any_statement_is_generated(): void
    {
        BillingAccount::factory()->count(3)->create(['active' => true]);

        $run = app(StartStatementRun::class)();

        $this->assertSame('running', $this->statusOf($run));
        $this->assertEmpty(Storage::disk('local')->allFiles('statements'));
        $this->assertSame(0, Mail::sent(StatementReady::class)->count() + Mail::queued(StatementReady::class)->count());
    }

    public function test_statement_generation_does_not_run_on_the_default_queue(): void
    {
        BillingAccount::factory()->count(3)->create(['active' => true]);
        $queues = new \ArrayObject;
        $current = new \ArrayObject(['queue' => null]);
        \Illuminate\Support\Facades\Queue::before(fn ($event) => $current['queue'] = $event->job->getQueue());
        $this->app->instance(StatementGenerator::class, new class($queues, $current) extends StatementGenerator
        {
            public function __construct(private \ArrayObject $queues, private \ArrayObject $current) {}

            public function generate(BillingAccount $account): string
            {
                $this->queues[] = $this->current['queue'];

                return '%PDF-1.4 test';
            }
        });

        app(StartStatementRun::class)();
        $this->drainQueues();

        $this->assertCount(3, $queues, 'each statement is generated in a queued job');
        $this->assertNotContains('default', $queues->getArrayCopy());
        $this->assertNotContains(null, $queues->getArrayCopy());
    }
}
