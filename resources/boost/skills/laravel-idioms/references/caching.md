# Caching, locks and rate limiting

## Read-through

```php
// bad: race-prone, and put() with no TTL is forever
if (Cache::has('stats:users')) {
    $count = Cache::get('stats:users');
} else {
    $count = User::count();
    Cache::put('stats:users', $count);
}

// good
$count = Cache::remember('stats:users:total', now()->addMinutes(5), fn () => User::query()->count());
```
- **Always pass a TTL.** `put()` / `cache(['k' => $v])` with `$ttl === null` calls `forever()`. A zero or negative TTL deletes the key. `rememberForever` only when an explicit invalidation path exists.
- `remember()` treats `null` as a miss (`is_null` check), so a closure that returns null re-runs every call. Cache a sentinel: `fn () => User::where('email', $email)->value('id') ?? false`, then `$id ?: null`.
- Hot, expensive values that may be slightly stale → `Cache::flexible('dashboard:revenue', [300, 3600], fn () => ...)`: fresh for 5 min, then served stale up to 1 h while a deferred function recomputes under a lock (one worker refreshes). The TTL is an array, not an int. Use `remember()` when data must never be stale.
- Known type → typed getters `Cache::integer('plan:limit', 10)`, `string`, `boolean`, `array`, `float`. They throw `InvalidArgumentException` on mismatch instead of passing a surprise value on.
- `cache('key')` gets, `cache([k => v], $ttl)` puts, `cache()` returns the manager.

## Keys

- Colon-separated namespaced segments that include every input changing the result: `"widgets:articles:published:{$window}"`. No bare global keys (`'users'`, `'topMembers'`).
- Tenant or user data is scoped by id: `"team:{$team->id}:dashboard:stats"`. `cache.prefix` separates apps, not tenants.
- Keep the key literal in one place (a method, a constant, or a backed enum; keys accept `UnitEnum`).
- Payload shape changed → bump a version segment (`"product:{$id}:card:v2"`) instead of flushing. Old entries expire on their own.

## Invalidation

```php
class PlanObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Plan $plan): void
    {
        Cache::forget("plans:{$plan->id}:v1");
        Cache::forget('plans:all:v1');
    }

    public function deleted(Plan $plan): void
    {
        $this->saved($plan);
    }
}
```
- `forget()` the specific keys from model events (observer or `static::saved/deleted` in a booted trait). `ShouldHandleEventsAfterCommit` so a concurrent reader can't re-cache pre-commit data.
- **Never `Cache::flush()` in app code.** It ignores the prefix and wipes the whole store: sessions, locks, other apps' keys.
- Aggregates many writes touch (counts, leaderboards) → a short TTL per value (60 s for "awaiting", 300 s for totals) instead of forgetting in six observers.

## What to cache

```php
// bad: Eloquent graph in the cache
Cache::remember('latestThreads', 3600, fn () => Thread::with('author', 'replies')->latest()->limit(3)->get());

// good: ids, re-hydrated; or a slim array
$ids = Cache::remember('forum:threads:latest:ids', 3600, fn () => Thread::query()->latest()->limit(3)->pluck('id')->all());
$threads = Thread::with('author')->findMany($ids);

Cache::remember("user:{$id}:card:v1", 600, fn () => User::findOrFail($id)->only(['id', 'name', 'avatar_url']));
```
New skeletons set `cache.serializable_classes => false`: cached objects come back as `__PHP_Incomplete_Class`. Cache scalars/arrays/ids; if objects must be cached, list them in `serializable_classes`.

## Atomic operations

```php
// once-per-window guard: add() is atomic and returns false if the key exists
if (! Cache::add('alerts:slow-query', true, now()->addMinutes(5))) {
    return;
}

// counter
Cache::add($key, 0, now()->addHours(4));
$n = Cache::increment($key);   // returns the new value
```
Never `has()` then `put()`, or `get() + 1` then `put()`; both race.

## Locks

```php
$lock = Cache::lock('reports:rebuild', 600);

if (! $lock->get()) {
    return;   // another run in progress
}

try {
    $this->rebuild();
} finally {
    $lock->release();
}

Cache::lock('sitemap:build', 120)->get(fn () => Sitemap::build());          // skip if busy; releases in finally
Cache::lock('sitemap:build', 120)->block(5, fn () => Sitemap::build());     // wait up to 5 s, else LockTimeoutException
```
- **Always give a lock seconds.** On Redis, `lock($name)` with 0 is `SETNX` with no expiry; a crash holds it forever. Release in `finally`, or pass a closure to `get()`/`block()`.
- Single-flight cold rebuild: re-check inside the lock so waiters reuse the first result, and catch `LockTimeoutException`:
```php
return Cache::get('catalog:tree:v1') ?? Cache::lock('catalog:tree:v1:rebuild', 30)
    ->block(10, fn () => Cache::remember('catalog:tree:v1', 3600, fn () => Category::buildTree()));
```
- Single instance: `Cache::withoutOverlapping('sitemap:build', fn () => ..., lockFor: 120, waitFor: 5)`. Bounded parallelism: `Cache::funnel('imports:supplier-api')->limit(3)->releaseAfter(60)->block(10)->then(fn () => ..., fn () => ...)`. Both need a lock-capable store (`funnel()` throws `BadMethodCallException` otherwise). Don't hand-roll either with counters.
- Lock taken in a request, released by a job: pass `$lock->owner()` to the job, then `Cache::restoreLock($name, $this->owner)->release()`. Not `forceRelease()`, which ignores ownership.
- Locks, rate limiters and tags need a shared central store (redis, memcached). `file` and `array` are per server, so locks don't coordinate across hosts. Route concerns with `Cache::store('redis')` or `'limiter' => 'redis'` in `config/cache.php`; the `failover` driver adds availability.

