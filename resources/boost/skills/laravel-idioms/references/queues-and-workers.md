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
- Options as class attributes from `Illuminate\Queue\Attributes`: `Tries, Backoff, Timeout, MaxExceptions, CountCrashesAsExceptions, FailOnTimeout, UniqueFor, DeleteWhenMissingModels, WithoutRelations, Queue, Connection, Delay, DebounceFor`. They target the class only (`WithoutRelations` also a property), never a method. A `public $tries` property overrides the attribute, so never set both. A method (`tries()`, `backoff()`) only for dynamic values. Pre-13 apps keep properties.
- Constructor takes models (or ids), never attribute arrays, API responses or binary blobs. `#[WithoutRelations]` stops eager-loaded relations being serialized. Base64-encode any binary you must pass.
- A class in `app/Jobs` is queued. Synchronous "command jobs" run with `dispatchSync` are legacy. Closure jobs (`dispatch(fn () => ...)`) get only `->name()`/`->catch()` and worker defaults: anything needing tries, timeout, middleware or `failed()` is a class.
- Payload carries personal data or secrets → `implements ShouldBeEncrypted` (jobs, listeners, notifications). Payloads are plain JSON in Redis/DB/SQS and copied into `failed_jobs`. Only the serialized command is encrypted; every worker needs the same `APP_KEY`.

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
- Key writes on a natural key (`firstOrCreate`/`updateOrCreate`, unique index), not a blind `create()`.
- The externally visible effect (mail, payment, coupon) goes last, behind a persisted marker.

## Failures: throw, don't log-and-return

```php
// bad: the job "succeeds": no retry, no failed_jobs row, no failed(), no alert
try { $client->createShipment($this->order); } catch (RequestException $e) { Log::error($e->getMessage()); return; }
// good: already done → return; broken → throw → backoff → retry → failed()
if ($this->order->shipment()->exists()) { return; }
$this->order->shipment()->create(['reference' => $client->createShipment($this->order)]);
```
- Nothing to do or already done → `return`. Something failed → let it throw (`Http::timeout(10)->throw()`), or `$this->fail($e); return;` when retrying can't help.
- A `handle()` that returns normally is deleted as a success, also after catching and storing "failed" on a row. Catch only to add context and rethrow, or to call `fail()`.
- Remote reads use `Http::timeout(30)->retry(2, 200)->get($url)->throw()`, never `file_get_contents()` on a URL.

## Retries and failure

- Default is **one attempt**. Every retry path counts: an exception, `release()`, a timeout, and a release by `RateLimited`, `WithoutOverlapping` or `Release` middleware. Jobs using those need a higher `#[Tries]` or `retryUntil()` on the job, whatever `--tries` the worker runs with, or the first release fails them.
- Separate "released for later" from "broke": many tries plus `#[MaxExceptions(3)]`. Its counter lives in the cache, and exceptions swallowed by `ThrottlesExceptions` don't count, so bound those with `retryUntil()`. Time-bounded work uses `retryUntil(): DateTime { return now()->plus(minutes: 30); }`, which wins over tries.
- A killed attempt (OOM, SIGKILL) never counts toward `#[MaxExceptions]`. Jobs that can crash a worker add `#[CountCrashesAsExceptions]`; it does nothing without `MaxExceptions` and a cache store.
- Without backoff a failed job is retried immediately. `#[Backoff([10, 60, 300])]` on the class; the last value repeats.
- Permanent errors fail at once: `$this->fail('User token revoked.'); return;` in `handle()`, `new FailOnException([AuthorizationException::class])` middleware, or `$exceptions->dontRetry([...])` in `bootstrap/app.php`. `$this->release($delay)` only for transient conditions.
- A timeout uses an attempt and retries if tries remain. Non-idempotent long jobs add `#[FailOnTimeout]`. Never `#[Timeout(0)]`: no alarm is set and a hung job runs forever.
- `failed(?Throwable $e)` runs on a **fresh instance**: properties set in `handle()` are gone. `$e` may be `MaxAttemptsExceededException` or `TimeoutExceededException`. A queued listener's `failed()` receives `($event, Throwable $e)`.

## Serialization pitfalls

