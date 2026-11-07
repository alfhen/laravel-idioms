# HTTP layer

## Controllers

A controller action authorizes, hands validated data to Eloquent or one action/job, flashes, and returns.
```php
public function store(StorePostRequest $request): RedirectResponse
{
    $post = $request->user()->posts()->create($request->validated());

    return to_route('posts.show', $post)->with('success', 'Post created.');
}
```
Move domain work (several writes, events, subscriptions) into an action or job only when it is shared or grows past this shape. Give that class typed constructor arguments and a `fromRequest()` named constructor so it runs without HTTP:
```php
final class CreateThread
{
    public function __construct(
        private UuidInterface $uuid,
        private string $subject,
        private User $author,
        private array $tags = [],
    ) {}

    public static function fromRequest(ThreadRequest $request, UuidInterface $uuid): self
    {
        return new self($uuid, $request->subject(), $request->user(), $request->tags());
    }
}
```

### Base controller in 11+

`App\Http\Controllers\Controller` is an empty abstract class. There is no `$this->middleware()`, `$this->authorize()` or `$this->validate()` unless the project extends `Illuminate\Routing\Controller` or adds the traits back.
```php
class ArticlesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(['auth', 'verified'], except: ['index', 'show'])];
    }
}

// or, Laravel 13 attributes (Illuminate\Routing\Attributes\Controllers)
#[Middleware('auth', except: ['index', 'show'])]
class ArticlesController extends Controller
{
    #[Authorize('delete', 'comment')]
    public function destroy(Comment $comment) { /* ... */ }
}
```

## Routing

```php
Route::resource('articles', ArticlesController::class);
Route::apiResource('api/articles', Api\ArticlesController::class)->only(['store', 'update', 'destroy']);
Route::put('users/{user}/block', BlockUserController::class)->name('users.block');   // invokable
```
- Delete action is `destroy`; names are `resource.action` (`articles.destroy`), even for hand-written routes.
- Non-CRUD endpoints get a single-action invokable controller, not extra methods on a resource controller.
- Implicit binding: `Route::get('forum/{thread:slug}', ...)` with `Thread $thread`. The parameter name must match the segment, or you get an empty model instead of a 404. Keep `Route::bind()` for lookups that aren't a column.
- Nested resources: `->scopeBindings()` or `Route::resource('photos.comments', ...)->scoped(['comment' => 'slug'])` so a child from another parent 404s.
- Backed enum type-hints in route actions 404 on invalid values: `fn (Category $category) => ...`.
- `->missing(fn (Request $r) => to_route('photos.index'))` to redirect on a missing model; `->withTrashed(['show'])` for soft-deleted models.

## FormRequests

GET endpoints with filters, sorting or `per_page` get a FormRequest too (`IndexPostRequest`), with `sometimes` rules and bounded integers. Expose typed accessors so the controller never reads raw input:
```php
/** @return array{status: ?PostStatus, tag: ?string} */
public function filters(): array
{
    return ['status' => $this->enum('status', PostStatus::class), 'tag' => $this->validated('tag')];
}
```

```php
// bad
public function update(Request $request, Post $post)
{
    $request->validate(['email' => 'required|email|unique:users,email,'.Auth::id()]);
    $post->update($request->all());
}

// good
class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', Rule::unique('users')->ignore($this->user())],
            'status' => ['required', Rule::enum(Status::class)->except([Status::Archived])],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}

$user->update($request->validated());
$user->update($request->safe()->only(['name', 'email']));
```
- `get()`, `input()` and `all()` return raw input including keys not in `rules()`. Only `validated()` / `safe()` are filtered. Typed accessor methods on the request read `$this->validated('subject')`.
- `authorize()` defaults to true when missing; delete stubs that return true. When it depends on the bound model: `return $this->user()->can('update', $this->comment);`.
- `prepareForValidation()` normalizes or merges data (session values, slugs) before rules run.
- Cross-field or stateful checks go in `after(): array` returning closures or invokable classes:
```php
public function after(): array
{
    return [
        function (Validator $validator) {
            if ($this->user()->hasTooManyOrdersToday()) {
                $validator->errors()->add('order', 'You can only place 5 orders per day.');
            }
        },
    ];
}
```
- Laravel 13: `#[StopOnFirstFailure]`, `#[ErrorBag('login')]`, `#[RedirectToRoute('dashboard')]`, `#[FailOnUnknownFields]` instead of protected properties.
- Configure passwords once: `Password::defaults(fn () => Password::min(8)->uncompromised());` in `AppServiceProvider::boot()`.
- Custom rules:
```php
final class PasscheckRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Hash::check($value, Auth::user()->getAuthPassword())) {
            $fail('Your current password is incorrect.');
        }
    }
}
```
- Typed input outside FormRequests: `$request->integer('qty')`, `->boolean()`, `->date()`, `->enum('status', Status::class)`, `->string('name')->trim()`.