## Tags

`Cache::tags()` works only on redis, memcached and array. `file`, `database` (the skeleton default), `dynamodb` and `storage` throw `BadMethodCallException`. Read with the same ordered tag list you wrote with. If the store could change, use versioned keys and `forget()`, or guard with `Cache::supportsTags()`.

## Memoization

- Same key read many times in one request or job (settings, flags in a loop) → `Cache::memo()->get('fx:EUR')`. First read hits the store, the rest come from memory; writes through `memo()` forget the memoized value. It is scoped, so it resets between requests and queue jobs.
- A pure computation per object or call site, no store → `once(fn () => $this->loadPermissions())` (per instance inside an object). It is keyed by call site and captured variables and flushed only in tests: outside an object scope in a long-running worker it lives for the whole process. Don't use the cache with a 1 s TTL as a per-call memo.

## Flaky upstreams

```php
try {
    $rates = Cache::remember('fx:rates:v1', 300, fn () => Http::timeout(3)
        ->connectTimeout(2)
        ->retry(3, 100, fn (Throwable $e) => $e instanceof ConnectionException)
        ->get($url)
        ->throw()
        ->json());
    Cache::forever('fx:rates:v1:last-good', $rates);
} catch (ConnectionException|RequestException $e) {
    report($e);
    $rates = Cache::get('fx:rates:v1:last-good', []);
}
```
- The HTTP client doesn't throw on 4xx/5xx without `->throw()`, so without it an error body gets cached as data. Default timeout is 30 s; set `timeout`/`connectTimeout` on request-path calls.
- `retry()` throws `RequestException` after the last failed attempt; with `throw: false` it returns the last response but a `ConnectionException` still throws. `remember()` caches nothing when the closure throws.

## Rate limiting

```php
// AppServiceProvider::boot()
RateLimiter::for('api', fn (Request $request) =>
    Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

RateLimiter::for('uploads', fn (Request $request) => [
    Limit::perMinute(10)->by('minute:'.$request->user()->id),   // prefix: same by() would share a counter
    Limit::perDay(1000)->by('day:'.$request->user()->id),
]);

RateLimiter::for('resource-not-found', fn (Request $request) => Limit::perMinute(10)
    ->by($request->user()?->id ?: $request->ip())
    ->after(fn (Response $response) => $response->status() === 404));   // count only 404s

Route::middleware('throttle:api')->group(...);   // not throttle:60,1
```
In code (sending messages, partner API): `RateLimiter::attempt($key, $max, fn () => ..., $decaySeconds)`, or compare the value returned by `RateLimiter::increment($key)` rather than separate `tooManyAttempts()` + `hit()` (check-then-act race). `availableIn($key)` for the retry-after message, `clear($key)` on reset.

## Deploy

`env()` only in `config/*.php`; after `config:cache` (run by `php artisan optimize`) `.env` isn't loaded and `env()` elsewhere returns null. Deploy scripts run `php artisan optimize`; reset with `optimize:clear`.

## Testing

There is no `Cache::fake()`. `phpunit.xml` sets `CACHE_STORE=array`: test against it, assert on `Cache::get/has`, and cross TTLs with `travel(6)->minutes()` / `freezeTime()`.
```php
it('caches the total for five minutes', function () {
    Article::factory()->count(2)->create();
    expect(app(Stats::class)->total())->toBe(2);

    Article::factory()->create();
    expect(app(Stats::class)->total())->toBe(2);

    travel(6)->minutes();
    expect(app(Stats::class)->total())->toBe(3);
});
```
Use `Cache::spy()` + `shouldHaveReceived`, or `Cache::expects()`, only to assert an interaction happened; `Cache::memo()` returns a spied repository when Cache is a spy. Mocking `remember` tests the mock, not the key/TTL logic.

Evidence: `Illuminate/Cache/{Repository,CacheManager,MemoizedStore,Lock,RedisLock,HasCacheLock,RateLimiter}.php`, `Cache/RateLimiting/Limit.php`, `Support/{Once,Onceable}.php`; `laravel/docs/cache.md`, `rate-limiting.md`, `routing.md`, `http-client.md`, `deployment.md`; laravel/laravel `config/cache.php`, `phpunit.xml`; laravel.io `app/Filament/Widgets/`, `AppServiceProvider.php`; spatie/laravel-permission `PermissionRegistrar.php`.
