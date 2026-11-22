# Modern Laravel (11-13)

Check `laravel/framework` in `composer.lock` before using anything here. Items marked **13** are Laravel 13 (PHP ^8.3, PHPUnit ^12.5); **13.N+** marks an API added during 13.x. Laravel 13 on PHP 8.3 means Pest 4; Pest 5 needs PHP 8.4 and PHPUnit 13 (`pest.md`). Apps upgraded from 10 or earlier may keep the old structure; follow what exists rather than half-migrating.

## Patch floor and support

- Run `composer audit --locked` and report every `laravel/*` advisory with its fixed version. It is the source of truth; the numbers below are a dated example.
- As of 2026-11-18 the floor is **13.43.0 / 12.73.0** (released 2026-11-17). The advisory wave before it hit idioms this skill teaches: `exists`/`unique` rules, `decimal`, `in`, file validation, signed URL expiry, nested `sometimes`, trusted proxies, mass assignment and Blade component attributes. Below the floor, apply the workarounds in `security.md` and say so.
- Laravel 11 has had no security fixes since 2026-03-12; 12 gets them until 2027-02-24, 13 until 2028-03-17. On 11 or older, recommend the upgrade instead of patching around it.

## Skeleton: what exists and what doesn't

The 11+ `app/` ships only `Http/Controllers/Controller.php` (empty abstract class), `Models/User.php` and `Providers/AppServiceProvider.php`.

| Don't create | Use instead |
|---|---|
| `app/Http/Kernel.php` | `->withMiddleware()` in `bootstrap/app.php` |
| `app/Exceptions/Handler.php` | `->withExceptions()` |
| `app/Console/Kernel.php` | `Schedule::` in `routes/console.php` (or `->withSchedule()`); commands auto-discovered from `app/Console/Commands`. A leftover `Kernel::schedule()` is dead only once `bootstrap/app.php` uses `Application::configure()`; an upgraded app that still binds `App\Console\Kernel` keeps running it |
| `RouteServiceProvider` | `->withRouting(web:, api:, commands:, health:, apiPrefix:)` |
| `AuthServiceProvider` / `$policies` | policy auto-discovery, `#[UsePolicy]`, `Gate::policy()` in `AppServiceProvider` |
| `EventServiceProvider` / `$listen` | listener discovery from `app/Listeners` (on by default under `Application::configure()`, which registers the framework's discovering provider even when the app also has its own `EventServiceProvider` subclass, so listing a discovered listener in `$listen` runs it twice; `withEvents(discover: false)` turns discovery off; an old bootstrap without `Application::configure()` discovers nothing and needs `$listen` or `Event::listen()`) |
| providers in `config/app.php` | `bootstrap/providers.php` |
| `routes/api.php` by hand | `php artisan install:api` (installs Sanctum, or Passport with `--passport`, and wires `api:`; it does not enable `statefulApi()` or throttling, define an `api` limiter or guard, or add `HasApiTokens` to `User`) |
| `routes/channels.php` by hand | `php artisan install:broadcasting` |
| missing config file by hand | `php artisan config:publish <name>` |
| middleware stubs (`TrustProxies`, `EncryptCookies`, `TrimStrings`, `RedirectIfAuthenticated`, `VerifyCsrfToken`) | `$middleware->trustProxies()`, `encryptCookies(except:)`, `redirectGuestsTo()`, `redirectUsersTo()`, `preventRequestForgery(except:)` |

## bootstrap/app.php

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [AddContext::class]);
        $middleware->alias(['subscribed' => EnsureUserIsSubscribed::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->trustHosts();   // APP_URL's host and its subdomains
        // Only behind a load balancer: its address range. Leave the line out when nothing proxies the app.
        // '*' only when every request reaches the app through a proxy that overwrites X-Forwarded-*.
        $middleware->trustProxies(at: ['10.0.0.0/8']);
        $middleware->preventRequestForgery(except: ['webhooks/*']);   // 13; validateCsrfTokens(except:) on 11-12
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (InvalidOrderException $e, Request $request) =>
            response()->view('errors.invalid-order', status: 500));
        $exceptions->dontReport(VendorNotFound::class);   // your own exceptions: implements ShouldntReport
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());
    })
    ->create();