## Authorization

- One `{Model}Policy` in `app/Policies`, one bool method per ability. Auto-discovered; register exceptions with `#[UsePolicy(OrderPolicy::class)]` on the model or `Gate::policy()` in `AppServiceProvider` (for vendor models).
- Call `Gate::authorize('update', $thread)`, route `->can('update', 'post')`, or `#[Authorize]`.
- Class-level abilities: `Gate::authorize('create', Reply::class)`, `->can('create', Post::class)`.
- Return `Response::denyAsNotFound()` from a policy to hide existence instead of `abort(404)` in the controller:
```php
public function view(?User $user, Article $article): Response
{
    return $article->isPublished() || $user?->isAdmin()
        ? Response::allow()
        : Response::denyAsNotFound();
}
```

## API authentication and errors

### Guests: 401 for the API, redirect for the web
The skeleton's `shouldRenderJsonWhen(fn ($r) => $r->is('api/*') || $r->expectsJson())` only shapes the exception body. The guest redirect runs earlier, in `Authenticate::unauthenticated()`, whenever the request does not send `Accept: application/json`. `withMiddleware` pre-registers `redirectGuestsTo(fn () => route('login'))`, so curl, webhooks and uptime probes get a 500 `Route [login] not defined` in an app without a `login` route.
```php
// bad: API-only app, no route named login. GET /api/orders without Accept -> 500
->withMiddleware(function (Middleware $middleware): void {})

// good: no redirect for the API, and none when no web login exists
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(
        fn (Request $request) => $request->is('api/*') || ! Route::has('login') ? null : route('login'),
    );
})
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
})
```
- Keep both. A null redirect plus `shouldRenderJsonWhen` gives a JSON 401 `{"message": "Unauthenticated."}`; with a null redirect and no JSON match it is an empty 401.
- An app that serves web pages and an API keeps its web login redirect: return `route('login')` for non-API requests, never `redirectGuestsTo(fn () => null)` for all of them.
- Does not apply when a `login` route exists, when you pass a plain string (`redirectGuestsTo('/login')`), or when nothing uses `auth`.
- An upgraded app with `app/Http/Middleware/Authenticate.php` fixes it there (`redirectTo()` returns null for `api/*`), not with a second mechanism.

### Routes and guards
- JSON endpoints go in `routes/api.php`. It is mounted in the `api` group with an automatic `/api` prefix (`withRouting(apiPrefix:)` changes it), so never add `Route::prefix('api')` inside it (`/api/api/...`). The `api` group has no session, cookies or CSRF. `routes/web.php` has all three, so a JSON POST there returns 419. Fix a 419 by moving the route, never by excluding `api/*` from CSRF. Blade or Inertia pages and AJAX in the session app stay in `web`.
- Pick the guard to match the credential. `auth:sanctum` for bearer tokens and SPA cookies. A bare `auth` uses the default `web` session guard, which the `api` group never starts, so a browser cookie gives 401. `auth:api` throws `Auth guard [api] is not defined` (500) unless `config/auth.php` defines it; the skeleton has only `web`.
- `php artisan install:api` installs Sanctum (Passport with `--passport`) and wires `api:` routing. It does not call `statefulApi()` or `throttleApi()`, define a limiter, or add `HasApiTokens` to `User`. Do not run it in an app that already has a JWT, Passport or custom guard.
- `auth:sanctum,api` tries guards in order and the first that passes becomes the default guard for the request. Two middleware strings (`['auth:sanctum', 'auth:api']`) mean both must pass.
- A public route that personalises for logged-in callers reads `$request->user('sanctum')`. `$request->user()` uses the default guard, so a bearer caller looks like a guest. Do not add `auth:sanctum` to such a route: it would 401 real guests. Once `auth:sanctum` ran on the route, plain `$request->user()` works.

### Sanctum
- SPA cookie auth is opt-in: `$middleware->statefulApi()`, the SPA host in `sanctum.stateful` (`SANCTUM_STATEFUL_DOMAINS`), and a `GET /sanctum/csrf-cookie` before the login call. A request counts as stateful only when its `Referer` or `Origin` matches `sanctum.stateful`; any other request falls through to bearer auth. Expect 419 (bad CSRF) or 401 (expired session), not a redirect. Skip `statefulApi()` for a token-only mobile or third-party API.
- Abilities are scopes for third-party tokens, not authorization. For first-party SPA requests the user carries a `TransientToken`, and `tokenCan()` is always true. Enforce a token scope with the `abilities:` middleware on the route (below), so a token without it gets 403 before validation or a lookup runs; ownership stays in a policy or a scoped query. A `tokenCan()` inside a policy or controller runs after the FormRequest, so a read-only token gets 422 for a bad body instead of 403. `createToken()` defaults to `['*']`, so pass explicit abilities.
- Sanctum ships `CheckAbilities` (all listed) and `CheckForAnyAbility` (any listed), but registers no alias. Alias them in `bootstrap/app.php`: `$middleware->alias(['abilities' => CheckAbilities::class, 'ability' => CheckForAnyAbility::class])`. Run them before route-model binding with `$middleware->prependToPriorityList(SubstituteBindings::class, CheckAbilities::class)` (and the same for `CheckForAnyAbility`): otherwise binding answers first, and a token without the scope gets 404 for a missing id but 403 for another user's, which tells it which ids exist. A missing ability is a 403 (`MissingAbilityException`), a missing token a 401.
- Revoke the current token with `$request->user()->currentAccessToken()->delete()`, one token with `$user->tokens()->whereKey($id)->delete()`. A `TransientToken` has no `delete()`, so on an endpoint SPA clients can reach, branch on `instanceof TransientToken` and log out the web guard (`Auth::guard('web')->logout()`, then invalidate the session).
- Tokens never expire by default. Set `sanctum.expiration` or a per-token `expiresAt`, and schedule `sanctum:prune-expired --hours=24`.

### Rate limits
- `throttle:api` with no `RateLimiter::for('api', ...)` throws `MissingRateLimiterException` (500) on every request. Neither the skeleton nor the framework defines it, and the `api` group is not throttled by default. `$middleware->throttleApi()` adds `throttle:api` to the whole group and still needs the limiter in `AppServiceProvider::boot()`. Key by user id, with the IP for guests.
- Login, sign-up, password reset, token issue and OTP endpoints get their own named limiter keyed on identifier plus IP: `Limit::perMinute(5)->by($request->string('email')->lower().'|'.$request->ip())`. A per-IP limiter alone lets one attacker try every account.
- A throttled request returns 429 with `Retry-After`. Do not catch it to return 200.

### Status codes and error shapes
- 401 not authenticated, 403 authenticated but not allowed, 404 not found, 422 invalid input, 429 throttled. Never `abort(401)` for a missing field or a failed ownership check.
- Framework shapes are `{"message": "..."}` for 401, 403, 404 and 429, and `{"message", "errors": {field: [...]}}` for 422. Do not invent `{error: ...}` or `{success: false}`. A custom reason is `abort(403, 'Reason.')` or `Response::deny('Reason.')`. A house style changes once in `withExceptions`, never per controller.
- Never wrap a call in `catch (Exception $e)` and return `$e->getMessage()`. It leaks internals, turns 500s into 404s and adds a second shape. Throw typed exceptions (`findOrFail`, `abort`, `ValidationException`) and catch only the domain exception you translate.
- With `APP_DEBUG=false` a 404 from `findOrFail` still says `No query results for model [App\Models\Order] 5`, because the handler converts it to `NotFoundHttpException` before render callbacks run and HTTP exceptions keep their message. Render it yourself when the class name must not leak. Type-hint `NotFoundHttpException`; a `ModelNotFoundException` callback never matches.
```php
$exceptions->render(function (NotFoundHttpException $e, Request $request) {
    if ($request->is('api/*')) {
        return response()->json(['message' => 'Not found.'], 404);
    }
});
```
- Middleware priority runs `auth`, then `throttle`, then `SubstituteBindings`, then `can`. A guest asking for `/api/orders/999999` gets 401, not 404. For a signed-in user, 404 for a missing id and 403 for someone else's row tells them which ids exist. Return `Response::denyAsNotFound()` from the policy or scope the query (`$request->user()->orders()->findOrFail($id)`) when existence must stay hidden. One ownership mechanism per controller.
- API resources format output, they do not authorize. Gate sensitive attributes with `$this->when($request->user()?->can('update', $this->resource), ...)`, or return a different resource per role.

### Header-secret middleware
For a shared-secret or internal-token route, implement the marker interface `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests`. It sorts the middleware before `SubstituteBindings`, so a bad token plus an unknown `{user}` is 401, not 404.
```php
final class AuthenticateInternalToken implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.internal.token');
        throw_if($secret === '' || ! hash_equals($secret, (string) $request->bearerToken()), AuthenticationException::class);

        return $next($request);
    }
}
```
- Compare with `hash_equals`, never `===`. Refuse when the secret is empty, or `'Bearer '.config('x')` matches the header `Bearer `. Throw `AuthenticationException` (401), not `abort(403)`.

### Tests
- Guests twice per API group: `getJson()` and a plain `get()` with no `Accept` header, both `assertUnauthorized()`. `getJson()` sends `Accept: application/json`, so it cannot expose the redirect 500.
- `Sanctum::actingAs($user)` grants no abilities, so `tokenCan()`, `abilities:` and ability-aware policies return 403. Pass `['*']` or the exact abilities, and add a test with a token that lacks one (403). Non-sanctum guards use `$this->actingAs($user, 'api')`.
- `Sanctum::actingAs` skips SPA cookie, CSRF and `stateful` checks, and CSRF is off under the test runner. A test can send a `Referer` but will not see 419.
- Guards cache the resolved user. Two requests as different users in one test need `$this->app['auth']->forgetGuards()` between them.
- Assert shapes: `assertJson(['message' => 'Unauthenticated.'])`, `assertJsonValidationErrors(['email'])`, `assertNotFound()` for hidden rows.

## Responses

- After a successful write: `to_route('threads.show', $thread)->with('success', '...')` or `back()->with('status', '...')`.
- API deletes: `response()->noContent()`, not `response()->json([], 204)`.

## API resources

```php
/** @mixin Article */
class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'author' => AuthorResource::make($this->whenLoaded('author')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}

