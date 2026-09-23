# Pest

## Which framework

Check before writing a test:
- `tests/Pest.php` exists or `composer.json` has `pestphp/pest` → write Pest closures. Never add a `class FooTest extends TestCase` to a Pest suite.
- Only `phpunit/phpunit` and existing tests are classes (the Livewire starter kit ships this way) → write PHPUnit classes in the same style. Don't install Pest or convert files unless asked.
- Asked to migrate → `composer require pestphp/pest pestphp/pest-plugin-drift --dev`, then `./vendor/bin/pest --drift tests/Feature` (one folder at a time is fine). Don't hand-rewrite files.

Pest 5 needs PHP 8.4 and PHPUnit 13; bump every `pestphp/*` plugin to `^5.0` together with `pestphp/pest`.

## tests/Pest.php

Bind the TestCase, traits and folder-wide hooks once, per folder:
```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->actingAs(User::factory()->create()))
    ->in('Feature');
```
- The stock Laravel stub ships with `->use(RefreshDatabase::class)` commented out. Turn it on deliberately; don't assume it is active.
- Don't repeat `uses(RefreshDatabase::class)` or `pest()->use()` in a file already covered here (PHPStan reports `pest.config.redundantLocalUse`).
- Only folders passed to `->in()` get the Laravel TestCase. `tests/Unit` runs on bare `PHPUnit\Framework\TestCase`: no container, factories, facades or `$this->get()`. Keep Unit for pure PHP; put anything that touches Laravel in Feature, or extend Unit in Pest.php on purpose.

## Structure

```php
it('can create a record', function () {
    // ...
});

describe('bulk actions', function () {
    beforeEach(fn () => $this->records = Product::factory()->count(3)->create());

    it('can bulk delete products', function () { /* ... */ });
});
```
- `it()` when the description reads as a sentence; `describe()` to group tests sharing a subject or a `beforeEach`.
- **Closures are never `static`.** Pest binds them to the TestCase so `$this` works; `static function`/`static fn` gives "Using $this when not in object context" (PHPStan: `pest.test.staticClosure`). Plain `fn () =>` is fine. Don't let a linter or Rector rule add `static`.
- Shared per-test setup goes in `beforeEach`, assigning `$this->prop`. `beforeAll`/`afterAll` run statically: no `$this`, no database, and not allowed inside `describe()`.
- Hook bodies that are a pure `$this` chain may be higher-order: `beforeEach()->withoutMiddleware()`.

## Expectations

Use `expect()` for values; keep Laravel's own assertions (`assertDatabaseHas`, response and Livewire assertions, facade fakes), which have no expectation equivalent. Mixing is normal.
```php
// bad
$this->assertEquals('Test User', $user->name);
$this->assertNull($user->email_verified_at);

// good: one subject, chained, after refresh()
expect($user->refresh())
    ->name->toBe('Test User')
    ->email_verified_at->toBeNull()
    ->and($user->notifications)->toHaveCount(1);
```
- `toBe()` is `assertSame` (strict type and identity); `toEqual()` is loose. Decimal casts come back as strings: `expect((float) $order->total)->toBe(100.00)`. Enum casts: `toBe(OrderStatus::Paid)`. Two instances of the same row are never `toBe()`; compare ids.
- `->refresh()` the model after the action, before asserting.
- Partial checks: `toMatchArray([...])` (calls `toArray()` on models). Counts: `toHaveCount`. Types: `toBeInstanceOf`, `toContainOnlyInstancesOf`, `toBeCollection()` (Laravel plugin).
- Over collections: `->each->is_visible->toBeFalse()` and ordered `->sequence(A, B)`, not a `foreach` of expects. `Factory::sequence()` is an unrelated API (varies attributes while creating).
- Pest 5 matchers over regexes: `toBeEmail`, `toBeUlid`, `toBeUuid`, `toBeUrl`, `toBeIpAddress`.
- Repeated multi-step assertion → `expect()->extend('toBePublished', fn () => $this->published_at->not->toBeNull()->is_visible->toBeTrue())` in `tests/Pest.php`, returning the expectation. To change `toBe()` for models use `expect()->pipe()` / `intercept()`. No standalone `assertX()` helper functions.

## Exceptions

```php
it('rejects negative qty', function () {
    $cart->add($product, -1);
})->throws(InvalidArgumentException::class, 'Quantity must be positive.');

expect(fn () => $cart->add($product, -1))
    ->toThrow(InvalidArgumentException::class, 'Quantity must be positive.');
```
`->throws()` when the whole test throws; `expect(fn () => ...)->toThrow()` when the throw is one step. Never `try/catch` + `$this->fail()` or `expectException()`.

