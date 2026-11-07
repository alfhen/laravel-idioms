# Scheduling

## Where schedules live

- New code: `routes/console.php` with the `Schedule` facade, or `->withSchedule(fn (Schedule $schedule) => ...)` in `bootstrap/app.php` when `routes/console.php` should hold only closure commands. Pick one per app.
- Upgraded app with `app/Console/Kernel.php`: check `bootstrap/app.php` first. `Application::configure()` binds the framework's console kernel, so a leftover `Kernel::schedule()` never runs; move its entries. A bootstrap that still binds `App\Console\Kernel` keeps it live: add new entries there, and don't move them as a side effect of a feature.
- Grep `routes/console.php`, `bootstrap/app.php`, `app/Console/Kernel.php` and providers (`callAfterResolving(Schedule::class`) before adding or auditing a task.
- Service providers hold only scheduler-wide settings such as `Schedule::alwaysOnOneServer()` in `AppServiceProvider::boot()`. Never tasks.

## Pick the task type

| Work | Use |
|---|---|
| An Artisan command (most tasks) | `Schedule::command(PruneCarts::class)` or `'carts:prune --days=30'` |
| Anything slow, retryable or fanned out | `Schedule::job(new SyncStock)` (the job does the work on a worker) |
| A one-liner with no options | `Schedule::call(fn () => ...)->name('...')` |
| A shell binary | `Schedule::exec('node scripts/build.js')` |

- `command()` runs a child `php artisan` process: an exception or non-zero exit fails only that task. `call()` runs inside `schedule:run`; a long closure delays every task after it in the same minute.
- Tasks due in the same minute run one after another. Long work goes to the queue, or `command()->runInBackground()`.
- `Schedule::job()` on a class without `ShouldQueue` runs the job inline in `schedule:run` (`dispatchNow`). Scheduled jobs always `implements ShouldQueue`.
- `runInBackground()` exists only for `command()`/`exec()`; on `call()`/`job()` it throws `RuntimeException` when the schedule loads. A background command's non-zero exit is not reported to the exception handler (only its `onFailure()` hooks see it), and its overlap lock is held until the hidden `schedule:finish` runs.

## Frequency chain

```php
// bad: weekly() resets minute, hour and day-of-week → Sunday 08:00
Schedule::command('reports:send')->mondays()->weekly()->at('8:00');
// bad: weekly() wipes the 9:00 → Sunday 00:00
Schedule::command('reports:send')->dailyAt('9:00')->weekly();
// good
Schedule::command('reports:send')->weeklyOn(Schedule::MONDAY, '8:00');   // 0 8 * * 1
Schedule::command('reports:send')->weekly()->mondays()->at('8:00');      // same
```
- Base frequency first (`daily`, `weekly`, `monthly`), then constraints (`mondays`, `at`, `between`, `environments`). Prefer the one-call forms: `weeklyOn`, `monthlyOn`, `twiceDailyAt`, `dailyAt`.
- Check what you wrote: `php artisan schedule:list` (`--next`, `--timezone=`, `--json`) shows the cron expression and the next due time.

## Timezones and DST

```php
// risky: on the spring-forward night 02:30 Copenhagen does not exist; on fall-back it happens twice
Schedule::command('billing:close-day')->dailyAt('02:30')->timezone('Europe/Copenhagen');
// good
Schedule::command('billing:close-day')->dailyAt('05:30')->timezone('Europe/Copenhagen');
```
- The scheduler matches the wall clock in the task's timezone, else `app.schedule_timezone`, else `app.timezone` (skeleton: `UTC`).
- When the business runs on a local timezone, every time-of-day entry uses it, nightly pruning included: set `schedule_timezone` once, or `->timezone()` on each. Mixing UTC and local entries in one file makes "every night at 3" mean two different things.
- Keep local times out of the DST change window (02:00-02:59 in EU zones, 01:00-02:59 in US zones), or the task skips or runs twice that night. Pick 03:00 or later, not a UTC time chosen to dodge the window.

## Overlap and single-server locks

```php
// bad: unnamed closure → LogicException; default lock outlives a killed run by 24 h
Schedule::call(fn () => Cart::pruneStale())->hourly()->withoutOverlapping()->onOneServer();
// good
Schedule::call(fn () => Cart::pruneStale())->name('carts:prune-stale')->hourly()->withoutOverlapping(10)->onOneServer();
```
Four guards, four meanings:

| Guard | Stops | Expiry |
|---|---|---|
| `->withoutOverlapping($minutes)` | a tick starting while the previous run of this task still runs | default 1440 min; pass just above the worst run |
| `->onOneServer()` | the same tick running on two servers | 1 h scheduling lock per tick |
| `Isolatable` + `--isolated` on the command | two `--isolated` runs of the command, from any caller (cron, deploy hook, human) | `isolationLockExpiresAt()`, default 1 h |
| `ShouldBeUnique` / `WithoutOverlapping` job middleware | two queued copies of the job, or two running at once | `#[UniqueFor]` / `expireAfter()` |

- The scheduler releases its overlap lock on SIGTERM/SIGINT/SIGQUIT (with pcntl). A SIGKILL, OOM kill or lost container leaves it until expiry, so the expiry is the only recovery. `php artisan schedule:clear-cache` drops stale locks by hand.
- `withoutOverlapping()` on `Schedule::job()` guards only the dispatch, which takes milliseconds. Guard the job: `ShouldBeUnique` + `uniqueId()` + `#[UniqueFor]`. The scheduler then skips the dispatch silently while the unique lock is held.
- `onOneServer()` only when more than one process runs `schedule:run` (several servers, replicas, a cron line in every web container). One scheduler → neither `onOneServer()` nor a shared store.
- The locks live in `config('cache.schedule_store')`, else `SCHEDULE_CACHE_STORE`, else the default cache store. `file`/`array` are per host and coordinate nothing, so production's environment points one of those at a shared store (redis, memcached, database, dynamodb all work). Choose the store by environment, never in code: a hardcoded `Schedule::useCache('redis')` or `env('X', 'redis')` default makes `schedule:run`, artisan and the test suite fail wherever Redis isn't running, while tests on the `array` store exercise the same locks.
- Closures need `->name()` before `withoutOverlapping()`/`onOneServer()`, including inside an `onOneServer()` group. `alwaysOnOneServer()` silently skips unnamed closures.
- Two entries for the same job class (different arguments) need distinct names, or they share one lock: `->name("uptime:{$site->host}")`.
- Shared settings → a group instead of repeating them:
```php
Schedule::daily()->onOneServer()->timezone('Europe/Copenhagen')->group(function () {
    Schedule::command('emails:send --force');
    Schedule::command('emails:prune');
});
```

## Sub-minute tasks

- `everySecond()` … `everyThirtySeconds()` keep `schedule:run` alive for the whole minute. The task only dispatches: `Schedule::job(new PollPayouts)->everyFifteenSeconds()` or `Schedule::command('payouts:poll')->everyTenSeconds()->runInBackground()`.
- The running minute uses the old code after a deploy: add `php artisan schedule:interrupt` to the deploy script after the new code is live (next to `queue:restart`).

## Maintenance mode and pause

- Default: tasks skip in maintenance mode and while `schedule:pause` is on (13). Keep that for anything that writes or sends.
- Heartbeats, health checks and queue monitors get `->evenInMaintenanceMode()` (and `->evenWhenPaused()` on 13), so the monitor doesn't page during a planned window.

## Hooks, pings and failure

```php
Schedule::command('invoices:send')->dailyAt('06:00')
    ->pingBefore(config('services.hc.invoices_start'))
    ->pingOnSuccess(config('services.hc.invoices_ok'))
    ->pingOnFailure(config('services.hc.invoices_fail'));
```
- A dead-man's switch uses `pingOnSuccess`/`pingOnFailure` (or `*If`). `thenPing()`, `then()` and `after()` fire whatever the exit code, so they never signal success.
- Failure means a non-zero exit (command/exec) or, for `call()`, a thrown exception or a `false` return. Return nothing for "nothing to do"; `return false` marks the run failed.
- On `Schedule::job()` every hook means "was dispatched". Completion pings go at the end of `handle()`, failure handling in `failed()`.
- Output hooks (`sendOutputTo`, `appendOutputTo`, `emailOutputTo`, `emailOutputOnFailure`) only work on `command()`/`exec()`.
- A ping that fails with an HTTP or connection error is reported, not thrown, so a dead monitor endpoint doesn't fail the task.

## Dispatchers and missed ticks

