# Logging, error reporting and health

Checked against Laravel 13.42, Pulse 1.10, Nightwatch 1.30. `ShouldntReport`, `dontReportWhen`, `stopIgnoring` and `Exceptions::fake()` are newer than 11.0: on 11/12 confirm they exist, else use `dontReport` and a real-handler test. `Context` is 11+.

## Logging an exception

```php
// bad: class, file, line and trace are gone
Log::error('Delete failed: '.$e->getMessage(), ['article_id' => $id]);
// good: unexpected failure, goes through the handler
report($e);
// good: you need your own message or level
Log::warning('Article delete failed', ['article_id' => $id, 'exception' => $e]);
```
- Pass the object. The line formatter renders the `exception` context key with its trace. `getMessage()` alone never does.
- `report($e)` is the better default: only it passes through `dontReport`, throttling and the `reportable()` hooks error trackers use. A bare `Log::error` bypasses all three.
- Report and continue, or rethrow. Never both: the handler (or the queue worker) reports the rethrown exception again, so one incident shows twice. To add context, wrap and throw (`throw new SyncFailed($id, previous: $e)`) and do not log.
- `$exceptions->dontReportDuplicates()` only dedupes the same instance. Two separately built exceptions, and `report('text')` (new `Exception` each call), are not deduped.
- Not every catch is an incident. A catch whose fallback worked wants a `warning` with context, or nothing.

## Catching

- `Throwable` only at a boundary that reports: a command's per-item loop, a job's tail (`failed()` or the last catch), a controller that must answer something specific. Everywhere else catch the narrow class you can handle.
- `catch (\Exception)` misses `TypeError`, `Error` and `ValueError`, so the failure skips your log and fallback.
```php
foreach ($ids as $id) {
    try { $this->sync($id); } catch (Throwable $e) { report($e); $failed++; }
}
return $failed ? self::FAILURE : self::SUCCESS;
```
- `report($e)`: record and continue. `rescue(fn () => ..., $default, report: true)` is try-and-default and catches `Throwable`; `report:` takes a bool or a closure (`fn ($e) => ! $e instanceof NotFound`). `report_if($cond, $e)` reports an exception you built; it is not a catch helper.
- Don't write `try {...} catch (Throwable $e) { report($e); $x = null; }` when `$x = rescue(fn () => ..., null)` says the same. Don't use `rescue()` around code you expect to be type-safe: it swallows `Error` too.
- Don't wrap every method in `catch (Throwable)` "to be safe": deep catches hide bugs and cause double reports.

## Expected exceptions

- Your own class: `class OutOfStock extends Exception implements ShouldntReport {}`. Vendor class: `$exceptions->dontReport([VendorNotFound::class])` (`instanceof`). Conditional: `$exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof HttpException && $e->getStatusCode() < 500)`.
- `abort(4xx)` throws `HttpException`, already on the framework's ignore list (also auth, authorization, model-not-found, validation, token mismatch). `$exceptions->stopIgnoring(HttpException::class)` reverses it.
- Never `abort(500)` or return a 500 for a client fault. Skipped exceptions are still rendered; `dontReport` only affects reporting.
- Never flash secrets: defaults are `current_password`, `password`, `password_confirmation`; add more with `$exceptions->dontFlash(['pin', 'api_token'])` (additive).

## Levels

`$exceptions->level(PDOException::class, LogLevel::CRITICAL)` (`instanceof`, first match wins, default `error`). `LogLevel::WARNING` for "upstream flaked, fallback used".
- `emergency`/`alert`: site down, page now. `critical`: a component is down. `error`: this operation failed and a person must look. `warning`: handled or degraded. `notice`: unusual but normal. `info`: business events. `debug`: developer detail.
- Expected 4xx outcomes: no log, or `info`/`warning`. Never `error`.
- `logger('x')` is `debug`: `logger('sync failed, continuing')` vanishes at `LOG_LEVEL=info`. Use `Log::warning`/`report()`.

## Throttling and sampling