- `SerializesModels` stores the id and re-fetches **without global scopes**: a soft-deleted model comes back. Check `trashed()`/status in `handle()`; `#[DeleteWhenMissingModels]` only covers hard-deleted rows (otherwise `ModelNotFoundException` fills `failed_jobs`).
- A collection property silently drops models deleted meanwhile. When exactness matters, pass ids and re-query.
- The job sees the database row, not your in-memory object. Persist first:
```php
// bad: the job loads the old status
$order->status = OrderStatus::Paid;
ShipOrder::dispatch($order);
// good
$order->update(['status' => OrderStatus::Paid]);
ShipOrder::dispatch($order)->afterCommit();
```
- Payloads already in the queue are unserialized without the constructor. A new property needs a default (`public bool $notify = false;`) or old jobs fail on deploy.
- A model captured by a queued closure's `use()` is serialized whole, not by id.

## afterResponse is not a queue

`dispatch(...)->afterResponse()` / `dispatchAfterResponse()` runs the job in the web process after the response is sent: no worker, no retries, no `failed_jobs`, errors only reported. Use it only for work you can lose. Anything that must happen is queued (`->afterCommit()` inside a transaction).

## Timeouts: limits in order

HTTP client timeout (`Http::timeout(30)->connectTimeout(5)`) **<** job `#[Timeout]` (worker `--timeout`, default 60) **<** Horizon supervisor `timeout` **<** connection `retry_after` in `config/queue.php` (default 90) **<** Supervisor `stopwaitsecs` / container stop grace **<** the orchestrator's kill timeout.
- A timeout at or above `retry_after` lets a second worker start the same job while the first runs. Keep it a few seconds below; raise `retry_after` for long jobs.
- SQS has no `retry_after`: set the queue's visibility timeout in AWS above the longest job timeout.
- Timeouts need the `pcntl` extension and are ignored with `--once`. PCNTL can't interrupt a blocking socket, hence the HTTP timeout.

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
- `WithoutOverlapping` is keyed on the resource id (no key = one lock for every job of the class) and always has `releaseAfter()` and `expireAfter()` (defaults: release at once, lock never expires). `dontRelease()` drops overlapping runs; `shared()` shares the key across job classes.
- Flaky upstream: `(new ThrottlesExceptions(10, 5 * 60))->by('crm-api')->when(fn (Throwable $e) => $e instanceof HttpClientException)->deleteWhen(CustomerDeletedException::class)->report()` with `retryUntil()`. Without `report()` the exception is swallowed; `backoff()` is in minutes. `ThrottlesExceptionsWithRedis` on Redis. `failWhen()` for permanent errors.
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
- "Only the latest of rapid dispatches runs" → `#[DebounceFor(seconds, maxWait: ...)]` + `debounceId()`. An explicit `->delay()` overrides the window. Can't combine with `ShouldBeUnique` (`LogicException`).
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

Bus::batch($chunks->map(fn ($c) => new ImportCsv($upload->id, $c)))   // jobs use Batchable
    ->name('Import CSV '.$upload->id)
    ->allowFailures()
    ->finally(fn (Batch $b) => ImportFinished::dispatch($upload->id, $b->hasFailures()))
    ->onQueue('imports')
    ->dispatch();
```
- `'after_commit'` defaults to false. Inside a transaction: `->afterCommit()` on the dispatch, `ShouldQueueAfterCommit` on the class (jobs, listeners, mailables, notifications). A rollback discards the job.
- Chains and batches have no `afterCommit()`: dispatch them after the transaction returns, from `DB::afterCommit()`, or with `->afterCommit()` on the chain's first job.
- Dependent steps → chain; don't dispatch the next job from `handle()`. Only a **failure** stops a chain: `$this->delete()`, `Skip`, `RateLimited::dontRelease()` and `WithoutOverlapping::dontRelease()` all let the next job run. To halt, `$this->fail('reason'); return;` and the chain's `catch()` runs. `->delay()` delays only the first job; there is no chain `finally()`.
- Parallel fan-out with completion → batch. `then()` runs only if every job succeeded, so keep it for all-must-succeed batches; with `allowFailures()` do completion work in `finally()` and check `hasFailures()`. Callbacks are serialized: never reference `$this`; capture ids.
- A batch has one connection and queue: set `->onQueue()`/`->onConnection()` on the batch (per-job `onQueue()`/`#[Queue]` is ignored). `job_batches` ships in the skeleton migration; run `make:queue-batches-table` only if it is missing.
- Thousands of jobs → chunk them, or start the batch with one loader job that calls `$this->batch()->add(...)`. Untracked fan-out → `Bus::bulk()`. A chain nested in a batch strands `finally()` when a step fails.

## Events and listeners

