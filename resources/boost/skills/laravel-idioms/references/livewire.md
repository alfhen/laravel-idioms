# Livewire components (v3 and v4)

Applies to plain Livewire components. Filament owns its own authorization and tables (see `filament.md`); don't add these rules to Filament pages. A simple page needs no component: don't convert a controller plus Form Request to Livewire for its own sake. Version marks: **v3**, **v4**, or both when unmarked.

## Trust boundary

Every public property is browser-writable, every public method and `#[On]` listener is an endpoint, and a component is rehydrated on each action without `mount()` running again.

```php
// bad: the browser can write `bookingId`, so any booking can be cancelled
public int $bookingId;
public string $reason = '';
public function cancel(): void { Booking::findOrFail($this->bookingId)->cancel(); }

// good: lock the id, leave the bound field open, authorize in the action
#[Locked] public int $bookingId;
public string $reason = '';                       // wire:model="reason": never lock this

public function cancel(): void
{
    $booking = Booking::findOrFail($this->bookingId);
    $this->authorize('cancel', $booking);       // 403 AuthorizationException
    $booking->cancel();
}
```
- `#[Locked]` is for ids, flags and state the browser must never write. It throws `CannotUpdateLockedPropertyException` on any client update, deep paths included, and does not stop server-side writes. Never put it on a `wire:model` field: every update would throw.
- A typed public model property (`public Booking $booking`) is tamper-proof. The key comes from the checksummed snapshot: `set('booking', $otherId)` is ignored and `set('booking.title', 'x')` throws. It needs no lock, so `#[Locked] public Booking $booking` is redundant. Don't bind `wire:model="booking.title"` to it; copy fields to scalar properties or a form object. Type the property so a wrong-type update dies as a 419.
- `#[Locked]` is not authorization. `mount()` may have been given an id the user may not see.
- Authorize inside each action and each `#[On]` listener, not only in `mount()` or the route. `mount()` runs once; every action is a later POST to `/livewire/update`, and permissions can change in between. Use `$this->authorize()` or `Gate::authorize()`; both work in v3 and v4. **v4 only:** `#[Authorize('update', 'booking')]` on the method resolves the argument from a parameter or property and 403s before the body runs.
- Action and listener arguments are untrusted. `delete(int $id)` loads through the user's scope (`$user->bookings()->findOrFail($id)`) or authorizes the loaded model. A listener may be sent any params with its event name: don't trust an id it carries.
- Every public method below `Livewire\Component` is callable, including methods from traits. Helpers like `recalculate()` or `deleteAll()` must be `protected` or `private`. Lifecycle hooks (`mount`, `boot`, `updated*`, `hydrate*`, ...) and `render` are blocked from the browser.
- Route middleware is re-applied on `/livewire/update` only for the persistent set: `Authenticate`, `Authorize` (`can:`), `SubstituteBindings`, `AuthenticateWithBasicAuth`, `RedirectIfAuthenticated`, Sanctum's `EnsureFrontendRequestsAreStateful`, Jetstream's `AuthenticateSession`. Custom middleware, or middleware with arguments such as `role:admin`, is dropped unless registered with `Livewire::addPersistentMiddleware([...])` (argument-free). So `can:update,booking` on the route protects actions; `role:admin` does not.
- Not over-locking: `$search`, `$title` and any bound field stay unlocked. Not over-authorizing: no `authorize()` in `render()`, computed getters or per-keystroke hooks. Protected data read there still goes through a scoped query (`$this->user->notes()`) or a route covered by persistent middleware, so access revoked after `mount()` stops on the next request.

## Validation