```

Proxies and hosts:
- Trusted proxies decide `$request->ip()`, the scheme and the host. Trusting `*` on an app that clients can also reach directly lets them set `X-Forwarded-For`, which defeats per-IP rate limits and allow-lists.
- Absolute URLs (password reset, verification, invitations, signed URLs) are built from the request's `Host`. Bare `trustHosts()` trusts the `APP_URL` host and its subdomains, anchored. Extra hosts are anchored regexes (`'^admin\.example\.com$'`), never a bare hostname: it is matched unanchored and its dots are wildcards.
- `trustHosts()` does nothing in `local` or under tests, so an app that mails links also pins the root in `AppServiceProvider::boot()` (below). Host-header tests: `security.md`.

API-only app on **13.6+**: `->prefersJsonResponses()` on `Application::configure()` rewrites a missing, empty, `*/*` or `application/*` `Accept` header to JSON, so curl and SDK clients get a JSON 401, 422 or error instead of a redirect. A browser's `text/html` still wins. Without it, keep `redirectGuestsTo()` returning `null` for `api/*` (`http-layer.md`).

`health: '/up'` answers 200 once the app has booted. It checks the database or cache only through a `DiagnosingHealth` listener, and a failing listener makes it answer 500 (see `observability.md`).

In 13, CSRF middleware is `PreventRequestForgery`; `VerifyCsrfToken` / `ValidateCsrfToken` are deprecated aliases. Tests: `$this->withoutMiddleware([PreventRequestForgery::class])`.

## Scheduling

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command(SendEmailsCommand::class, ['Taylor', '--force'])->daily();
Schedule::job(new Heartbeat)->everyFiveMinutes();
```
Overlap and one-server locks, timezones, monitoring and schedule tests: `scheduling.md`. Commands: `console-commands.md`.

## AppServiceProvider::boot() is the home for

```php
Model::shouldBeStrict(! $this->app->isProduction());
Password::defaults(fn () => Password::min(8)->uncompromised());
RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
Gate::policy(DatabaseNotification::class, NotificationPolicy::class);   // only non-conventional names
Queue::route(ProcessPodcast::class, connection: 'redis', queue: 'podcasts');   // 13
DB::prohibitDestructiveCommands($this->app->isProduction());   // migrate:fresh/refresh/reset/rollback, db:wipe

if (! $this->app->isLocal()) {
    URL::forceRootUrl(config('app.url'));   // links in mail never take the request's Host
    URL::forceHttps(str_starts_with(config('app.url'), 'https://'));   // 11.31+; forceRootUrl() alone keeps the request's scheme
}
```

## Attribute-first configuration (13)

| Old | 13 |
|---|---|
| `protected $fillable/$hidden/$appends` | `#[Fillable([...])]`, `#[Hidden([...])]`, `#[Appends([...])]` |
| `$table`, `$primaryKey`, `$keyType`, `$incrementing`, `$timestamps` | `#[Table('x', key:, keyType:, incrementing:, timestamps:)]` |
| `public $timestamps = false`, `public $incrementing = false`, `$dateFormat`, `$connection` | `#[WithoutTimestamps]`, `#[WithoutIncrementing]`, `#[DateFormat('U')]`, `#[Connection('mysql')]` |
| `$visible`, `$touches`, `getRouteKeyName()` | `#[Visible([...])]`, `#[Touches(['post'])]`, `#[RouteKey('slug')]` |
| `newCollection()`, `newEloquentBuilder()` overrides | `#[CollectedBy(...)]` (11.28+), `#[UseEloquentBuilder(...)]` |
| resource class guessed from the model name | `#[UseResource(...)]`, `#[UseResourceCollection(...)]` (12.29+) |
| JsonResource `public $collects`, `public $preserveKeys = true` | `#[Collects(...)]`, `#[PreserveKeys]` |
| `$article->refresh()` after `create()` to read a database default or generated column | `#[Refreshes(['status', 'slug'])]` (**13.33+**): re-selects those columns after every insert and update, one extra query per save |
| `scopeActive($q)` | `#[Scope] protected function active(Builder $q): void`, unless `active` is also read as an attribute (`eloquent.md`, Scopes) |
| `User::observe(...)` in provider | `#[ObservedBy([...])]` |
| `addGlobalScope` in `booted()` | `#[ScopedBy([...])]` |
| `newFactory()` override | `#[UseFactory(...)]` |
| `$this->middleware()` in constructor | `#[Middleware]` / `HasMiddleware` |
| `$this->authorize()` at top of action | `#[Authorize('update', 'post')]` |
| `public $tries/$timeout/$backoff` | `#[Tries]`, `#[Timeout]`, `#[Backoff]`, `#[Queue]`, `#[Connection]`, `#[UniqueFor]` |
| `retryUntil()` returning a fixed window | `#[RetryFor(hours: 1)]` (**13.43+**; a `retryUntil()` method or property still wins) |
| `protected $signature/$description` | `#[Signature('mail:send {user}')]`, `#[Description('...')]` |
| FormRequest `$stopOnFirstFailure`, `$errorBag`, `$redirectRoute` | `#[StopOnFirstFailure]`, `#[ErrorBag]`, `#[RedirectToRoute]`, `#[FailOnUnknownFields]` |
| `$this->app->singleton(I::class, C::class)` | `#[Bind(C::class)] #[Singleton] interface I {}` (12.22+), only for stateless services (`architecture-and-testing.md`, Container) |
| `$this->app->scoped(I::class, C::class)` | `#[Scoped]` on the class or bound interface (12.21+): one instance per request or job |
| `protected $seed = true` in TestCase | `#[Seed]` |

Properties still work. Use attributes in new code on 13 when the project hasn't settled on properties.

## Patterns that changed (all 11+ unless noted)

- `protected function casts(): array` over `protected $casts`; `$dates` is gone.
- Jobs: single `Illuminate\Foundation\Queue\Queueable` trait.
- Custom rules: `ValidationRule::validate(..., Closure $fail)`, not `Rule::passes()/message()`.
- FormRequest `after(): array` over `withValidator($v) { $v->after(...) }`.
- Mailables: `envelope()` / `content()` over `build()`.
- `Arr::first()` / `Arr::last()` over global `array_first()` (13 pulls in `symfony/polyfill-php85`, whose `array_first()` takes no callback).
- `HasUuids` generates ordered UUIDv7 (`Str::uuid7()`).
- **13**: cache `serializable_classes => false` and session `serialization => 'json'` in the skeleton — arbitrary objects in cache/session won't unserialize unless allow-listed.
- **13**: `JobAttempted::$exceptionOccurred` → `$exception`; `QueueBusy::$connection` → `$connectionName`.

## Newer helpers worth using

```php
use function Illuminate\Support\defer;

defer(fn () => Metrics::reportOrder($order));                      // after the response
[$users, $orders] = Concurrency::run([fn () => User::count(), fn () => Order::count()]);
$plans = once(fn () => Plan::all());                               // memoize per instance/request
Context::add('trace_id', Str::uuid()->toString());                 // on every log line and job
Cache::flexible('users', [5, 10], fn () => User::all());           // stale-while-revalidate
Cache::touch('key', 3600);
$status = $request->enum('status', ServerStatus::class);
'start' => ['required', Rule::date()->afterToday()],
'email' => ['required', Rule::email()->rfcCompliant(strict: false)->preventSpoofing()],
Post::whereRelation('comments', 'is_approved', true)->get();
```

Typed input read outside a FormRequest:
```php
// bad: ?per_page=abc gives 0, not 15 (the default is used only when the key is missing)
$perPage = $request->integer('per_page', 15);
// good, 13.40+: anything that isn't a valid integer gives the default
$perPage = $request->integer('per_page', 15, strict: true);
```
- `strict: true` (**13.40+**) also exists on `float()`, `boolean()` (only `1/0`, `true/false`, `on/off`, `yes/no` count) and `string()` (an array gives the default instead of a 500). Below 13.40, validate the value first.
- `enum()` has no strict flag: an array value throws a `TypeError`. Validate with `Rule::enum()` when the input can be an array.

Evidence: `laravel/laravel/bootstrap/app.php`, `bootstrap/providers.php`, `app/Models/User.php`, `composer.json` (13.x: php ^8.3, phpunit ^12.5.12); `Illuminate/Foundation/Configuration/{ApplicationBuilder,Middleware,Exceptions}.php` (`prefersJsonResponses`, `trustHosts`, `trustProxies`), `Illuminate/Http/Middleware/{TrustHosts,PrefersJsonResponses}.php`, `Illuminate/Routing/UrlGenerator.php` (`forceRootUrl`, `forceHttps`), `Illuminate/Support/Traits/InteractsWithData.php` (`strict`), `Illuminate/Database/Eloquent/Attributes/*`, `Illuminate/Http/Resources/Attributes/*`, `Illuminate/Container/Attributes/{Singleton,Scoped,Bind}.php`, `Illuminate/Queue/Attributes/RetryFor.php`, `Illuminate/Queue/Queue.php` (`getJobExpiration`), `Illuminate/Foundation/Console/ApiInstallCommand.php`, `Illuminate/Foundation/Support/Providers/EventServiceProvider.php`; laravel/framework release notes v13.6.0 (#59753), v13.33.0 (#61523), v13.40.0 (#62254), v13.43.0 (#62434) and v12.73.0; laravel/framework security advisories (GHSA-ch34-gjmh-c4hp, GHSA-wwq3-m3j5-wv66, GHSA-h84h-g58v-xg2h, GHSA-h2hg-mfj4-rg7q, GHSA-hxc4-wvjf-r59p, GHSA-v5ch-g375-82r6, GHSA-6rv8-qj5v-p8ch, GHSA-f3ph-5p6j-34xr, GHSA-559m-v249-7qw2, GHSA-6pxv-c29g-cc5h, GHSA-hv67-wfqj-w9xv, GHSA-9966-43cq-4mpr); `laravel/docs/releases.md` (support table), `requests.md` (trusted proxies and hosts, strict input), `eloquent.md`, `upgrade.md`, `structure.md`.