```php
final class ReplyWasCreated
{
    use SerializesModels;

    public function __construct(public Reply $reply) {}
}

// app/Listeners, auto-discovered by the typed handle()
final class NotifyUsersMentionedInReply implements ShouldQueueAfterCommit
{
    public function handle(ReplyWasCreated $event): void
    {
        $event->reply->mentionedUsers()->each->notify(new MentionNotification($event->reply));
    }
}
```
- Past-tense event names carrying models as promoted properties.
- No `EventServiceProvider` / `$listen` array: registering a discovered listener again runs it twice. Discovery registers every public `handle*` method, so helpers stay private.
- Queue the listener, notification or mailable itself (`implements ShouldQueue`), not a job that wraps it. Don't queue an observer (its model is serialized by value): dispatch an event from it and queue a listener.
- Dispatched inside a transaction → the queued listener implements `ShouldQueueAfterCommit`; `ShouldHandleEventsAfterCommit` only delays *sync* listeners and observers. To hold every listener, put `ShouldDispatchAfterCommit` on the event.
- Listeners, mailables and notifications take the same queue attributes as jobs (`#[Tries]`, `#[Backoff]`, `#[Timeout]`, `#[DeleteWhenMissingModels]`, `#[Queue]`, `#[Connection]`, `#[Delay]`) plus `failed()` and `middleware()`. Set tries/backoff on the ones that make network calls; leave the rest bare. Listener options can't depend on constructor state.

## Notifications and mail

```php
#[Tries(3), Backoff([30, 120])]
final class InvoicePaid extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(User $user): array { return ['mail', 'database']; }
    public function viaQueues(): array { return ['mail' => 'mail-queue']; }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->invoice->isPaid();   // checked when the worker sends
    }
}

$user->notify(new InvoicePaid($invoice));
Notification::route('mail', 'ops@example.com')->notify(new Alert($x));
```
- One job per notifiable per channel. Big audiences fan out from a job or batch in chunks, not `Notification::send($allUsers, ...)` in the request.
- Final-failure handling in the notification's `failed()`; `NotificationFailed` fires on every failed attempt.
- Mailables use `envelope(): Envelope` and `content(): Content`, not `build()`. They render on the worker, so attach from storage (`Attachment::fromStorageDisk(...)`), not temp paths. Throttle with `middleware()` (`RateLimited`), not sleeps.

## Routing and priority

- Named queues (`high`, `default`, `emails`, `imports`); priority is set on the worker: `queue:work redis --queue=high,default` drains in order.
- A job's default queue is set once: `Queue::route(ProcessPodcast::class, connection: 'redis', queue: 'podcasts')` in a provider (also takes interfaces, e.g. `ShouldBroadcast::class`) or `#[Queue('podcasts')]`, not `->onQueue()` at every call site.

## Crash safety

A SIGKILL, OOM or container stop past its grace never calls `failed()`. A job that tracks status on a row:
```php
$this->import->update(['status' => ImportStatus::Processing, 'started_at' => now()]);   // claim, with a timestamp
// ...work...
public function failed(?Throwable $e): void { $this->import->update(['status' => ImportStatus::Failed]); }

// routes/console.php
Schedule::command('imports:recover-stuck')->everyTenMinutes()->withoutOverlapping(9);
```
The recovery command selects rows `processing` with `started_at` older than the longest possible run, caps the count per run (`->limit(...)`), resets or re-dispatches them, and has `--dry-run`.