## Datasets

```php
it('validates the form data', function (array $data, array $errors) {
    $this->actingAs(User::factory()->create())
        ->postJson(route('posts.store'), [...validPost(), ...$data])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    '`title` is required' => [['title' => null], ['title' => 'required']],
    '`status` must be valid' => [['status' => 'nope'], ['status']],
]);
```
- Key each case with a description; spread its overrides into an otherwise valid payload. The run is named after the key.
- **Anything that needs the app (models, factories, config) must be a closure**, and the receiving parameter must be type-hinted. A plain value runs at file load, before the app boots or the DB migrates:
```php
})->with([
    'verified' => fn () => User::factory()->create(),
    'unverified' => fn () => User::factory()->unverified()->create(),
]);   // function (User $user)
```
- Reused across files → `dataset('roles', [...])` in `tests/Datasets/*.php` (global) or a folder-local `Datasets.php`; use `->with('roles')`. Chained `->with()` calls give the cartesian product. Small scalar sets (`->with([true, false])`) stay inline.

## HTTP, fakes, mocks

- HTTP tests chain `TestResponse` assertions off `$this->get()/postJson()/actingAs()`. Alternative: `use function Pest\Laravel\{actingAs, get, postJson};` and drop `$this`. They are namespaced, not global, so calling `get()` without the import fails. Pick one style per file.
- Call `Event/Notification/Mail/Queue/Bus/Storage::fake()` **before** the action, then assert with the facade's `assertX`. Fakes can't be expressed with `expect()`. If a fake returns an undo callable (Filament `Repeater::fake()`), call it at the end.
- Replace container-resolved dependencies with `$this->mock(Class::class, fn ($m) => ...)` or `Pest\Laravel\mock/partialMock/spy/swap`, which bind the double. A bare `Mockery::mock()` is only right when you pass it to a constructor yourself.
```php
use function Pest\Laravel\mock;

mock(PaymentClient::class)->shouldReceive('charge')->once()->andReturn(new Receipt('ok'));
$this->post('/checkout')->assertRedirect(route('orders.index'));
```
- Livewire and Filament pages: `Livewire::test(Page::class)` or `livewire()`; see `filament.md`.

## Arch tests

```php
// tests/ArchTest.php
arch()->preset()->php();
arch()->preset()->security()->ignoring('md5');
arch()->preset()->laravel()->ignoring('App\Http\Controllers\Webhooks');

arch('controllers stay thin')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller')
    ->not->toUse('Illuminate\Support\Facades\DB');
```
- The `laravel` preset is opinionated: it bans `env()` outside config, `dd/dump/ray`, non-resource public controller methods, and requires `App\Mail` and `App\Jobs` classes to implement `ShouldQueue`. On an existing app, run it and `->ignoring()` what the codebase deliberately does differently; don't delete the preset.
- Encode conventions: `toOnlyBeUsedIn`, `toExtend`, `extending(Model::class)->toUseTrait(HasFactory::class)`, `toHaveSuffix`, `not->toUse`/`not->toBeUsed`. Namespaces are strings; wildcards (`'App\*\Traits'`) work.

## Higher-order tests, skipping

```php
it('renders the login screen')->get('/login')->assertOk();

it('has a name')
    ->expect(fn () => User::factory()->create(['name' => 'Nuno'])->name)   // lazy closure
    ->toBe('Nuno');
```
- Higher-order only when the body is a single `$this` chain or expectation; the `expect()` value must be a closure. Side effects: `->defer(fn () => ...)` (`tap()` was removed). Any arrange step → a normal closure.
- Never comment tests out: `->skip('reason')`, `->skip(fn () => ! config('services.mailgun.secret'), 'mailgun not configured')` (evaluated after `beforeEach`), `skipOnCi()`/`skipLocally()`, `->todo()`.
- Never commit `->only()`. CI with `--ci` ignores it; local runs silently drop the rest of the suite.
- Run `./vendor/bin/pest --parallel --tia` locally; keep `--tia` out of CI.

Evidence: `pestphp/pest` `src/Mixins/Expectation.php`, `src/Expectation.php`, `src/PendingCalls/TestCall.php`, `src/ArchPresets/`, `stubs/init-laravel/Pest.php.stub`; `pest-plugin-laravel` `src/{Http,Container,Expectations}.php`; Pest docs; filamentphp/demo `tests/`; laravel/livewire-starter-kit `tests/`.
