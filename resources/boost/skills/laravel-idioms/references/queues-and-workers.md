# Queues and workers

## Job shape

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\{Backoff, DeleteWhenMissingModels, Timeout, Tries, WithoutRelations};

#[Tries(5), Backoff([10, 60, 300]), Timeout(120), DeleteWhenMissingModels, WithoutRelations]
class ImportFeed implements ShouldQueue
{
    use Queueable;

    public function __construct(public Feed $feed) {}

    public function handle(FeedClient $client): void
    {
        // services are injected here, not in the constructor
    }

    public function failed(?Throwable $e): void
    {
        $this->feed->update(['status' => FeedStatus::Failed, 'error' => $e?->getMessage()]);
    }
}
```
- One `Illuminate\Foundation\Queue\Queueable` trait (it bundles Dispatchable, InteractsWithQueue, QueueableByBus, SerializesModels), not the four legacy traits.
- Options as class attributes from `Illuminate\Queue\Attributes`: `Tries, Backoff, Timeout, MaxExceptions, FailOnTimeout, UniqueFor, DeleteWhenMissingModels, WithoutRelations, Queue, Connection, Delay, DebounceFor`. A `public $tries` property overrides the attribute, so never set both. A method (`tries()`, `backoff()`) only for dynamic values. Pre-13 apps keep properties.
- Constructor takes models (or ids), never attribute arrays, API responses or binary blobs. `SerializesModels` stores the id and re-fetches, so the job sees current data. `#[WithoutRelations]` stops eager-loaded relations being serialized. Base64-encode any binary you must pass.
- Model may be deleted before the job runs → `#[DeleteWhenMissingModels]`; otherwise `ModelNotFoundException` fills `failed_jobs`.
- A class in `app/Jobs` is queued. Synchronous "command jobs" run with `dispatchSync` are legacy.
- Payload carries personal data or secrets → `implements ShouldBeEncrypted` (jobs, listeners, notifications). Payloads are plain JSON in Redis/DB/SQS and copied into `failed_jobs`.

## handle() must be idempotent

A job can run twice: timeout, crash, `retry_after` expiry, `queue:retry`. Uniqueness stops duplicate dispatches, not a second run.
```php
public function handle(PaymentGateway $gateway): void
{
    if ($this->order->charged_at) {
        return;
    }

    $gateway->charge($this->order->total, idempotencyKey: "order-{$this->order->id}");
    $this->order->update(['charged_at' => now()]);
}
```

## Retries and failure

- Default is **one attempt**. Every retry path counts: an exception, `release()`, a timeout, and a release by `RateLimited`, `WithoutOverlapping` or `Release` middleware. Jobs using those need a higher `#[Tries]` or `retryUntil()`, or the first release fails them.
- Separate "released for later" from "broke": many tries plus `#[MaxExceptions(3)]`. Time-bounded work uses `retryUntil(): DateTime { return now()->plus(minutes: 30); }`, which wins over tries.
- Without backoff a failed job is retried immediately. `#[Backoff([10, 60, 300])]`; the last value repeats.
- Permanent errors fail at once: `$this->fail('User token revoked.'); return;` in `handle()`, `new FailOnException([AuthorizationException::class])` middleware, or `$exceptions->dontRetry([...])` in `bootstrap/app.php`. `$this->release($delay)` only for transient conditions.
- `failed(?Throwable $e)` runs on a **fresh instance**: properties set in `handle()` are gone. `$e` may be `MaxAttemptsExceededException` or `TimeoutExceededException`. A status-tracking job must set the failed status here, or a crash leaves the record at "processing". A queued listener's `failed()` receives `($event, Throwable $e)`.

## Timeouts: three limits in order

Job `#[Timeout]` (or worker `--timeout`) **<** connection `retry_after` in `config/queue.php` (default 90) **<** Supervisor `stopwaitsecs`. A timeout at or above `retry_after` lets a second worker start the same job while the first runs. Also set the HTTP client timeout (`Http::timeout(30)->connectTimeout(5)`): PCNTL can't interrupt blocking sockets.

## Job middleware