## Workers in production

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/app/artisan queue:work redis --queue=high,default --sleep=3 --tries=3 --backoff=30 --max-time=3600 --memory=256
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=8
stopwaitsecs=3600
```
- Supervisor or systemd running `queue:work` (never `queue:listen`, `nohup` or deprecated `--daemon`/`--delay`). `--max-time`/`--memory` recycle processes; memory is checked between jobs.
- `--tries`/`--backoff`/`--timeout` are fallbacks: a job's own attributes win. Declare the budget on the job.
- `autorestart=true`, not `unexpected`: `--max-time`, `--max-jobs`, `queue:restart`, SIGTERM and a lost DB/Redis connection all exit 0; memory exits 12.
- `stopwaitsecs` longer than the longest job and shorter than whatever kills the host.
- Every deploy restarts workers gracefully: `php artisan queue:restart`, or `php artisan reload` (also Reverb/Octane), or `horizon:terminate` under Horizon. Restart and `queue:pause`/`queue:resume` signal through the cache, so every worker host shares one store; `Queue::withoutInterruptionPolling()` turns both off.

## Long-running worker state

- One worker process runs many jobs. No per-job state in statics, singletons or properties of shared services: they leak into the next job.
- Bind per-job services with `$this->app->scoped(...)`: the worker forgets scoped instances and resolved facades before each job. Statics are never reset.
- `Context` is copied into every payload in plain text, outside `ShouldBeEncrypted`: small scalars only (trace id, tenant id, locale), never secrets or personal data, `addHidden()` included.

## Containers

- One `queue:work` (or `horizon`) per container, as PID 1 via exec form (`CMD ["php", "artisan", "queue:work", "--max-time=3600"]`) so it receives SIGTERM; shell form swallows it. Scale replicas at the orchestrator with an always-restart policy.
- Stop grace (ECS `stopTimeout`, Kubernetes `terminationGracePeriodSeconds`) above the longest job, or jobs die mid-run (see Crash safety).
- Install `pcntl` in the image (`docker-php-ext-install pcntl`): without it there are no job timeouts and no graceful shutdown.
- Long loops implement `Interruptible` and stop cleanly in `interrupted(int $signal)` (also called on timeout).
- The scheduler is its own process (`schedule:work`, or cron running `schedule:run`), never inside a worker container.

## Drivers

- **SQS:** payload max 1 MiB (enable `overflow` to a cache store for more); delay max 15 minutes, so longer waits are scheduled; no `retry_after` (visibility timeout, see Timeouts); `attempts()` is the receive count, so every release counts against the redrive policy's max receives.
- **SQS FIFO:** per-message delay is dropped. Group with `->onGroup("customer-{$id}")` and dedupe with `deduplicationId()`; without one every message gets a fresh UUID and is never deduplicated.
- **Redis:** `block_for` a small number (5) or `null`, never `0` (blocks SIGTERM until the next job). Redis Cluster queue names carry a hash tag (`'{default}'`). Apps sharing a Redis use their own prefix or database.
- **Database:** `DB_QUEUE_CONNECTION` can give the jobs table its own connection; inserts then commit outside the app's transaction, so `afterCommit()` still applies. Several workers want `FOR UPDATE SKIP LOCKED` (MySQL 8.0.1+, MariaDB 10.6+, PostgreSQL 9.5+); older versions fall back to plain row locks and workers contend.

## Horizon

- Workers are configured in `config/horizon.php` (`defaults` + a supervisor per entry under `environments`), not CLI flags. Every deployed environment has an entry, or `'*'`.
- Per supervisor: `timeout`, `tries`, `backoff`, `memory`, `maxJobs`, `maxTime` (recycle limits; default 0 = never). Supervisor `timeout` (default 60) above any job `#[Timeout]` (scale-down force-kills after it) and a few seconds below `retry_after`.
- With `balance => 'auto'`, `maxProcesses` is the total across the supervisor's queues and `minProcesses` is per queue (≥ 1), so `maxProcesses` ≥ queues × `minProcesses`. `autoScalingStrategy` is `time` (default), `size` or `log` (Horizon 5.50+).
- With `balance => 'auto'`, queue order inside one supervisor is **not** priority. Strict priority → separate supervisors or `balance => false`:
```php
'production' => [
    'supervisor-high' => ['queue' => ['high'], 'maxProcesses' => 10, 'timeout' => 60],
    'supervisor-default' => ['queue' => ['default'], 'balance' => 'auto', 'autoScalingStrategy' => 'time', 'maxProcesses' => 5, 'maxTime' => 3600, 'maxJobs' => 1000],
],
```

## Monitoring

```php
// routes/console.php
Schedule::command('queue:monitor redis:default,redis:emails --max=500')->everyMinute();

// AppServiceProvider::boot()
Event::listen(fn (QueueBusy $e) => Notification::route('slack', config('services.slack.ops'))
    ->notify(new QueueIsBusy($e->connectionName, $e->queue, $e->size)));
Queue::failing(fn (JobFailed $e) => Log::critical('Job failed', ['job' => $e->job->resolveName()]));   // final failure only; route to alerting
```
- `queue:monitor` alone alerts nobody: it only fires `QueueBusy`.
- Backlog size doesn't prove workers consume. Add a liveness signal: a scheduled tiny job that stamps `Cache::put('queue:heartbeat', now())` with an alert when it goes stale (or `spatie/laravel-health`'s `QueueCheck`).
- `Queue::after()`/`JobProcessed` also fire for released and failed jobs: check `isReleased()`/`hasFailed()` before counting a success.
- Horizon: `waits` per `connection:queue` (undefined pairs default to 60 s) plus `Horizon::routeMailNotificationsTo()`/`routeSlackNotificationsTo()` in `HorizonServiceProvider`.