```php
$exceptions->throttle(fn (Throwable $e) => match (true) {
    $e instanceof ConnectionException => Limit::perMinute(5),        // keyed by exception class
    $e instanceof RateLimited => Limit::perMinute(5)->by($e->getMessage()),
    $e instanceof DeprecatedField => Lottery::odds(1, 100),
    default => null,
});
```
- `Limit` goes through `RateLimiter` (cache-backed), so it needs a shared cache, not `array`. A broken cache driver silently disables throttling.
- The callback is chosen by its first parameter's type: `fn (Throwable $e)` gets all, `fn (ConnectionException $e)` only that class.
- Throttling runs before the report hooks, so Pulse and Nightwatch records are throttled too.
- Only where a noisy type is observed. On a low-volume app it hides real errors. Don't add ad-hoc `Cache::add('alerted:...')` in a catch.

## Context

- `$exceptions->context(fn (Throwable $e, array $context) => [...$context, 'tenant_id' => tenant()?->id])`, or a `context(): array` method on the exception class. `userId` is added automatically. Don't catch only to add context.
- `Context::add('trace_id', ...)`: lands in `extra` of every log record and is serialised into dispatched jobs. `Context::addHidden()` reaches jobs but not log lines.
- `Log::withContext([...])` persists on the resolved logger and accumulates across requests/jobs in a long-lived worker or Octane. Use `Context` in middleware. It does not reach queued jobs.
- Never put large objects in `Context` (`Context::add('event', $event)`): they are serialised into every log record and every job.

## Custom `report()` and `render()`

- `public function report(): bool|null` on an exception class: `false` falls through to default logging, anything else (including `void`) stops it. Resolved through the container. `render()` returns the response.
- `dontReport`/`ShouldntReport` are checked before the method runs.

## Log messages

```php
// bad
Log::info("Order $order->id for $user->email: ".json_encode($request->all()));
// good: constant message, ids only
Log::info('Order shipped', ['order_id' => $order->id, 'user_id' => $user->id]);
```
- A constant message groups in every aggregator. Never put emails, tokens, `$request->all()`, headers or Eloquent models in context (the formatter dumps every attribute; `$hidden` only applies to `toArray()`).
- No per-item logging in a loop or in a resource `toArray()`: log once with counts after the loop.

## Channels

- Containers: `LOG_CHANNEL=stderr` (or `LOG_STACK=stderr`), `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`. A file in a container's writable layer is lost on deploy.
- Hosts: `daily` with `max_files` on 13 (`days` on 11-12, where `max_files` is ignored), not `single` (grows forever).
- Production `LOG_LEVEL=info` (the skeleton default is `debug`, and every vendor debug line is stored). Check where logs ship before changing a host app.
- Slack/mail channels: `critical` and up only. Leave `ignore_exceptions` `false` so a dead sink surfaces. A named channel for a specific stream (`Log::channel('audit')`), not one per feature.
- Set `LOG_DEPRECATIONS_CHANNEL` to a real channel in staging.

## Health

```php
Event::listen(DiagnosingHealth::class, function (): void {
    DB::connection()->select('select 1');
    Cache::store()->get('health-probe');
});
```
- `health: '/up'` proves only that the app booted. A failing `DiagnosingHealth` listener makes it answer 500 (not 503) and is reported on every probe. The status comes from the exception message, so an exception with an empty message (or `'0'`) is reported but answers 200: throw with a message. Throttle that report during a DB blip. With `APP_DEBUG=true` the exception is rethrown.
- Check only the app's own DB and cache, within about 1 s. Never call a vendor from a probe: a vendor outage then removes every instance from rotation.
- `health:` registers one route. For liveness add `Route::get('/live', fn () => response()->noContent())` with no dependencies, and use `/up` as readiness. A liveness probe on a DB check restarts every pod during a DB blip.
- Keep a working `/health-check` if the load balancer uses it: `health:` takes any path.

## Slow queries

