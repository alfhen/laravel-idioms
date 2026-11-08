---
name: laravel-idioms
description: Use for any work in a Laravel codebase, whether reviewing, planning, building, refactoring, debugging, speeding up or testing PHP code in a project with an artisan file or laravel/framework in composer.json, even when the user never says "Laravel". Load it before planning a change or answering a question about the code, not only before editing. Also use it to audit a Laravel codebase, judge its health, find code smells or write a refactor report. Concrete rules for Laravel 11-13 on thin controllers, Form Requests, policies, collections over loops, Eloquent, N+1 and slow queries, migrations and indexes, API auth and error responses, file uploads, races, outbound HTTP, events and mail, jobs, queues and workers, scheduled tasks, Artisan commands, caching and locks, Blade components, Filament 5, Pest and PHPUnit tests, plus a mandatory "Before you finish" checklist. Not for PHP projects that aren't Laravel. Complements Laravel Boost's laravel-best-practices skill, so load both when both are installed.
---

# Laravel idioms

Write code the way the Laravel framework, its docs and well-maintained packages (laravel.io, Spatie) write it. Match the project's existing conventions and Laravel version first; these rules decide everything the project hasn't already decided.

**With Laravel Boost:** load this skill *and* `laravel-best-practices`. Boost's rule files also cover security, migrations, the HTTP client, error handling and mail. This skill adds the concrete rules below, the Laravel 13 / Filament 5 / Pest 5 API specifics, and the mandatory "Before you finish" checklist. Where the two differ on a specific API or pattern, follow this skill; verify version-sensitive APIs with Boost's `search-docs` when it is available.

**Audit or refactor-report request?** ("evaluate this codebase", "suggest refactors", "audit this app") → follow `references/audit-mode.md`. It ends in a self-contained HTML report built from `assets/audit-report-template.html`.

## 0. Check the version, then commit to its style

Read `laravel/framework` in `composer.lock`. The 11+ skeleton has no `Http/Kernel.php`, `Console/Kernel.php`, `Exceptions/Handler.php`, `RouteServiceProvider`, `AuthServiceProvider` or `EventServiceProvider`, and no `routes/api.php` until `php artisan install:api`. On **Laravel 13**, every new model, job and command uses attributes (`#[Fillable]`, `#[Hidden]`, `#[Scope]`, `#[ObservedBy]`, `#[Tries]`, `#[Signature]` + `#[Description]`), and you use them in every new file, not only some. On older versions, or where existing code uses properties, keep properties. An upgraded app may still keep schedules in `app/Console/Kernel.php` or a provider: add new entries where the existing ones live.

## 1. Controllers orchestrate; they don't compute

A controller action authorizes, hands validated input to one collaborator, and returns a response. **Limit: about 10 lines per action, one query or one call, and no `private` query/formatting helpers.** When an action needs several queries, aggregation, row mapping or formatting, move that into a class named after what it produces, and have it reuse the model's scopes.
```php
// bad: 60+ lines of joins, sums and formatting in __invoke plus private helpers
// good
public function __invoke(StatsRequest $request, BuildTeamStats $stats): JsonResponse
{
    return response()->json($stats($request->user()->team, $request->filters()));
}
```
Plain CRUD needs no extra class: `$request->user()->posts()->create($request->validated())` is the whole store action. Domain rules that belong to one model (syncing its tags, computing its totals) go in a model method, not in a private controller helper. Use resource routes with CRUD names (`destroy`, not `delete`); a non-CRUD endpoint gets its own invokable controller. Type-hint models for route binding (`{post:slug}`).

## 2. Validate every input, including query strings

Every request that reads input gets a FormRequest: store, update, **and** index/report endpoints that take filters, sort keys or `per_page`. An unvalidated `?status=bogus` that silently returns nothing is a bug.
```php
// bad
->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
// good: IndexPostRequest
public function rules(): array
{
    return [
        'status'   => ['sometimes', Rule::enum(PostStatus::class)],
        'per_page' => ['sometimes', 'integer', 'between:1,100'],
    ];
}
```
- Persist only `validated()` / `safe()->only([...])`, never `all()`.
- Optional filters are `nullable` as well as `sometimes`: an empty `?status=` arrives as `null`, and a bare `'string'` rule turns "no filter" into a 422.
- Use array rules with rule objects (`Rule::unique(...)->ignore(...)`, `Rule::enum`, `Password::defaults()`) and built-in rules (`after_or_equal:from`, `size:2`) before writing closures.
- Checks that need the database or another model go in `after()`, and they stop at the first failure so the client gets one clear message per request.
- Normalise input in `prepareForValidation()`. Add typed accessors (`filters()`, `coupon()`) that read `validated()`. Memoise lookups with `once(fn () => ...)`, not hand-written `$resolved` flags.
- Omit `authorize()` when it would just `return true`.

## 3. Authorization: policies, one mechanism per controller

```php
// bad
abort_unless($post->user_id === auth()->id(), 403);
// good: App\Policies\PostPolicy is auto-discovered
public function update(User $user, Post $post): bool
{
    return $post->user()->is($user);
}
```
Choose one mechanism per controller and use it for every action: `Gate::authorize('update', $post)` in each action, route `->can('update', 'post')`, or the FormRequest's `authorize()`. Don't mix them. When an action takes a FormRequest, authorize on the route or in the request's `authorize()`: `Gate::authorize()` in the action runs after validation, so a non-owner sending a bad body gets 422 instead of 403. The 11+ base `Controller` is empty, so `$this->authorize()` / `$this->middleware()` don't exist; don't re-add the traits. Don't register policies manually.