```php
// bad: untouched fields were never validated; all() also returns $search, $page, ...
Article::create($this->form->all());

// good: write the validated array
$article = Article::create($this->form->validate());
```
- Call `$this->validate()` or `$this->form->validate()` in every action that writes, and persist its return value. It returns only fields that have rules.
- `#[Validate]` runs only when that property is updated in the request. A deferred `wire:model` or a crafted request skips it. The invalid value also stays in component state after the error.
- `rules()` never runs on a property update, only on `validate()`. A bare `#[Validate]` opts a property into live validation; `#[Validate('...', onUpdate: false)]` keeps the rule on the attribute but runs it only on `validate()`. Use `rules()` for rule objects (`Rule::unique()->ignore($this->article)`, `Password::defaults()`). Don't name a property or method `rules`, `messages` or `validationAttributes`.
- Form objects (`Livewire\Form`, v3 and v4) for multi-field create and edit: `public ArticleForm $form;`, `$this->form->fill($article)`, `wire:model="form.title"`, `$this->form->reset()` after a save. A model id inside a form object is still client-writable: lock it there or keep the model on the component. Keep `$fillable` on the model as the second line.
- Don't duplicate rules in a Form Request and the component unless a controller shares them.

## Queries and state

```php
// bad: ids round-trip; every later request re-queries and drops the eager load
public $articles;
public function mount() { $this->articles = Article::with('tags')->get(); }

// good
#[Computed]
public function articles() { return Article::with('tags')->latest()->paginate(10); }   // blade: $this->articles
```
- Query in `#[Computed]` methods or `render()`. Never hold an Eloquent collection (or a `select`-constrained model) in a public property: only keys are sent, the next request re-queries by key, and the eager loads are lost (measured: 8 queries against 2 for 7 rows). A single typed `public Article $article` is fine.
- `#[Computed]` memoises per request. `persist: true` caches per component instance. `cache: true` is shared across ALL users and instances unless you set `key:`: never use it for user- or tenant-scoped data. Bust with `unset($this->articles)`. On Laravel 13 a cached computed that returns models is re-evaluated unless `cache.serializable_classes` allows them; cache scalars and arrays.
- Trivial scalars gain nothing from `#[Computed]`. Don't `->get()` an unbounded table.
- Pagination: `use WithPagination;` and `paginate()` inside the computed. Call `$this->resetPage()` in `updatedSearch()`. `WithoutUrlPagination` drops `?page`. `#[Url]` and `#[Session]` properties are client-controlled: clamp or validate them.
- `wire:key` on every loop item, plain elements and child components: `<livewire:row :$article :wire:key="$article->id" />`. v4's `smart_wire_keys` (default on) does not remove the need in loops. Don't use `$loop->index` for deletable or reorderable lists.
- Public properties travel in every request and response (default 1 MB cap). Keep them to scalars, small arrays and ids.

## Behaviour

- `wire:model` is deferred by default in v3 and v4. Add `.live`, `.live.blur` or `.live.debounce` only where the server must react per keystroke (search, live validation). **v4:** bare `.blur` and `.change` also defer client state; the v3 meaning is `.live.blur`.
- Keep `updated*` hooks cheap: no queries, HTTP or API calls, because they run on every live update. Heavy work goes in the action or a queued job.
- Redirect with `$this->redirectRoute('articles.index', navigate: true)` or `redirect(route(...), navigate: true)`. Flash with `session()->flash()` first. A redirect skips the re-render.
- Events: `$this->dispatch('article-saved', id: $article->id)` with `#[On('article-saved')]` on the receiver (see the listener rule above); `->to(Other::class)` targets one component.
- Don't wrap actions in `try/catch` to hide `AuthorizationException`; it becomes a 403.

## Uploads

```php
use WithFileUploads;

public $cover;

public function save(): void
{
    $this->authorize('update', $this->article);
    $this->validate(['cover' => ['required', File::image()->max('2mb')]]);
    $path = $this->cover->store('covers', 'local');            // hashed name, named disk
    $this->article->update(['cover_path' => $path]);
}
```
- Livewire's temp upload endpoint applies only `file|max:12288` by default, so up to 12 MB lands in `livewire-tmp` before your rules run. Set `temporary_file_upload.rules` for a global cap, and validate type and size again in the action before `store()`.
- Store with `store()` or `storeAs()` on a named disk using a name you generate. Never put `getClientOriginalName()` in a path; keep it in a column for display (see `files-and-uploads.md`). A fresh property with no file must fail validation, not call `null->store()`.
- `temporaryUrl()` previews work only for `preview_mimes`; other types throw.