```php
// AppServiceProvider::boot()
RateLimiter::for('klaviyo', fn (object $job) => Limit::perSecond(10)->by($job->account->id));

// job
public function middleware(): array
{
    return [
        new SkipIfBatchCancelled,
        new RateLimited('klaviyo'),                                         // RateLimitedWithRedis on Redis
        (new WithoutOverlapping($this->order->id))->releaseAfter(30)->expireAfter(180),
        Release::unless(fn () => $this->order->isPaid(), releaseAfter: 60),
    ];
}

public function retryUntil(): DateTime
{
    return now()->plus(hours: 1);
}
```
- Rate-limit external APIs with `RateLimited` + a named limiter, not `Redis::throttle` in `handle()`.
- `WithoutOverlapping` is keyed on the resource id (no key = one lock for every job of the class) and always has `expireAfter()`. `dontRelease()` drops overlapping runs; `shared()` shares the key across job classes.
- Flaky upstream: `(new ThrottlesExceptions(10, 5 * 60))->by('crm-api')->when(fn (Throwable $e) => $e instanceof HttpClientException)->deleteWhen(CustomerDeletedException::class)->report()` with `retryUntil()`. `ThrottlesExceptionsWithRedis` on Redis. `failWhen()` for permanent errors.
- Run-time guards as middleware (`Skip::when/unless` deletes, `Release::when/unless` re-queues, `SkipIfBatchCancelled`), not early returns in `handle()`.

## Uniqueness and debounce

```php
#[UniqueFor(3600)]
class UpdateSearchIndex implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function __construct(public Product $product) {}

    public function uniqueId(): string
    {
        return (string) $this->product->id;
    }
}
```
- Without `uniqueId()` the key is the class name: one product at a time across the app. Without `#[UniqueFor]` a lost lock never expires.
- `ShouldBeUniqueUntilProcessing` allows a new copy once the current one starts. Uniqueness needs a shared lock-capable cache and is ignored inside batches.
- "Only the latest of rapid dispatches runs" → `#[DebounceFor(seconds, maxWait: ...)]` + `debounceId()`. Can't combine with `ShouldBeUnique` (`LogicException`).
- Not a hand-rolled cache flag.

## Dispatching, transactions, chains, batches

```php
ProcessPodcast::dispatchIf($active, $podcast);

DB::transaction(function () use ($data) {
    $order = Order::create($data);
    SendOrderConfirmation::dispatch($order)->afterCommit();   // else the worker may run before commit
});

Bus::chain([new ProcessPodcast($p), new OptimizePodcast($p), new ReleasePodcast($p)])
    ->onQueue('podcasts')
    ->catch(fn (Throwable $e) => report($e))
    ->dispatch();

Bus::batch($chunks->map(fn ($c) => new ImportCsv($c)))       // jobs use Batchable
    ->name('Import CSV '.$upload->id)
    ->then(fn (Batch $b) => ImportFinished::dispatch($b->id))
    ->catch(fn (Batch $b, Throwable $e) => report($e))
    ->allowFailures()
    ->onQueue('imports')
    ->dispatch();
```
- `'after_commit'` defaults to false. Inside a transaction use `->afterCommit()`, `ShouldQueueAfterCommit` on listeners/jobs, `->afterCommit()` on notifications and mailables. A rollback discards the job.
- Dependent steps → chain; don't dispatch the next job from `handle()`. Only a **failure** stops a chain: `$this->delete()` does not, so use `$this->fail()` or `Skip`. `prependToChain()`/`appendToChain()` change it at runtime.
- Parallel fan-out with completion → batch (needs `make:queue-batches-table`). Callbacks are serialized: never reference `$this`. Untracked fan-out → `Bus::bulk()`.

## Queued side effects

Queue the listener, notification or mailable itself (`implements ShouldQueue`), not a job that wraps it.
```php
class InvoicePaid extends Notification implements ShouldQueue
{
    use Queueable;

    public function viaQueues(): array
    {
        return ['mail' => 'mail-queue', 'slack' => 'slack-queue'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->invoice->isPaid();   // checked when the worker sends
    }
}
```
Listeners take `#[Connection]/#[Queue]/#[Delay]/#[Tries]` and `shouldQueue($event)`; mailables `onQueue()`/`later()`.

## Routing and priority

- Named queues (`high`, `default`, `emails`, `imports`); priority is set on the worker: `queue:work redis --queue=high,default` drains in order.
- A job's default queue is set once: `Queue::route(ProcessPodcast::class, connection: 'redis', queue: 'podcasts')` in a provider (also takes interfaces, e.g. `ShouldBroadcast::class`) or `#[Queue('podcasts')]`, not `->onQueue()` at every call site.

## Workers in production

```ini
[program:laravel-worker]
command=php /var/www/app/artisan queue:work redis --queue=high,default --sleep=3 --tries=3 --max-time=3600
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=8
stopwaitsecs=3600
```
- Supervisor or systemd running `queue:work` (never `queue:listen`, never `nohup`) with `--tries`, `--max-time`, `--memory`, `--sleep` so processes recycle. `stopwaitsecs` longer than the longest job.
- Every deploy restarts workers gracefully: `php artisan queue:restart`, or `php artisan reload` (also Reverb/Octane), or `horizon:terminate`. Long-lived workers keep old code and static state until restarted.