The scheduler evaluates only the current minute: a tick missed during a deploy or outage never replays. Select work by persisted state, not a time window.
```php
// bad: rows created during a missed tick are never invoiced; a slow run re-invoices
Order::whereBetween('created_at', [now()->subHour(), now()])->each(fn (Order $o) => $o->invoice());

// good: DispatchPendingExports, scheduled everyMinute()->onOneServer()->withoutOverlapping(5)
$ids = Export::query()->whereNull('claimed_at')->orderBy('id')->limit(500)->pluck('id');
Export::query()->whereKey($ids)->update(['claimed_at' => now()]);
$ids->each(fn (int $id) => GenerateExport::dispatch($id));
```
- Claim before dispatch (stamp a column) or dispatch a keyed `ShouldBeUnique` job. `withoutOverlapping()` on the dispatcher doesn't stop re-dispatch: it exits long before the jobs run.
- Cap each run (`limit()`), so a backlog drains over several ticks instead of one huge run.
- Claims need a recovery task for rows a killed worker left behind:
```php
// RecoverStuckExports, scheduled everyTenMinutes()->onOneServer()->withoutOverlapping(9)
$ids = Export::query()->whereNull('completed_at')->where('claimed_at', '<', now()->subMinutes(30))->limit(200)->pluck('id');
Export::query()->whereKey($ids)->update(['claimed_at' => null]);
```
- Every task is idempotent: running it twice in a row, or after a gap, gives the same result.

## Running the scheduler

- One cron line per scheduler host: `* * * * * cd /app && php artisan schedule:run >> /dev/null 2>&1`. In containers and locally, one `php artisan schedule:work` process. Prefer it to a hand-written `while true; do php artisan schedule:run; sleep 60; done` loop: `schedule:work` starts each run on the minute in its own process, so a slow run doesn't push the next tick late or skip it.
- Several scheduler processes are fine only with `onOneServer()` on a shared store. Otherwise run exactly one.
- `schedule:test --name=carts:prune-stale` runs one task now; `schedule:list --json` audits `on_one_server`, `timezone` and `has_mutex` (an overlap lock held right now).

## Observability

- `Event::listen(ScheduledTaskFailed::class, ...)` for a central alert (`$event->task`, `$event->exception`). `ScheduledTaskSkipped` carries a `ScheduledTaskSkipReason` (`Paused`, `ConditionNotMet`, `RanOnAnotherServer`).
- Long-run alarm on 13: `Schedule::whenTaskRunsLongerThan(300, fn (Event $task, float $seconds) => report(new RuntimeException("{$task->getSummaryForDisplay()} took {$seconds}s")))`. It fires after the task finishes, so it can't detect a hung task; the overlap expiry and an external heartbeat do.
- Per-task history: spatie/laravel-schedule-monitor is optional; it needs a name on every closure and job entry (`->monitorName()`).

## Testing

Assert the definition through the container (tests boot the console kernel, so `routes/console.php` entries are registered):
```php
it('schedules the weekly report once per tick on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->sole(fn (Event $e) => str_contains((string) $e->command, 'reports:weekly'));

    expect($event->expression)->toBe('0 17 * * 5')
        ->and($event->timezone)->toBe('Europe/Copenhagen')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(30);

    $this->travelTo(CarbonImmutable::parse('2026-11-06 17:00', 'Europe/Copenhagen'));
    expect($event->isDue(app()))->toBeTrue();
});
```
- Find jobs and closures by name: `$e->description === SyncStock::class` or the `->name()` you gave. `$e->command` is null on them, hence the `(string)` cast.
- End to end: fake first, travel in the task's timezone, run the scheduler.
```php
it('dispatches the digest at 07:00 Copenhagen time', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-06 07:00', 'Europe/Copenhagen'));

    $this->artisan('schedule:run')->assertSuccessful();

    Queue::assertPushed(SendDigest::class);
});
```
- `now()->setTime(7, 0)->setTimezone('Europe/Copenhagen')` is 07:00 UTC converted, not 07:00 Copenhagen: build the time in the target zone.
- Test the dispatcher's claim (a second run dispatches nothing) and the recovery task's cut-off (a row claimed 29 min ago stays claimed).

Evidence: `Illuminate/Console/Scheduling/{Schedule,Event,CallbackEvent,ManagesAttributes,ManagesFrequencies,PendingEventAttributes,CacheSchedulingMutex,ScheduleRunCommand,ScheduleListCommand,ScheduleTestCommand,ScheduleWorkCommand,ScheduleClearCacheCommand,ScheduledTaskSkipReason}.php`, `Console/Events/ScheduledTask{Failed,Skipped,Finished}.php`, `Foundation/Configuration/ApplicationBuilder.php` (`withKernels`, `withSchedule`), `Foundation/Console/Kernel.php` (`scheduleTimezone`, `scheduleCache`), `Foundation/Testing/TestCase.php`; `laravel/docs/scheduling.md`; laravel/laravel `config/app.php`, `routes/console.php`; spatie/laravel-schedule-monitor `ScheduleMonitorServiceProvider.php`; laravel.io `routes/console.php`.