## Housekeeping (routes/console.php)

```php
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('queue:prune-batches --hours=48 --unfinished=72 --cancelled=72')->daily();   // apps using batches
Schedule::command('model:prune')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();   // only with laravel/horizon
Schedule::job(new SyncInventory)->hourly()->onOneServer();   // SyncInventory: ShouldBeUnique, uniqueId(), #[UniqueFor]
```
- Without pruning, `failed_jobs` and `job_batches` grow without limit.
- On `Schedule::job()` the scheduler's `withoutOverlapping()` guards only the dispatch; overlap protection for the work is `ShouldBeUnique` or `WithoutOverlapping` middleware. `onOneServer()` only when several servers run the scheduler. Lock stores, expiries and schedule tests: `references/scheduling.md`.

## Testing

Test dispatch and handling separately.
```php
Queue::fake()->serializeAndRestore();   // catches serialization bugs

$this->post('/orders', $data);

Queue::assertPushedOn('high', ShipOrder::class, fn (ShipOrder $job) => $job->order->is($order));

Bus::fake();
// ...
Bus::assertChained([ShipOrder::class, RecordShipment::class]);
Bus::assertBatched(fn (PendingBatch $b) => $b->name === 'Import CSV' && $b->jobs->count() === 10);
```
- `Queue::fake()`: `assertPushed`, `assertPushedOn`, `assertPushedTimes`, `assertPushedOnce` (class only), `assertNotPushed`, `assertPushedWithChain`, `assertCount`, `assertNothingPushed`. `Bus::fake()`: `assertDispatched`, `assertDispatchedTimes`/`Once`, `assertNotDispatched`, `assertChained`, `assertDispatchedWithoutChain`, `assertBatched`, `assertBatchCount`, `assertNothingBatched`. Pass a class list to fake only some. Don't switch the queue to `sync` and assert side effects through HTTP.
- `Bus::fake()` doesn't see queued listeners: `Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendShipmentNotification::class)`. Wiring: `Event::assertListening(OrderShipped::class, SendShipmentNotification::class)`. Behaviour: call `handle()` directly.
- A `ShouldQueue` mailable is recorded as queued even when sent with `Mail::send()`: assert `Mail::assertQueued()`, or `assertOutgoingCount()` when unsure.
- Unit-test `handle()`'s release/fail/delete logic:
```php
$job = (new ProcessPodcast($podcast))->withFakeQueueInteractions();

$job->handle(app(AudioProcessor::class));

$job->assertReleased(delay: 30);
$job->assertNotFailed();
```
  Also `assertFailed()`, `assertFailedWith()`, `assertDeleted()`, `assertNotReleased()`; `[$job, $batch] = $job->withFakeBatch()` for batches; `$job->assertHasChain([...])` for runtime chain changes.
- Idempotency: run `handle()` twice and assert one row, one charge, one mail.
- Status-tracking jobs: call `(new ImportFeed($feed))->failed(new RuntimeException('boom'))` and assert the row is marked failed.

Evidence: `Illuminate/Foundation/Queue/Queueable.php`, `Foundation/Console/stubs/job.queued.stub`, `Illuminate/Queue/{Attributes,Middleware}/*`, `Queue/{Worker,QueueManager,QueueRoutes,QueueServiceProvider,SerializesModels,SerializesAndRestoresModelIdentifiers,InteractsWithQueue,CallQueuedHandler,SqsQueue,DatabaseQueue}.php`, `Queue/Jobs/SqsJob.php`, `Queue/Console/{WorkCommand,MonitorCommand,PruneBatchesCommand}.php`, `Queue/Events/QueueBusy.php`, `Bus/{Batch,PendingBatch,UniqueLock,Batchable,Dispatcher}.php`, `Foundation/Bus/{PendingDispatch,PendingChain}.php`, `Events/{Dispatcher,CallQueuedListener}.php`, `Database/Eloquent/Model.php` (`newQueryForRestoration`), `Log/Context/ContextServiceProvider.php`, `Support/Testing/Fakes/{QueueFake,BusFake,MailFake,EventFake,NotificationFake}.php`; `laravel/docs/queues.md`, `events.md`, `notifications.md`, `mail.md`, `horizon.md`, `scheduling.md`; laravel/laravel `config/queue.php`, `database/migrations/0001_01_01_000002_create_jobs_table.php`; laravel/horizon `config/horizon.php`, `src/SupervisorOptions.php`, `CHANGELOG.md`; laravel.io `app/Jobs/`, `app/Listeners/`, `routes/console.php`.
