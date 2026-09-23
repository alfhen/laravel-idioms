# Modern Laravel (11-13)

Check `laravel/framework` in `composer.lock` before using anything here. Items marked **13** are Laravel 13 (PHP ^8.3, PHPUnit ^12, Pest ^4). Apps upgraded from 10 or earlier may keep the old structure; follow what exists rather than half-migrating.

## Skeleton: what exists and what doesn't

The 11+ `app/` ships only `Http/Controllers/Controller.php` (empty abstract class), `Models/User.php` and `Providers/AppServiceProvider.php`.

| Don't create | Use instead |
|---|---|
| `app/Http/Kernel.php` | `->withMiddleware()` in `bootstrap/app.php` |
| `app/Exceptions/Handler.php` | `->withExceptions()` |
| `app/Console/Kernel.php` | `Schedule::` in `routes/console.php`; commands auto-discovered from `app/Console/Commands` |
| `RouteServiceProvider` | `->withRouting(web:, api:, commands:, health:, apiPrefix:)` |
| `AuthServiceProvider` / `$policies` | policy auto-discovery, `#[UsePolicy]`, `Gate::policy()` in `AppServiceProvider` |
| `EventServiceProvider` / `$listen` | listener discovery from `app/Listeners` |
| providers in `config/app.php` | `bootstrap/providers.php` |
| `routes/api.php` by hand | `php artisan install:api` (installs Sanctum, wires `api:`) |
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
        $middleware->trustProxies(at: '*');
        $middleware->preventRequestForgery(except: ['stripe/*']);   // 13
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (InvalidOrderException $e, Request $request) =>
            response()->view('errors.invalid-order', status: 500));
        $exceptions->dontReport(MissedFlightException::class);
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());
    })
    ->create();
```

In 13, CSRF middleware is `PreventRequestForgery`; `VerifyCsrfToken` / `ValidateCsrfToken` are deprecated aliases. Tests: `$this->withoutMiddleware([PreventRequestForgery::class])`.

## Scheduling

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command(SendEmailsCommand::class, ['Taylor', '--force'])->daily();
Schedule::job(new Heartbeat)->everyFiveMinutes();
```

## AppServiceProvider::boot() is the home for

```php
Model::shouldBeStrict(! $this->app->isProduction());
Password::defaults(fn () => Password::min(8)->uncompromised());
RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
Gate::policy(DatabaseNotification::class, NotificationPolicy::class);   // only non-conventional names
Queue::route(ProcessPodcast::class, connection: 'redis', queue: 'podcasts');   // 13
```

## Attribute-first configuration (13)

| Old | 13 |
|---|---|
| `protected $fillable/$hidden/$appends` | `#[Fillable([...])]`, `#[Hidden([...])]`, `#[Appends([...])]` |
| `$table`, `$primaryKey`, `$keyType`, `$incrementing`, `$timestamps` | `#[Table('x', key:, keyType:, incrementing:, timestamps:)]` |
| `scopeActive($q)` | `#[Scope] protected function active(Builder $q): void` |
| `User::observe(...)` in provider | `#[ObservedBy([...])]` |
| `addGlobalScope` in `booted()` | `#[ScopedBy([...])]` |
| `newFactory()` override | `#[UseFactory(...)]` |
| `$this->middleware()` in constructor | `#[Middleware]` / `HasMiddleware` |
| `$this->authorize()` at top of action | `#[Authorize('update', 'post')]` |
| `public $tries/$timeout/$backoff` | `#[Tries]`, `#[Timeout]`, `#[Backoff]`, `#[Queue]`, `#[Connection]`, `#[UniqueFor]` |
| `protected $signature/$description` | `#[Signature('mail:send {user}')]`, `#[Description('...')]` |
| FormRequest `$stopOnFirstFailure`, `$errorBag`, `$redirectRoute` | `#[StopOnFirstFailure]`, `#[ErrorBag]`, `#[RedirectToRoute]`, `#[FailOnUnknownFields]` |
| `$this->app->singleton(I::class, C::class)` | `#[Bind(C::class)] #[Singleton] interface I {}` |
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

Evidence: `laravel/laravel/bootstrap/app.php`, `bootstrap/providers.php`, `app/Models/User.php`; `Illuminate/Foundation/Configuration/{ApplicationBuilder,Middleware,Exceptions}.php`; `laravel/docs/upgrade.md`, `releases.md`, `structure.md`.
