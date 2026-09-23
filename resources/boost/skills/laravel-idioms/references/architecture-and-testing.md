# Architecture and testing

## Where logic goes

| Situation | Put it in |
|---|---|
| Plain CRUD | controller + FormRequest + Eloquent |
| Several queries, aggregation or a computed response (stats, totals, exports) | one invokable class named for its output, reusing model scopes; returns an array or `JsonResource` |
| Rule that belongs to one model (derive a field, sync a relation) | model method, scope, or observer |
| Logic shared by 2+ callers (controller, command, job) | small invokable action class |
| Slow or external work (APIs, images, fan-out mail) | queued job |
| Several side effects of one thing happening | event + one listener per effect |
| User-facing message on any channel | Notification |
| Tiny post-response side effect | `defer(fn () => ...)` |

The opposite failure is just as common: a controller action holding several queries, row mapping and formatting in private helpers. Neither reviewers nor other callers can reuse those. Move it out once an action exceeds about 10 lines or one query.

Don't wrap `Model::create($request->validated())` in a repository, service, action and DTO. `Actions/`, `Services/`, `Repositories/` are not framework directories; add them when the code earns them.

```php
final class ConnectGitHubAccount
{
    public function __invoke(User $user, SocialiteUser $gh): void
    {
        $user->update(['github_id' => $gh->getId()]);

        UpdateUserIdenticonStatus::dispatch($user);   // slow, external: queued
    }
}
```

A DTO (spatie/laravel-data `Data` class or a `fromRequest()` named constructor) is worth it when the same typed shape is built from several sources or crosses a queue boundary. Otherwise pass `$request->validated()`. A readonly class with a hand-written `toArray()` that exists only to be JSON-encoded is ceremony. Return the array, or use a `JsonResource`.

## Jobs, events, notifications

Job shape, retries, timeouts, middleware, uniqueness, chains/batches, events, notifications, workers and queue tests live in `queues-and-workers.md`.

## Enums

```php
enum NotificationType: string
{
    case Mention = 'mention';
    case Reply = 'reply';

    public function label(): string
    {
        return match ($this) {
            self::Mention => 'Mentions',
            self::Reply => 'Replies',
        };
    }
}
```
Cast on the model, validate with `Rule::enum(NotificationType::class)`, replace class constants and string comparisons. In a Filament app, implement `HasLabel`/`HasColor`/`HasIcon` instead of a custom `label()` (`filament.md`).

## Container

```php
public function __construct(
    protected AppleMusic $apple,
    #[Config('app.timezone')] protected string $timezone,
    #[Storage('s3')] protected Filesystem $disk,
) {}

Route::get('/me', fn (#[CurrentUser] User $user) => $user)->middleware('auth');

#[Bind(RedisEventPusher::class)]
#[Bind(FakeEventPusher::class, environments: ['local', 'testing'])]
#[Singleton]
interface EventPusher {}
```
- Constructor injection over `app()` / `resolve()` / `new` inside methods.
- Contextual attributes (`Config, Storage, Cache, DB, Log, CurrentUser, RouteParameter, Context, Give, Tag`) over facade lookups in constructors.
- `register()` for bindings only; everything else in `boot()`.

## Config and context

- `env()` only in `config/*.php`; read with `config('services.stripe.secret')`.
- Request metadata: `Context::add('trace_id', Str::uuid()->toString())` in middleware; it lands on every log line and propagates into queued jobs.
- `Concurrency::run([fn () => ..., fn () => ...])` for independent slow reads.

## Testing

Match the suite's framework: Pest closures if `tests/Pest.php` or `pestphp/pest` exists, otherwise PHPUnit classes in the existing style. Pest idioms (`Pest.php` bindings, `expect()`, datasets, arch tests) are in `pest.md`. The examples below are Pest; in a PHPUnit suite the same Laravel assertions go in `test_*` methods, and datasets become `#[DataProvider]` methods.

```php
it('stores an article', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.articles.store'), ['title' => 'Hi', 'body' => '# x'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Hi');

    $this->assertDatabaseHas('articles', ['title' => 'Hi', 'author_id' => $user->id]);
});
```

### Coverage expected for a feature

| Area | Tests |
|---|---|
| Happy path | one per requirement in the brief, asserting the exact response shape (`assertJsonPath`, `assertExactJson`) and the DB state |
| Auth | guest → `assertUnauthorized()`, other user → `assertForbidden()` |
| Validation | one per rule (or a dataset), asserting the error key **and** message, including query-string filters |
| Limits | caps (top N, `per_page`), ordering ties, empty result, zero/rounding edges |
| Side effects | `Queue::fake()` → `assertPushed`; `Mail::fake()` → `assertSent`/`assertQueued`; `Storage::fake()` → `assertExists`; unchanged state after a failure |
| Robustness | malformed input (blank/ragged rows, wrong types), and the job `failed()` path |

```php
it('rejects invalid input', function (array $override, string $field) {
    $this->actingAs(User::factory()->create())
        ->postJson(route('posts.store'), [...validPost(), ...$override])
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor($field);
})->with([
    'missing title' => [['title' => ''], 'title'],
    'bad status'    => [['status' => 'nope'], 'status'],
]);
```
Build related records with `->for($parent)`, `->has(Child::factory()->count(3))`, `->recycle($user)` and named states (`->published()`), never `['parent_id' => $parent->id]` arrays or `Model::create`.

Fakes:
```php
$user = User::factory()->create();
Event::fake();   // after factories, or model events (UUIDs, slugs) are suppressed

Event::assertDispatched(EmailAddressWasChanged::class, fn ($e) => $e->user->is($user));
Notification::assertSentTo([$userOne, $userTwo], NewReplyNotification::class);
Queue::assertPushed(ShipOrder::class, fn ($job) => $job->order->is($order));   // more in queues-and-workers.md

Http::preventStrayRequests();
Http::fake(['api.github.com/*' => Http::response(['avatar' => '...'])]);
$this->mock(GithubUserApi::class, fn (MockInterface $m) => $m->expects('hasIdenticon')->andReturnTrue());
```
- Assert with `assertJson(fn (AssertableJson $json) => ...)`, `assertJsonPath`, `assertDatabaseHas`, not `DB::table()->count()`.
- `Sanctum::actingAs($user)` for API tests.
- `#[Seed]` on the TestCase (13) instead of `protected $seed = true`.
- Cache behaviour: test against the array store with `travel()`, not a mocked `Cache::remember` (`caching.md`).
- Pest arch tests (`arch()->preset()->laravel()`, custom rules): `pest.md`.

Evidence: `laravel/docs/container.md`, `http-tests.md`, `Illuminate/Container/Attributes/`, laravel.io `tests/Integration/`.