return $article->toResource();
return Article::published()->paginate()->toResourceCollection();
```
Never touch `$this->author` / `$this->tags` directly in a resource (N+1 on collections). For JSON:API, extend `Illuminate\Http\Resources\JsonApi\JsonApiResource` (Laravel 13).

Evidence: `Illuminate/Foundation/Http/FormRequest.php`, `Illuminate/Routing/Controllers/HasMiddleware.php`, `Illuminate/Routing/Attributes/Controllers/`, `Illuminate/Validation/Rule.php`, laravel.io `app/Http/Controllers/Forum/ThreadsController.php`, `app/Policies/ThreadPolicy.php`, `app/Http/Resources/ArticleResource.php`, `Illuminate/Auth/Middleware/Authenticate.php`, `Illuminate/Foundation/Configuration/Middleware.php`, `Illuminate/Foundation/Configuration/ApplicationBuilder.php`, `Illuminate/Foundation/Exceptions/Handler.php`, `Illuminate/Foundation/Http/Kernel.php`, `Illuminate/Routing/Middleware/ThrottleRequests.php`, `Illuminate/Contracts/Auth/Middleware/AuthenticatesRequests.php`, `Illuminate/Auth/Access/Response.php`, `laravel/sanctum` (`src/Sanctum.php`, `src/Guard.php`, `src/TransientToken.php`, `src/Http/Middleware/CheckAbilities.php`, `src/Http/Middleware/EnsureFrontendRequestsAreStateful.php`), laravel/docs `sanctum.md`, `errors.md`, `routing.md`.