## 3b. API routes: guards and error responses

```php
// bad: the default guest redirect calls route('login'). A client that sends no `Accept: application/json`
// (curl, a server-to-server script) gets a 500 in any app without a `login` route, and getJson() tests never see it
// good: bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
})
```
- JSON endpoints live in `routes/api.php` (`install:api`; it adds the `api/` prefix and no session, cookies or CSRF), never in `routes/web.php` and never with the `web` group. A token API uses `auth:sanctum`; a bare `auth` is the session guard. On a public route that personalises for logged-in callers, read `$request->user('sanctum')`.
- 401 = not authenticated, 403 = authenticated but not allowed (policy), 404 = not found *or* another user's record you don't reveal (scope the query, or `Response::denyAsNotFound()`), 422 = invalid input. Let the framework render them: never `catch (Exception $e) { return response()->json(['error' => $e->getMessage()]) }`. With debug off, a 404 still names the model class (`No query results for model [App\Models\Order]`). Only when the app must not reveal internals, render `NotFoundHttpException` on `api/*` with a plain message.
- Token abilities go on the route, so a token without the scope gets 403 before validation or a lookup runs: alias Sanctum's middleware in `bootstrap/app.php` (`$middleware->alias(['abilities' => CheckAbilities::class])`), run it before route-model binding (`$middleware->prependToPriorityList(SubstituteBindings::class, CheckAbilities::class)`, or a missing id answers 404 and someone else's 403), and use `->middleware('abilities:orders:write')`. Ownership stays in a policy or a query scoped to the user. Don't move `tokenCan()` into a policy or controller: they run after the FormRequest has already answered 422. `throttle:api` needs a `RateLimiter::for('api', ...)`; a login or token endpoint gets its own limiter keyed by email + IP.

## 4. One source of truth for query rules

A filter rule is written once, as a composable scope, and every query reuses it: counts, sums and joins alike. Never write a second copy of the same `where` logic in a `DB::table()` query.
```php
#[Scope]   // 12.4+; scopeCreatedBetween() before that
protected function createdBetween(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to): void
{
    $query->when($from, fn ($q, $from) => $q->where('created_at', '>=', $from))
          ->when($to, fn ($q, $to) => $q->where('created_at', '<=', $to));
}

$count    = Post::query()->createdBetween($from, $to)->published()->count();
$comments = Comment::query()->whereHas('post', fn ($q) => $q->createdBetween($from, $to)->published());
```
Use `Model::query()`, not `DB::table()`, when a model exists. Let SQL do aggregation (`sum`, `count`, `exists`, `value`, `groupBy`, `withCount/withSum`), not `->get()->sum()`. Join only the tables a query actually reads. Iterate big tables with `lazyById()` / `chunkById()`.

## 5. Models

```php
#[Fillable(['title', 'body', 'status'])]            // L13; the owner FK and derived columns stay out
#[ObservedBy(PostObserver::class)]
class Post extends Model
{
    protected function casts(): array
    {
        return ['status' => PostStatus::class, 'meta' => AsArrayObject::class, 'published_at' => 'datetime'];
    }

    protected function title(): Attribute
    {
        return Attribute::make(set: fn (string $v) => trim($v));
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
```
- Use backed enums for every fixed set of states, never class constants. Compare cases (`=== PostStatus::Published`), validate with `Rule::enum`, and set defaults with `->default(PostStatus::Draft->value)` in migrations.
- Write through relationships: `$user->posts()->create()`, `->associate()`, `whereBelongsTo`, `whereRelation`. Add the inverse `hasMany` to the parent model when you need it.
- Derived fields (slug, published_at) go in model events or an observer, so they apply on every Eloquent write. Mass writes skip model events: `where()->update()/delete()`, `insert`, `upsert`, builder `increment`, `DB::table()`. Where an observer must run (audit rows, cache busting), loop with `lazyById()` and save each model, or do the observer's work in the same method.
- No N+1: eager load what loops and resources touch (`with('author:id,name')`), use `whenLoaded()` in resources, and enable `Model::shouldBeStrict(! $this->app->isProduction())`.

## 5b. Migrations and schema