```php
// bad: one log line per query, on every request
DB::listen(fn (QueryExecuted $query) => Log::debug($query->sql, $query->bindings));

// good, AppServiceProvider::boot(): a budget for the whole request or job
DB::whenQueryingForLongerThan(CarbonInterval::seconds(2), function (Connection $connection, QueryExecuted $event): void {
    Log::warning('Query time budget exceeded', ['connection' => $connection->getName(), 'route' => request()->route()?->getName()]);
});
// good: single slow queries only
DB::listen(function (QueryExecuted $query): void {
    if ($query->time > 1000) {
        Log::warning('Slow query', ['sql' => $query->sql, 'ms' => $query->time, 'connection' => $query->connectionName]);
    }
});
```
- `sql` leaves the bindings out, but raw SQL with inline values (`whereRaw("email = '$email'")`) puts them in the text. Bind every value (`security.md`), or log only the time and connection where the code builds raw SQL.
- `whenQueryingForLongerThan` adds up the time of every query on one connection (the default one through `DB::`; `DB::connection('x')` for others) and fires once when the total passes the threshold. An int threshold is milliseconds. Queue workers reset the total before each job, so it works per job too. It catches N+1 loops that no single slow query shows.
- Log `$query->sql`, never `toRawSql()` or bindings: bindings carry emails, tokens and other request data.
- Only where nothing else records queries. Pulse's slow-queries card and Nightwatch already do.

## Pulse and Nightwatch

Only when already in `composer.json`. Don't add either to an app with another tracker.
- Pulse: the default `viewPulse` gate is local only; define it against a real role (`Gate::define('viewPulse', fn (User $user) => $user->isAdmin())`). `Pulse::user(fn ($u) => ['name' => $u->name, 'extra' => $u->role])` keeps emails and Gravatar hashes out. Run `pulse:check` (servers card) and, with the redis ingest, `pulse:work` supervised. Lower `sample_rate` on request/cache/user recorders for busy apps. Pulse failures are silent: a blank dashboard is not "no problems".
- Nightwatch: run `php artisan nightwatch:agent` supervised or events drop silently. `Nightwatch::user(fn ($u) => ['name' => $u->display_name, 'username' => $u->handle])` keeps emails out. Keep `NIGHTWATCH_EXCEPTION_SAMPLE_RATE` at `1.0` (request sampling alone still keeps exceptions). `NIGHTWATCH_REDACT_*` env lists replace the defaults, so re-include them. Logs ship only through the `nightwatch` log channel, which you add to the stack; set `NIGHTWATCH_LOG_LEVEL`.
- Both capture exceptions through `report()`: `dontReport`, `ShouldntReport` and throttling hide them there too, and an exception you only `Log::error` never shows as one.

## Small mistakes

- `dd()`, `dump()`, `ray()`, `var_dump` left in committed code.
- `$e->getMessage()` in a response body (see `http-layer.md`).
- `response()->json(['error' => ...], 500)` in a catch: let it throw, the handler renders and reports once.
- `$request->all()` logged in a handler of third-party callbacks: store an id.

## Tests

- `Exceptions::fake()` records only what the real `shouldReport` allows, and skips the exception's own `report()`, report callbacks and logging. `assertNotReported(HttpException::class)` passes trivially. `assertReported`/`assertReportedCount` for your own classes, plus the positive case in a sibling test. Call it before the request.
- To test what gets logged, use the real handler: `config(['app.debug' => false])` and collect `Event::listen(MessageLogged::class, fn ($e) => $records[] = $e)`; `$e->context['exception']` is the `Throwable`.
- Don't use `Log::spy()` when code calls `Log::channel()`: the chain breaks.
- Health: swap the default connection for a missing SQLite file and assert `/up` is a 5xx and `/live` is 200.

Evidence: `Illuminate/Database/Connection.php` (`whenQueryingForLongerThan`, `logQuery`), `Illuminate/Queue/QueueServiceProvider.php` (`resetTotalQueryDuration` before each job), `laravel/docs/database.md` (Monitoring Cumulative Query Time); laravelio/laravel.io `app/Providers/AppServiceProvider.php`; `Illuminate/Foundation/Exceptions/Handler.php`, `Configuration/Exceptions.php`, `Foundation/Http/HealthCheck.php`, `Foundation/helpers.php`, `Log/{LogManager,Logger}.php`, `Log/Context/ContextLogProcessor.php`, `Support/Testing/Fakes/ExceptionHandlerFake.php`, `Contracts/Debug/ShouldntReport.php`; `laravel/docs/{errors,logging,context,pulse}.md`; `laravel/pulse/src/...`; `laravel/nightwatch/src/...`.
