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

Evidence: `Illuminate/Foundation/Http/FormRequest.php`, `Illuminate/Routing/Controllers/HasMiddleware.php`, `Illuminate/Routing/Attributes/Controllers/`, `Illuminate/Validation/Rule.php`, laravel.io `app/Http/Controllers/Forum/ThreadsController.php`, `app/Policies/ThreadPolicy.php`, `app/Http/Resources/ArticleResource.php`.