- Foreign keys start from `foreignId()`/`foreignIdFor()`. Modifiers go before `constrained()`: `->nullable()->constrained()->nullOnDelete()`. Pick `ON DELETE` on purpose: `cascadeOnDelete()` for owned children (line items, pivots), `nullOnDelete()` on a nullable column for references that must outlive the parent, the default restrict for records that must block a delete.
- Every invariant of the form "one X per Y" (one redemption per user and coupon, one row per SKU) is a unique index, and validation is only a friendly pre-check. Columns in that index are `NOT NULL`, because NULLs never collide.
- Index the columns a hot query filters and sorts on, equality columns first, then the range or sort column: `->index(['customer_id', 'status', 'created_at'])` for `where customer_id = ? and status = ? order by created_at`.
- On a table that already has production rows and running code, change the schema in steps: add the column nullable, backfill it, and switch the reads, then tighten or drop the old column in a later release. Never rename or drop a column in the same deploy as the code that stops using it. On a big MySQL table, give each DDL step its own migration (MySQL runs DDL outside transactions, so a half-applied migration can't simply re-run), create a composite index that leads with the foreign key column before adding the foreign key (MySQL then reuses it instead of building its own), and make `down()` undo every step. A new table or a pre-launch app needs none of this.
- Backfills don't use Eloquent models (their later changes break old migrations, and they fire events and touch `updated_at`). Use `DB::table()->chunkById()` or a set-based `UPDATE`, in a re-runnable command when the table is big. `->change()` restates every attribute you keep (nullable, default, unsigned). Never edit a migration that has run anywhere shared: add a new one.

## 6. Collections, and no hidden state

```php
// bad
$names = []; foreach ($users as $u) { if ($u->active) { $names[] = $u->name; } }
// good
$names = $users->filter->active->pluck('name')->values();
[$active, $idle] = $users->partition(fn (User $u) => $u->isActive());
```
A closure inside `map`/`each`/`mapWithKeys` never captures `&$var`. If a step needs a running total, use a plain `foreach` with a named accumulator, or `reduce`. Call `->values()` after `filter/reject/unique` before JSON. Format money with `Number::currency($cents / 100, in: 'USD')`, not `number_format`.

Pipelines replace loops; they are not a mandate to rewrite `array_*` calls. Never add a temp variable and `@var`, or silence the error (`@phpstan-ignore`, `assert()`, a cast), just to make a pipeline fit a declared type.
```php
// bad: the temp exists only to carry a @var
/** @var list<string> $enabled */
$enabled = collect($this->languages($account))->filter(fn (array $l): bool => $l['enabled'] ?? false)->keys()->all();

return $enabled;
// good
return array_keys(array_filter($this->languages($account), fn (array $l): bool => $l['enabled'] ?? false));
```
The framework types `->all()` as `array<TKey, TValue>` and `->keys()->all()`/`->values()->all()` as `array<int, T>`, never `list<T>`. Where a method returns or a property holds `list<…>`, a single step stays `array_map(...)` / `array_keys(array_filter(...))` / `array_values(array_filter(...))`, and a real multi-step pipeline (or the loop it replaces) returns `array_values(collect($x)->...->all())`. Widen the type to `array<int, T>` only when no caller relies on a list.

A strict `in_array(..., true)` dedupe becomes `->uniqueStrict()`, never `->unique()`, which compares loosely (`'0'`/`'00'` collapse). Leave `array_unique()` on strings as it is.

## 7. Queued jobs

- `implements ShouldQueue` + one `use Queueable;` (`Illuminate\Foundation\Queue\Queueable`). Options as attributes on 13 (`#[Tries(5), Backoff([10, 60, 300]), Timeout(120)]`), never both attribute and property. Pass models to the constructor (`#[WithoutRelations]`, and `#[DeleteWhenMissingModels]` when the row can be deleted before the job runs), services to `handle()`. `dispatch(...)->afterResponse()` is not queueing: it runs in the web process with no retries.
- `handle()` is idempotent: a job can run twice (timeout, crash, `queue:retry`). Guard on persisted state, key writes on a natural key (`updateOrCreate`, unique index, idempotency key upstream; an accumulating write such as adding points needs a claimed row, because `updateOrCreate` would add twice), and do the externally visible effect last. The code that dispatches it is idempotent too: a state change that queues work (`markAsPaid()`) claims the transition with a conditional update (`where('status', 'pending')->update(...)` returns 1) and dispatches only when it won. `SerializesModels` re-fetches by id *without global scopes*, so a soft-deleted model comes back: check `trashed()`/status.
- Nothing to do or already done → `return`. Something failed → let it throw (`Http::timeout(10)->throw()`), or `$this->fail($e)` when retrying can't help. Never catch, log and return, or store "failed" and return: no retry, no `failed_jobs`, no alert.
```php
// bad
try { $client->createShipment($this->order); } catch (RequestException $e) { Log::error($e->getMessage()); return; }
// good: already done → return; failure → throws → backoff → retry → failed()
if ($this->order->shipment()->exists()) { return; }
$this->order->shipment()->create(['reference' => $client->createShipment($this->order)]);
```
- Default is one attempt, and every `release()` (including the `RateLimited`/`WithoutOverlapping` middleware) uses one. Size `#[Tries]` or `retryUntil()` above the releases, add `#[MaxExceptions]`, and always set `#[Backoff]` (default 0 retries in a tight loop).
- Built-in job middleware, not hand-rolled throttles in `handle()`: `RateLimited` + a named `RateLimiter::for()`, `(new WithoutOverlapping($id))->releaseAfter(30)->expireAfter(180)` (defaults: release at once, lock never expires), `(new ThrottlesExceptions(10, 300))->report()` (it swallows the exception otherwise). An upstream 429 → `$this->release()` for its `Retry-After` (capped), not a throw that spends `MaxExceptions`.
- A job that tracks status on a row sets `processing` with a timestamp and defines `failed(?Throwable $e)` (it runs on a fresh instance) that marks the row failed. A killed worker never calls `failed()`, so schedule a recovery for rows stuck past N minutes.
- Timeouts nest: HTTP client timeout < `#[Timeout]` < `retry_after` (default 90; on SQS the queue's visibility timeout) < Supervisor `stopwaitsecs` / container stop grace. Restart workers on every deploy (`queue:restart` / `horizon:terminate`); process config, drivers, containers and monitoring are in `references/queues-and-workers.md`.
- Inside a transaction: `->afterCommit()` on the dispatch; queued listeners and notifications implement `ShouldQueueAfterCommit` (`ShouldHandleEventsAfterCommit` only affects *sync* listeners and observers). Chains and batches have no `afterCommit()`: dispatch them after the transaction returns.
- `ShouldBeUnique` always has `uniqueId()` and a `#[UniqueFor]` between the job's worst total run (every try plus backoff) and its schedule interval: an hourly job whose runs take up to 45 minutes gets `#[UniqueFor(3300)]`, because a lock as long as the interval, orphaned by a killed worker, silently skips the next run. Raise `retry_after` on a dedicated connection for long jobs, not the shared one. Retries must end before any claim or lock around the job expires (`retryUntil()` inside a 15-minute claim), and a long job that times out shouldn't be retried behind the next scheduled copy (`#[FailOnTimeout]`). A batch runs on one queue (set it on the batch); `then()` runs only if every job succeeded, so with `allowFailures()` do completion work in `finally()` and check `hasFailures()`. Only a failure stops a chain: `delete()` and `Skip` let the next job run.
- Mail and notifications: `Mail::to($users)` sends one message with every address in `To`, so loop and send one mailable per recipient. A `ShouldQueue` mailable or notification returns once it is queued, so a "sent" flag set next to it means "queued". The `database` channel needs `make:notifications-table`.

## 7b. Scheduler

```php
// bad: lock outlives a killed run by 24 h, runs on every server, and only guards the dispatch
Schedule::call(fn () => Report::sendDaily())->dailyAt('02:30')->withoutOverlapping();
Schedule::job(new SyncStock)->everyFiveMinutes()->withoutOverlapping();
// good (routes/console.php)
Schedule::command(SendDailyReport::class)->dailyAt('07:00')->timezone('Europe/Copenhagen')
    ->onOneServer()->withoutOverlapping(30)->pingOnFailure(config('services.alerts.url'));
Schedule::job(new SyncStock)->everyFiveMinutes()->onOneServer();   // SyncStock: ShouldQueue, ShouldBeUnique, uniqueId(), #[UniqueFor]
```
- Three different overlap guards: the scheduler's `withoutOverlapping($minutes)` (default 1440, so always pass an expiry just above the worst run), the job middleware's `expireAfter($seconds)` (default never), and `Cache::lock()`. On `Schedule::job()` the scheduler's guard covers only the dispatch.
- Several servers or containers run the scheduler → `onOneServer()`, with the lock store chosen by environment (`SCHEDULE_CACHE_STORE=redis` or `CACHE_STORE=redis` in production). Never hardcode `redis` in code or as an `env()` default: tests and local runs have no Redis, and the `array` store tests the same locks. Closures need `->name()` before `withoutOverlapping()`/`onOneServer()`. One server → neither.
- When the task or app names a business timezone other than the app's (never invent one), every time-of-day entry (`dailyAt`, `weeklyOn`, nightly cleanups) runs in it: `->timezone()` on each, or `'schedule_timezone'` in `config/app.php`, at a local hour outside the DST change (03:00 or later in EU zones), never a UTC time picked to dodge it. Scheduled output is discarded unless you send it somewhere (`->appendOutputTo(storage_path('logs/x.log'))`) or the command logs it. Alert with `onFailure()`/`pingOnFailure()`, not `thenPing()`/`after()` (they fire on any exit). On `Schedule::job()` hooks mean "dispatched", so completion handling lives in the job.
- A dispatcher selects work by persisted state (`whereNull('synced_at')`), never by a time window (missed ticks are never replayed), and claims rows (stamp a column) or dispatches unique jobs, so the next tick doesn't re-dispatch rows still in flight. Long work is a queued job or a command, never a long closure.

## 7c. Console commands

```php
// bad: work in the constructor, `return;` on an error exits 0, chunk() skips rows it deletes
// good
#[Signature('carts:prune {--days=30} {--dry-run} {--force}')]
#[Description('Delete abandoned carts older than --days')]
class PruneAbandonedCarts extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {   // asks only in production; --force skips; no TTY → cancelled
            return self::FAILURE;
        }

        $deleted = 0;
        foreach (Cart::query()->abandonedFor((int) $this->option('days'))->lazyById() as $cart) {
            $cart->delete();
            $deleted++;
        }

        $this->info("Deleted {$deleted} carts.");

        return self::SUCCESS;
    }
}
```
- Any failure exits non-zero: `return self::FAILURE` or `$this->fail('...')`, including "some rows failed" (`report($e)` each, count, then FAILURE). Never a bare `return;` or a bool on an error path: cron, ECS and `onFailure()` only see the exit code. No work in the constructor (every `artisan` call builds every command); inject services into `handle()`.
- Only commands that people run by hand against production data get `ConfirmableTrait` + `{--force}` and a `--dry-run` that reports and changes nothing. A cleanup only the scheduler runs needs neither (scheduling it with `--force` is the smell), and read-only commands never do. Every prompted value is also an argument or option (cron has no TTY). Laravel Prompts' `confirm()` defaults to **true**, so pass `default: false` before anything destructive.
- Loops that update or delete the rows they filter on use `lazyById()`/`chunkById()`, never `chunk()` (offset pages skip rows). A command that must never run twice at once implements `Isolatable` or gets `withoutOverlapping()` in the schedule. `Isolatable` locks before `handle()`, so the lock also covers a `--dry-run` and an open confirmation prompt: a command people preview by hand takes a `Cache::lock()` around the deleting part instead.

## 7d. Files and uploads

- Validate every upload with a type allow-list and a size cap: `File::types(['pdf', 'png', 'jpg'])->max('5mb')`, or `['file', 'mimes:csv,txt', 'max:5120']`. `mimes` checks the type sniffed from the content, so a CSV can sniff as `text/plain` (a header-only, single-column or semicolon file): always allow `txt` next to `csv`. Never pick a parser or a stored name from `getClientOriginalExtension()`/`getClientOriginalName()`.
- Store with `$file->store('documents', 'local')` (a random `hashName()`), naming the private disk every time you store, check or serve the file (the skeleton's `local` is `storage/app/private`; the default disk can be changed by config). Keep the client's name in a column if you need it. The `public` disk is only for files every anonymous visitor may read. Serve a private file from an authorized route with `Storage::disk('local')->download($path, $name)`, or a link that expires (`URL::temporarySignedRoute(..., now()->addMinutes(10), ...)` behind the `signed` middleware, or `Storage::temporaryUrl()`): a plain `signedRoute()` never expires. Check `exists()` on the same disk first, because `download()` on a missing file is a 500.
- Rows from files and payloads are hostile. Strip a UTF-8 BOM from the first line, then find columns by name, with headers trimmed and lower-cased. When fields never contain line breaks (most feeds), read line by line and parse each with `str_getcsv()`, so line numbers stay physical and one stray quote breaks one row, not the rest of the file. Skip blank and whitespace-only lines, check the column count before `array_combine`, and check each row with a few explicit tests whose message names the column and value (a `Validator` per row is heavy for a big file and its messages are generic), reporting the file's line number. Import the good rows unless the brief says all-or-nothing. Read with a stream (`fopen`/`SplFileObject`/`Storage::readStream()`), close the handle in `finally`, and store the file before queueing its processing (a job gets the path, never the `UploadedFile`).

## 8. Caching

```php
// bad: race, and no TTL means forever
if (! Cache::has('stats:users')) { Cache::put('stats:users', User::count()); }
// good
$count = Cache::remember("team:{$team->id}:stats:users", now()->addMinutes(5), fn () => $team->users()->count());
```
- Always pass a TTL. `remember()` re-runs a closure that returns `null`, so cache a sentinel. `Cache::flexible($key, [300, 3600], ...)` for hot values that may be briefly stale.
- Keys are namespaced (`domain:entity:id:variant`), include every input and the tenant/user id, and get a `:v2` segment when the payload shape changes.
- Cache arrays or ids, not models (`serializable_classes => false`). Invalidate with `forget()` from an observer (`ShouldHandleEventsAfterCommit`), never `Cache::flush()`. `tags()` throws on the default `database` store.
- Atomic: `add()` for once-per-window guards, `add(key, 0, ttl)` + `increment()` for counters. Locks always get seconds and release in `finally` (or pass a closure). Rate limiters are named `RateLimiter::for()` keyed by user id or IP.
- Memoize repeated reads per request with `Cache::memo()`; tests use the array store plus `travel()`, never a mocked `remember`.

## 8b. Races: let the database decide

Applies to contended counters, caps, "one per user" rows and state changes with side effects. A plain CRUD write needs none of it.
```php
// bad: two requests both see a free seat (and no booking) and both book; each sends a confirmation
if ($workshop->booked < $workshop->capacity && ! $workshop->bookings()->whereBelongsTo($user)->exists()) { /* create, increment, mail */ }
// good: lock the parent row first, then decide; a unique index on (workshop_id, user_id) backs the "once" rule
$booking = DB::transaction(function () use ($workshop, $user) {
    $workshop = Workshop::whereKey($workshop->id)->lockForUpdate()->firstOrFail();
    $booking = $workshop->bookings()->firstOrNew(['user_id' => $user->id]);
    if ($booking->exists) {
        return $booking;   // the repeat request gets the same booking, 200
    }
    throw_if($workshop->booked >= $workshop->capacity, new WorkshopFull);   // rendered as a 409
    $booking->save();
    $workshop->increment('booked');

    return $booking;
}, 3);
if ($booking->wasRecentlyCreated) { BookingConfirmed::dispatch($booking); }   // only the request that created it
```
- Decide from the database's answer: `createOrFirst()` + `wasRecentlyCreated` (needs the unique index, and only the key columns as its first argument), `insertOrIgnore() === 1`, or `where('status', 'paid')->update(['status' => 'shipped']) === 1`. Never `exists()`/`if ($model->status ...)` then write, and never send, charge or dispatch before the claim succeeded. `firstOrCreate` without a unique index is check-then-insert, and the `unique` validation rule is only a pre-check.
- A limit on a single row lives in the `UPDATE`'s `WHERE` (`->where('stock', '>=', $qty)->decrement('stock', $qty)` returns 0 or 1). When the decision also inserts a child row, lock the parent first as above: inserting the child first takes a shared foreign-key lock on the parent, and two requests then deadlock on the parent's `UPDATE`. After `$model->increment()`, the model holds the loaded value + n, not the database's value, so never branch on it.
- `lockForUpdate()` holds only inside `DB::transaction()`: re-read the row with the lock there, then decide. Use the closure form, not `beginTransaction()`, so an early return can't leave it open. `DB::transaction($fn, 3)` re-runs the whole closure after a deadlock, so the closure holds no HTTP calls, mail or dispatches (`afterCommit()`). SQLite compiles locks to nothing, so a passing SQLite test proves nothing about a lock: also test the outcome with the competing write injected (§13).

## 8c. Outbound HTTP

```php
// bad: 30 s default timeout per attempt, retries a 404 or 422 too, and reads the body of a 500
$rates = Http::retry(3)->get(config('services.carrier.url').'/rates', ['postcode' => $postcode])->json('rates');
// good: one class per integration, settings in config/services.php
public function rates(string $postcode): array
{
    return Http::baseUrl(config('services.carrier.base_url'))->withToken(config('services.carrier.key'))
        ->timeout(2)->connectTimeout(1)   // per attempt: attempts × timeout + sleeps must fit the caller's budget
        ->retry(3, 200, fn (?Throwable $e) => $e instanceof ConnectionException
            || ($e instanceof RequestException && ($e->response->serverError() || $e->response->tooManyRequests())))
        ->get('/v2/rates', ['postcode' => $postcode])   // after the last attempt, retry() throws by itself
        ->json('rates');
}
```
- The client never throws on 4xx/5xx by itself: `->throw()` or check `->failed()` before reading a body. `retry($times)` counts attempts, not retries, and by default retries every failure, so always pass `when`; after the last attempt it throws `RequestException` (or `ConnectionException`, which has no response). Catch them only where you have a fallback or a mapping (404 → 422, fallback rate). `report()` only what a person must act on (a rejected key, a changed contract), not the transient failures the fallback already handles, and give expected domain exceptions (`UnknownPostcode`) `ShouldntReport`.
- Retry a POST that creates something only with an idempotency key the partner honours. Never call HTTP inside `DB::transaction()`: commit first, or dispatch a queued job with `afterCommit()` for slow or optional calls.
- Tests, when the app makes HTTP calls: `Http::preventStrayRequests()` once for the whole suite (`tests/Pest.php` or the base `TestCase`), not in each test file or in an app that calls nothing, a fake for every host (the first matching stub wins), `Http::sequence()` or `Http::failedConnection()` for failures, `Http::assertSentCount()` to prove what was retried, and `Sleep::fake()` so retry sleeps don't slow the suite.

## 9. Blade

- Views only render: queries, eager loading (`with`, `withCount`) and `paginate()->withQueryString()` happen in the controller. Shared data goes through a view composer.
- Layouts and reusable markup are components. Anonymous components declare `@props` and put `$attributes->merge()`/`->class()` on the root element. Pass PHP values with a colon (`:$user`).
- Forms: `@csrf` on every non-GET form, `method="POST"` + `@method('PUT')`, `old('title', $post->title)`, `@error`, `@checked`/`@selected`, `route('posts.edit', $post)`. After a POST, redirect with a flash (`to_route(...)->with('status', ...)`), never `return view()`.
- `{{ }}` always; `{!! !!}` only for HTML you sanitised. PHP → JS via `@js()` / `Js::from()`. `@class([...])` instead of `@if` inside `class=""`. `@can`, not inline ownership checks.

## 10. Filament (v4/v5)

Check `filament/filament` in `composer.lock`, scaffold with `make:filament-resource X --generate`, and use the v4/v5 API, not v3:
```php
// bad (v3)
use Filament\Forms\Form; use Filament\Tables\Actions\EditAction; use Filament\Forms\Components\Section;
public static function form(Form $form): Form { return $form->schema([...]); }
->actions([EditAction::make()])->bulkActions([...])
// good
use Filament\Schemas\Schema; use Filament\Actions\EditAction; use Filament\Schemas\Components\Section;
public static function form(Schema $schema): Schema { return CustomerForm::configure($schema); }   // ->components([...])
->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
```
- Resource folder per model with `Schemas/` and `Tables/` classes; action modals use `->schema()`, pages `getHeaderActions()`, widget properties are non-static.
- Enums implement `HasLabel`/`HasColor`/`HasIcon`; columns use dot notation and formatters, belongs-to fields `->relationship()`.
- Policies cover built-in pages and actions only: custom `Action`s get `->authorize()`/`->visible()`, inline-edit columns `->disabled()`. Scope data in `getEloquentQuery()`, and `User implements FilamentUser`.
- Test as Livewire components: `fillForm()->call('create')->assertHasNoFormErrors()`, `callAction(TestAction::make('x')->table($record))`, `assertCanSeeTableRecords`.

## 11. Skeleton, config, helpers, style

- Put middleware, exception rendering and `shouldRenderJsonWhen` in `bootstrap/app.php`, schedules in `routes/console.php`, and providers in `bootstrap/providers.php`. Listeners are auto-discovered under `Application::configure()` (a `handle*`/`__invoke` method whose first parameter has a class type; `handle(object $event)` is skipped), so don't also register them. Call `env()` only in `config/*.php`.
- Helpers: `Arr::wrap`, `data_get`, `str()->slug()`, `filled()`, `abort_unless`, `throw_if`, `?->`, `once()`, `Number::*`.
- Style (Pint laravel preset): guard clauses, no `else`, `match` over `switch`, native types everywhere, docblocks only for generics or array shapes, constructor promotion, a blank line before `return`, and typed return types on every controller action.

## 12. Where logic goes: no fat, no ceremony

| Situation | Put it in |
|---|---|
| CRUD | controller + FormRequest + Eloquent via relationship |
| Several queries/aggregations or a computed response | one invokable class named for its output (`BuildTeamStats`, `CalculateInvoiceTotals`) returning an array or `JsonResource` |
| Rule belonging to one model | model method / scope / observer |
| Logic shared by 2+ callers, or a queue boundary | action or job |

Don't add a repository, a service interface, or a DTO whose only job is `toArray()` for JSON. Return an array or a `JsonResource` instead. Don't wrap a single `create()` in an action. Don't add `final`/`strict_types` unless the project already uses them. An event earns its place when one fact has two or more independent effects or crosses a module boundary; one effect that must succeed with the request is a direct call (but keep an event the brief asks for). Register each listener once: discovery plus `Event::listen()` runs it twice.

## 13. Tests prove every requirement and every failure

**Match the framework.** `tests/Pest.php` or `pestphp/pest` present → Pest closures only (`it()`, `expect()`, datasets, non-`static` closures, `RefreshDatabase` bound once in `Pest.php`), never a PHPUnit class. PHPUnit-only suite → PHPUnit classes; don't convert unless asked. Write a feature test for each requirement in the brief, plus:
- **each failure path**: 401 guest, 403 non-owner, and 422 for each validation rule. Assert the specific message or key (`assertJsonValidationErrors(['email' => 'already been taken'])`), not just that some error occurred;
- **limits and boundaries**: top-N caps, pagination bounds, empty results, zero/rounding edges, malformed rows;
- **side effects**: `Queue::fake()` + `assertPushed`, `Mail::fake()`, `Storage::fake('<the disk the code writes to>')`, `Event::fake([OnlyThis::class])` (a bare `Event::fake()` also silences observers), and state left unchanged when an action fails. Fakes never render mail, so render each mailable once and assert its content;
- **API and races**: guests both with `getJson()` and with a plain `get()` (no `Accept` header); `Sanctum::actingAs($user, ['orders:read'])` (no abilities by default); a race simulated by a one-shot model event that writes the competing row between your read and your write.
```php
// bad
Comment::factory()->create(['post_id' => $post->id, 'user_id' => $user->id]);
// good
Comment::factory()->for($post)->for($user, 'author')->create();
User::factory()->has(Post::factory()->published()->count(2))->create();
```
Use factory states and `->for()`/`->has()` rather than raw foreign-key arrays. Build models with factories, never `Model::create` in tests. Use status helpers (`assertCreated`, `assertForbidden`, `assertUnprocessable`) and `assertJsonPath`/`AssertableJson`. Expect roughly as many tests as the endpoint has rules and branches. A small suite with no failure cases is incomplete.

## Before you finish (mandatory: fix every miss before reporting done)

Run `php artisan test` and `vendor/bin/pint --dirty` if present. Then go through this list against the actual files. In audit mode the list is the recall floor: every item becomes a finding or an explicitly cleared entry, with evidence, in the report's Coverage section (`references/audit-mode.md` §5). Items for areas the app doesn't use are `n/a`.

- [ ] **Controllers:** every action is ≤ ~10 lines, has a return type, and makes one query or call. `grep -n "private function\|DB::" app/Http/Controllers` finds no query or format helpers.
- [ ] **Validation:** every endpoint that reads input, including GET filters and `per_page`, has a FormRequest. Only `validated()`/`safe()` reaches the database. Built-in rules are used before closures, and there is no boilerplate `authorize()`.
- [ ] **Authorization:** policy-based, with the same mechanism in every action of a controller. No `$this->authorize()` on an 11+ base controller.
- [ ] **Queries:** each filter rule exists once, as a scope. No `DB::table()` duplicates a model query, no joins the query doesn't read, no N+1.
- [ ] **Models:** enums (not constants) with casts, `casts()` method, L13 attributes in every new model, writes go through relationships, and the inverse relations exist.
- [ ] **Collections:** no accumulate-foreach, no `&$` in collection closures, no temp + `@var` to fit a pipeline to `list<…>`, and strict `in_array` checks stay strict (`uniqueStrict`, `containsStrict`). Money uses `Number::currency`.
- [ ] **Jobs:** failures throw or `fail()` (no catch-log-return), `handle()` is idempotent (persisted guard, natural keys, `trashed()`), tries/`retryUntil` fit every release path with backoff, `WithoutOverlapping` has `expireAfter()`, status rows get `failed()` plus a stuck-row recovery, timeout < `retry_after`/visibility timeout, `afterCommit`/`ShouldQueueAfterCommit` inside transactions, batch completion in `finally()` under `allowFailures()`, unique jobs have `uniqueId()` + `#[UniqueFor]`.
- [ ] **Scheduler and commands:** `grep -n "withoutOverlapping()" routes/console.php` finds nothing; multi-server tasks use `onOneServer()` on a shared lock store; `Schedule::job()` jobs are `ShouldQueue` and unique; dispatchers claim rows; every command failure path returns non-zero; no constructor work; `--force`/`--dry-run` only on destructive commands; `lazyById`/`chunkById` when mutating the filtered rows.
- [ ] **API:** JSON routes in `routes/api.php` with `auth:sanctum` (no `web` group), `redirectGuestsTo()` returns null for `api/*` (a plain `get()` as a guest is a JSON 401, not a 500), token abilities as route middleware, 403 vs 404 deliberate, no `getMessage()` in a response, no model class names in a 404.
- [ ] **Races:** `grep -n "exists()\|firstOrCreate\|->increment(" app` and check each: a unique index, a guarded `UPDATE` or a lock on the parent row decides, side effects only after a won claim, `lockForUpdate()` only inside `DB::transaction()`, no HTTP/mail/dispatch inside a retried transaction.
- [ ] **Files:** every upload has a type allow-list and a size cap, files stored under `hashName()` on a private disk, downloads authorized or behind an expiring signature, `exists()` before `download()`, CSV readers strip the BOM, match headers by trimmed lower-case name, check column counts, report line numbers and close handles in `finally`.
- [ ] **Schema:** FKs with an explicit `ON DELETE`, unique indexes for every one-per-X rule (no nullable key column), indexes for hot filters, nullable-then-backfill on tables with rows, backfills without models and re-runnable, no edits to shipped migrations.
- [ ] **HTTP client:** every call has `timeout()` sized for its caller, `retry()` has a `when` that skips 4xx, responses are checked (`throw()`/`failed()`) before use, no call inside a transaction, tests use `preventStrayRequests()`.
- [ ] **Events and mail:** observers aren't relied on for mass writes, one mailable per recipient, mail/notifications/listeners after commit, `notifications` table present for the `database` channel, each listener registered once.
- [ ] **Caching:** every write has a TTL, keys are namespaced and tenant-scoped, no cached models, no `flush()`, no `has()`+`put()` race, locks have seconds and release in `finally`, no tags on a non-tag store.
- [ ] **Blade:** no queries or lazy loads in views, `@props` + root `$attributes`, `@csrf`/`@method`/`old()`/`@error` on forms, redirect after POST, no `{!! !!}` on user data.
- [ ] **Filament:** no v3 namespaces or deprecated methods, enums carry label/colour/icon, custom actions and inline-edit columns authorized, data scoped in `getEloquentQuery()`, `FilamentUser` on User.
- [ ] **Tests:** each requirement, each 401/403/422 with the message asserted, limits, empty and edge cases, fakes for queue/mail/storage, factories with `->for()`/states. Jobs: `handle()` run twice, `failed()` called. Schedules asserted via `app(Schedule::class)->events()`. Every command path through `$this->artisan(...)->assertExitCode()`, plus its side effect.
- [ ] **Pest:** the suite's framework is matched; in Pest, no `static` closures, no repeated `uses()`, DB-dependent dataset values are closures, `expect()` chains, `->throws()` over try/catch, no committed `->only()`.
- [ ] **Ceremony:** no layer, DTO or interface the code doesn't need, and no controller doing a class's job.
- [ ] Every API you used exists in the version in `composer.lock`.

## References: load only what the task needs

| Task | Load |
|---|---|
| Loops, collections, `Arr`/`Str`/`Number`, `tap`/`retry`/`once` | `references/collections-and-helpers.md` |
| Controllers, routes, FormRequests, policies, API resources, API auth and error responses | `references/http-layer.md` |
| Models, casts, relations, scopes, queries, factories | `references/eloquent.md` |
| Where logic goes, DTOs, enums, container, test coverage | `references/architecture-and-testing.md` |
| Jobs, retries, job middleware, uniqueness, chains/batches, events, notifications, workers, drivers, containers, Horizon | `references/queues-and-workers.md` |
| `routes/console.php` schedules, overlap/one-server locks, timezones, cron/`schedule:work`, schedule tests | `references/scheduling.md` |
| Artisan commands: signatures, prompts, exit codes, destructive-command guards, big tables, signals, command tests | `references/console-commands.md` |
| `Cache::*`, keys and TTLs, invalidation, locks, `memo`/`once`, rate limiters | `references/caching.md` |
| Blade views, components, layouts, forms, escaping, pagination | `references/blade.md` |
| Filament resources, schemas, tables, actions, policies, Filament tests | `references/filament.md` |
| Pest config, expectations, datasets, mocks, arch tests, PHPUnit → Pest | `references/pest.md` |
| Races, locks, transactions, atomic updates, idempotency keys, testing races | `references/concurrency.md` |
| Uploads, private disks, signed downloads, CSV import and export | `references/files-and-uploads.md` |
| Migrations, foreign keys, indexes, zero-downtime changes, backfills | `references/migrations.md` |
| Outbound HTTP: client classes, timeouts, retries, fakes | `references/http-client.md` |
| Events, listeners, observers, notifications, mail and their tests | `references/events-and-notifications.md` |
| Upgrading, `bootstrap/app.php`, Laravel 11-13 attributes, outdated patterns | `references/modern-laravel.md` |
| Formatting, naming, control flow, exceptions | `references/style.md` |
| Evaluating a codebase / producing a refactor report (HTML) | `references/audit-mode.md` |
