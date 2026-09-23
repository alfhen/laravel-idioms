---
name: laravel-idioms
description: Idiomatic modern Laravel (11-13) conventions for PHP. Use whenever writing, editing, reviewing or refactoring code in a Laravel app (controllers, form requests, models, queries, jobs, queues and workers, Horizon, listeners, policies, caching, locks, rate limiters, Blade views and components, Filament 5 resources/tables/actions, Pest or PHPUnit tests, bootstrap/app.php), and whenever asked to audit or evaluate a Laravel codebase, find code smells, or produce refactor suggestions or a refactor report (delivered as a self-contained HTML report). Covers thin controllers, FormRequests and policies, collections over loops, Eloquent casts()/attributes/scopes, N+1 prevention, job retries/timeouts/idempotency, cache TTLs/keys/locks, Blade components and forms, Filament v4/v5 APIs, Pest style, test coverage, current skeleton structure, and Spatie-style formatting. Complements Laravel Boost's laravel-best-practices skill, so load both when both are installed.
---

# Laravel idioms

Write code the way the Laravel framework, its docs and well-maintained packages (laravel.io, Spatie) write it. Match the project's existing conventions and Laravel version first; these rules decide everything the project hasn't already decided.

**With Laravel Boost:** load this skill *and* `laravel-best-practices`. Boost's rule files cover security, migrations, the HTTP client, error handling, mail and scheduling. This skill adds the concrete rules below, the Laravel 13 / Filament 5 / Pest 5 API specifics, and the mandatory "Before you finish" checklist. Where the two differ on a specific API or pattern, follow this skill; verify version-sensitive APIs with Boost's `search-docs` when it is available.

**Audit or refactor-report request?** ("evaluate this codebase", "suggest refactors", "audit this app") → follow `references/audit-mode.md`. It ends in a self-contained HTML report built from `assets/audit-report-template.html`.

## 0. Check the version, then commit to its style

Read `laravel/framework` in `composer.lock`. The 11+ skeleton has no `Http/Kernel.php`, `Console/Kernel.php`, `Exceptions/Handler.php`, `RouteServiceProvider`, `AuthServiceProvider` or `EventServiceProvider`, and no `routes/api.php` until `php artisan install:api`. On **Laravel 13**, every new model, job and command uses attributes (`#[Fillable]`, `#[Hidden]`, `#[Scope]`, `#[ObservedBy]`, `#[Tries]`, `#[Signature]`), and you use them in every new file, not only some. On older versions, or where existing code uses properties, keep properties.

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
#[Scope]
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

## 7. Queues, workers and external input

- `implements ShouldQueue` + one `use Queueable;` (`Illuminate\Foundation\Queue\Queueable`). Options as attributes on 13 (`#[Tries(5), Backoff([10, 60, 300]), Timeout(120)]`), never both attribute and property. Pass models to the constructor (`#[WithoutRelations]`, `#[DeleteWhenMissingModels]` if it may be deleted), services on `handle()`.
- `handle()` is idempotent: a job can run twice (timeout, crash, `queue:retry`). Check state before side effects; pass idempotency keys.
- Default is one attempt, and every `release()` (including `RateLimited`/`WithoutOverlapping` middleware) uses one: such jobs need more `#[Tries]` or `retryUntil()`, plus `#[MaxExceptions]`. Permanent errors: `$this->fail()` / `FailOnException`.
- `#[Timeout]` < `retry_after` (default 90) < Supervisor `stopwaitsecs`, or the job runs twice concurrently. Set HTTP client timeouts too.
- Dispatch inside a transaction with `->afterCommit()` (listeners: `ShouldQueueAfterCommit`). `ShouldBeUnique` always has `uniqueId()` and `#[UniqueFor]`. Use built-in job middleware (`RateLimited`, `WithoutOverlapping(...)->expireAfter()`, `ThrottlesExceptions`, `Skip`/`Release`), not hand-rolled throttles in `handle()`.
- A job that tracks status on a model must define `failed(?Throwable $e)` that sets the failed status (it runs on a fresh instance). Otherwise a crash leaves the record stuck at "processing".
- Workers: `queue:work` under Supervisor with `--max-time`/`--tries`, `queue:restart` (or `horizon:terminate`) on every deploy, and scheduled `queue:prune-failed`/`queue:prune-batches`.
- Treat files and payloads as hostile. Check the shape before you use it: skip blank rows, and pad or reject rows whose column count differs from the header (`array_combine` throws `ValueError` on a mismatch, so `?: []` does nothing). Report positions the user can find, such as file line numbers.
- Read files with `Storage::readStream()`, not `Storage::path()`. Mailables use `envelope()`/`content()`.
- Validate row data with `Validator::make($row, $rules)`, not hand-written `if` chains, when you need more than two rules.

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
- [ ] **Collections:** no accumulate-foreach and no `&$` captured in collection closures. Money uses `Number::currency`.
- [ ] **Jobs:** `Queueable`, `failed()` resets status, input shape is checked before use, `readStream`.
- [ ] **Queues/workers:** idempotent `handle()`, tries/`retryUntil` fit every release path, backoff set, timeout < `retry_after`, `afterCommit` inside transactions, unique jobs have `uniqueId()` + `#[UniqueFor]`, workers restarted on deploy.
- [ ] **Caching:** every write has a TTL, keys are namespaced and tenant-scoped, no cached models, no `flush()`, no `has()`+`put()` race, locks have seconds and release in `finally`, no tags on a non-tag store.
- [ ] **Blade:** no queries or lazy loads in views, `@props` + root `$attributes`, `@csrf`/`@method`/`old()`/`@error` on forms, redirect after POST, no `{!! !!}` on user data.
- [ ] **Filament:** no v3 namespaces or deprecated methods, enums carry label/colour/icon, custom actions and inline-edit columns authorized, data scoped in `getEloquentQuery()`, `FilamentUser` on User.
- [ ] **Tests:** each requirement, each 401/403/422 with the message asserted, limits, empty and edge cases, fakes for queue/mail/storage, factories with `->for()`/states.
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
| Jobs, retries, job middleware, uniqueness, chains/batches, events, notifications, workers, Horizon | `references/queues-and-workers.md` |
| `Cache::*`, keys and TTLs, invalidation, locks, `memo`/`once`, rate limiters | `references/caching.md` |
| Blade views, components, layouts, forms, escaping, pagination | `references/blade.md` |
| Filament resources, schemas, tables, actions, policies, Filament tests | `references/filament.md` |
| Pest config, expectations, datasets, mocks, arch tests, PHPUnit → Pest | `references/pest.md` |
| Upgrading, `bootstrap/app.php`, Laravel 11-13 attributes, outdated patterns | `references/modern-laravel.md` |
| Formatting, naming, control flow, exceptions, commands | `references/style.md` |
| Evaluating a codebase / producing a refactor report (HTML) | `references/audit-mode.md` |