## Horizon

- Workers are configured in `config/horizon.php` (`defaults` + a supervisor per entry under `environments`), not CLI flags. Every deployed environment has an entry, or `'*'`.
- Per supervisor: `timeout`, `tries`, `backoff`, `memory`, `maxJobs`, `maxTime`. Horizon `timeout` above any job `#[Timeout]`, below `retry_after`.
- With `balance => 'auto'`, queue order inside one supervisor is **not** priority. Strict priority → separate supervisors or `balance => false`:
```php
'production' => [
    'supervisor-high' => ['queue' => ['high'], 'maxProcesses' => 10, 'timeout' => 60],
    'supervisor-default' => ['queue' => ['default'], 'balance' => 'auto', 'autoScalingStrategy' => 'time', 'maxProcesses' => 5, 'maxTime' => 3600, 'maxJobs' => 1000],
],
```

## Housekeeping (routes/console.php)

```php
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('queue:prune-batches --hours=48 --unfinished=72 --cancelled=72')->daily();
Schedule::command('model:prune')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::job(new SyncInventory)->hourly()->withoutOverlapping()->onOneServer();
```
Without pruning, `failed_jobs` and `job_batches` grow without limit.

## Events and listeners

```php
final class ReplyWasCreated
{
    use SerializesModels;

    public function __construct(public Reply $reply) {}
}

// app/Listeners, auto-discovered by the typed handle()
final class NotifyUsersMentionedInReply implements ShouldQueue
{
    public function handle(ReplyWasCreated $event): void
    {
        $event->reply->mentionedUsers()->each->notify(new MentionNotification($event->reply));
    }
}
```
- Past-tense event names carrying models as promoted properties.
- No `EventServiceProvider` / `$listen` array: registering a discovered listener again runs it twice.

## Notifications and mail

```php
final class NewReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(User $user): array { return ['mail', 'database']; }
    public function toMail(User $user): NewReplyEmail { /* ... */ }
    public function toDatabase(User $user): array { return ['reply' => $this->reply->id]; }
}

$user->notify(new NewReplyNotification($reply));
Notification::route('mail', 'ops@example.com')->notify(new Alert($x));
```
Mailables use `envelope(): Envelope` and `content(): Content` (e.g. `new Content(markdown: 'emails.new_reply')`), not `build()`.

## Testing

Test dispatch and handling separately.
```php
Queue::fake();

$this->post('/orders', $data);

Queue::assertPushedOn('high', ShipOrder::class, fn (ShipOrder $job) => $job->order->is($order));

Bus::fake();
// ...
Bus::assertChained([ShipOrder::class, RecordShipment::class]);
Bus::assertBatched(fn (PendingBatch $b) => $b->name === 'Import CSV' && $b->jobs->count() === 10);
```
- `Queue::fake()` / `Bus::fake()` (pass a class list to fake only some) with `assertPushed`, `assertPushedOn`, `assertPushedTimes`, `assertNotPushed`, `assertChained`, `assertBatched`. Don't switch the queue to `sync` and assert side effects through HTTP.
- Unit-test `handle()`'s release/fail/delete logic:
```php
$job = (new ProcessPodcast($podcast))->withFakeQueueInteractions();

$job->handle(app(AudioProcessor::class));

$job->assertReleased(delay: 30);
$job->assertNotFailed();
```
  Also `assertFailed()`, `assertFailedWith()`, `assertDeleted()`, `assertNotReleased()`; `[$job, $batch] = $job->withFakeBatch()` for batches; `$job->assertHasChain([...])` for runtime chain changes.
- Test the `failed()` path of status-tracking jobs.

Evidence: `Illuminate/Foundation/Queue/Queueable.php`, `Foundation/Console/stubs/job.queued.stub`, `Illuminate/Queue/{Attributes,Middleware}/*`, `Queue/{Worker,QueueManager,QueueRoutes,SerializesModels,InteractsWithQueue}.php`, `Bus/{PendingBatch,UniqueLock,Batchable}.php`, `Foundation/Bus/PendingDispatch.php`, `Support/Testing/Fakes/BusFake.php`; `laravel/docs/queues.md`, `events.md`, `notifications.md`, `mail.md`, `horizon.md`, `scheduling.md`; laravel/laravel `config/queue.php`; laravel/horizon `config/horizon.php`; laravel.io `app/Jobs/`, `app/Listeners/`, `routes/console.php`.