## Lazy loading

- `<livewire:stats lazy />` with `placeholder()` defers a slow panel (v3). **v4** adds `defer`, `@island`, `#[Isolate]`, `#[Async]`. Lazy props are serialised to the client and models re-queried before `mount()`: pass ids or scalars and authorize inside the lazy component. Only for slow panels, not every component.

## Version differences

| Topic | v3 | v4 |
|---|---|---|
| Component format | class plus `render()` view | single-file components are the `make:livewire` default (`--class` for a class); `pages::` and `layouts::` namespaces |
| Routing | `Route::get('/x', Class::class)` | `Route::livewire('/x', Class::class or 'pages::x')`, required for single-file |
| `#[Authorize]` | not available (use `$this->authorize()`) | available |
| Layout config | `layout` => `components.layouts.app` | `component_layout` => `layouts::app` |
| `wire:model.blur` / `.change` | network timing only | also defer client state |
| `smart_wire_keys` | off | on |
| Same in both | `#[Locked]`, `#[Computed]`, `#[Validate]`, forms, `WithPagination`, `WithFileUploads`, `#[On]`, persistent middleware | |

Follow the repo's component style (class or single-file); don't insist on either.

## Tests

- `Livewire::test('articles.edit', ['article' => $article])` works for single-file and class components; pass a class name only for class components. v4 starter-kit pages use `'pages::settings.profile'`.
- Assert the boundary, not only the happy path:
```php
Livewire::actingAs($intruder)->test('articles.edit', ['article' => $article])->assertForbidden();

$t = Livewire::actingAs($owner)->test('articles.edit', ['article' => $article]);
$this->actingAs($intruder);                       // switches the user for the next request
$t->set('title', 'x')->call('save')->assertForbidden();
```
- Authorization, HTTP and model-not-found exceptions become responses (`assertForbidden()`); Locked, missing-method and lifecycle errors propagate as exceptions.
- Tamper attempts: `set()` the id property to another record's id inside `try/catch (Throwable)`, then call the action and assert the other record in the database is unchanged. Don't assert the exception type: a locked property throws, a typed model is ignored, an unlocked one is accepted.
- Validation: set an invalid value and call `save` in one chain, and call `save` with the field never set. Assert `assertHasErrors` and the row unchanged.
- `Livewire::test()` runs without middleware. Test route protection with `$this->get(route('articles.edit', $article))` (guest redirect, `assertSeeLivewire()`).
- Query counts on a later request: `DB::enableQueryLog()`, then `->set('search', 'x')`; compare 5 against 25 rows on the same page and assert `c25 <= c5 + 1`.
- Uploads: `Storage::fake('local')`, `->set('cover', UploadedFile::fake()->image('a.png'))->call('save')`, `Storage::disk('local')->assertExists($article->cover_path)`; an invalid type leaves the disk empty. Disable lazy loading with `Livewire::withoutLazyLoading()`.

Evidence: `livewire/src/Features/SupportLockedProperties/BaseLocked.php`, `Features/SupportModels/{ModelSynth,EloquentCollectionSynth}.php`, `Features/SupportComputed/BaseComputed.php`, `Features/SupportValidation/HandlesValidation.php`, `Features/SupportAuthorization/BaseAuthorize.php`, `Features/SupportEvents/SupportEvents.php`, `Features/SupportLifecycleHooks/SupportLifecycleHooks.php`, `Features/SupportTesting/RequestBroker.php`, `Mechanisms/HandleComponents/HandleComponents.php`, `Mechanisms/HandleSynths/HandleSynths.php`, `Mechanisms/PersistentMiddleware/PersistentMiddleware.php`, `config/livewire.php`; `livewire/docs/{security,properties,attribute-locked,attribute-authorize,validation,forms,computed-properties,pagination,uploads,lazy,upgrading,testing,redirecting,events}.md`.
