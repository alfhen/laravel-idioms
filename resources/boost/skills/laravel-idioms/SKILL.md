---
name: laravel-idioms
description: Use for any work in a Laravel codebase, whether reviewing, planning, building, refactoring, debugging, speeding up or testing PHP code in a project with an artisan file or laravel/framework in composer.json, even when the user never says "Laravel". Load it before planning a change or answering a question about the code, not only before editing. Also use it to audit a Laravel codebase, judge its health, find code smells or write a refactor report (a self-contained HTML report). Gives concrete rules for Laravel 11-13 covering thin controllers, Form Requests, policies, collections over loops, Eloquent casts/scopes/attributes, N+1 and slow queries, jobs, queues and workers, scheduled tasks, Artisan commands, caching and locks, Blade components, Filament 5, Pest and PHPUnit tests and the current skeleton structure, plus a mandatory "Before you finish" checklist. Not for PHP projects that aren't Laravel. Complements Laravel Boost's laravel-best-practices skill, so load both when both are installed.
---

# Laravel idioms

Write code the way the Laravel framework, its docs and well-maintained packages (laravel.io, Spatie) write it. Match the project's existing conventions and Laravel version first; these rules decide everything the project hasn't already decided.

**With Laravel Boost:** load this skill *and* `laravel-best-practices`. Boost's rule files cover security, migrations, the HTTP client, error handling and mail. This skill adds the concrete rules below, the Laravel 13 / Filament 5 / Pest 5 API specifics, and the mandatory "Before you finish" checklist. Where the two differ on a specific API or pattern, follow this skill; verify version-sensitive APIs with Boost's `search-docs` when it is available.

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
Choose one mechanism per controller and use it for every action: `Gate::authorize('update', $post)` in each action, route `->can('update', 'post')`, or the FormRequest's `authorize()`. Don't mix them. The 11+ base `Controller` is empty, so `$this->authorize()` / `$this->middleware()` don't exist; don't re-add the traits. Don't register policies manually.

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
- Derived fields (slug, published_at) go in model events or an observer, so they apply on every write path.
- No N+1: eager load what loops and resources touch (`with('author:id,name')`), use `whenLoaded()` in resources, and enable `Model::shouldBeStrict(! $this->app->isProduction())`.

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
- `handle()` is idempotent: a job can run twice (timeout, crash, `queue:retry`). Guard on persisted state, key writes on a natural key (`updateOrCreate`, unique index, idempotency key upstream), and do the externally visible effect last. The code that dispatches it is idempotent too: a state change that queues work (`markAsPaid()`) claims the transition with a conditional update (`where('status', 'pending')->update(...)` returns 1) and dispatches only when it won. `SerializesModels` re-fetches by id *without global scopes*, so a soft-deleted model comes back: check `trashed()`/status.
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
- Rows from files and payloads are hostile: check the column count before `array_combine`, validate with `Validator::make($row, $rules)`, report file line numbers, read with `Storage::readStream()`.

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

- Put middleware, exception rendering and `shouldRenderJsonWhen` in `bootstrap/app.php`, schedules in `routes/console.php`, and providers in `bootstrap/providers.php`. Listeners are auto-discovered, so don't also register them. Call `env()` only in `config/*.php`.
- Helpers: `Arr::wrap`, `data_get`, `str()->slug()`, `filled()`, `abort_unless`, `throw_if`, `?->`, `once()`, `Number::*`.
- Style (Pint laravel preset): guard clauses, no `else`, `match` over `switch`, native types everywhere, docblocks only for generics or array shapes, constructor promotion, a blank line before `return`, and typed return types on every controller action.

## 12. Where logic goes: no fat, no ceremony

| Situation | Put it in |
|---|---|
| CRUD | controller + FormRequest + Eloquent via relationship |
| Several queries/aggregations or a computed response | one invokable class named for its output (`BuildTeamStats`, `CalculateInvoiceTotals`) returning an array or `JsonResource` |
| Rule belonging to one model | model method / scope / observer |
| Logic shared by 2+ callers, or a queue boundary | action or job |

Don't add a repository, a service interface, or a DTO whose only job is `toArray()` for JSON. Return an array or a `JsonResource` instead. Don't wrap a single `create()` in an action. Don't add `final`/`strict_types` unless the project already uses them.

## 13. Tests prove every requirement and every failure

**Match the framework.** `tests/Pest.php` or `pestphp/pest` present → Pest closures only (`it()`, `expect()`, datasets, non-`static` closures, `RefreshDatabase` bound once in `Pest.php`), never a PHPUnit class. PHPUnit-only suite → PHPUnit classes; don't convert unless asked. Write a feature test for each requirement in the brief, plus:
- **each failure path**: 401 guest, 403 non-owner, and 422 for each validation rule. Assert the specific message or key (`assertJsonValidationErrors(['email' => 'already been taken'])`), not just that some error occurred;
- **limits and boundaries**: top-N caps, pagination bounds, empty results, zero/rounding edges, malformed rows;
- **side effects**: `Queue::fake()` + `assertPushed`, `Mail::fake()`, `Storage::fake()`, and state left unchanged when an action fails.
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
- [ ] **Jobs:** failures throw or `fail()` (no catch-log-return), `handle()` is idempotent (persisted guard, natural keys, `trashed()`), tries/`retryUntil` fit every release path with backoff, `WithoutOverlapping` has `expireAfter()`, status rows get `failed()` plus a stuck-row recovery, timeout < `retry_after`/visibility timeout, `afterCommit`/`ShouldQueueAfterCommit` inside transactions, batch completion in `finally()` under `allowFailures()`, unique jobs have `uniqueId()` + `#[UniqueFor]`, file rows checked before use.
- [ ] **Scheduler and commands:** `grep -n "withoutOverlapping()" routes/console.php` finds nothing; multi-server tasks use `onOneServer()` on a shared lock store; `Schedule::job()` jobs are `ShouldQueue` and unique; dispatchers claim rows; every command failure path returns non-zero; no constructor work; `--force`/`--dry-run` only on destructive commands; `lazyById`/`chunkById` when mutating the filtered rows.
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
| Controllers, routes, FormRequests, policies, API resources | `references/http-layer.md` |
| Models, casts, relations, scopes, queries, factories | `references/eloquent.md` |
| Where logic goes, DTOs, enums, container, test coverage | `references/architecture-and-testing.md` |
| Jobs, retries, job middleware, uniqueness, chains/batches, events, notifications, workers, drivers, containers, Horizon | `references/queues-and-workers.md` |
| `routes/console.php` schedules, overlap/one-server locks, timezones, cron/`schedule:work`, schedule tests | `references/scheduling.md` |
| Artisan commands: signatures, prompts, exit codes, destructive-command guards, big tables, signals, command tests | `references/console-commands.md` |
| `Cache::*`, keys and TTLs, invalidation, locks, `memo`/`once`, rate limiters | `references/caching.md` |
| Blade views, components, layouts, forms, escaping, pagination | `references/blade.md` |
| Filament resources, schemas, tables, actions, policies, Filament tests | `references/filament.md` |
| Pest config, expectations, datasets, mocks, arch tests, PHPUnit → Pest | `references/pest.md` |
| Upgrading, `bootstrap/app.php`, Laravel 11-13 attributes, outdated patterns | `references/modern-laravel.md` |
| Formatting, naming, control flow, exceptions | `references/style.md` |
| Evaluating a codebase / producing a refactor report (HTML) | `references/audit-mode.md` |
