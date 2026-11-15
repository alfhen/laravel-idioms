# Inertia server side (inertia-laravel v2 and v3)

Applies to controllers, `HandleInertiaRequests` and tests in an Inertia app. The front end (`resources/js`, SSR, TypeScript prop types) is out of scope. Version marks: **v2**, **v3**, or both when unmarked.

## Props are public JSON

Every prop and shared prop is readable by the visitor: v2 puts the page in a `data-page` attribute, v3 in a `<script type="application/json">` tag, and every Inertia visit returns it as JSON.

```php
// bad: every non-$hidden column and every loaded relation is sent
return Inertia::render('Tickets/Show', ['ticket' => $ticket->load('assignee')]);

// good
return Inertia::render('Tickets/Show', [
    'ticket' => $ticket->only('id', 'subject', 'status'),
    'assignee_name' => $ticket->assignee?->name,
]);
```
- A model prop goes through `toArray()`: `$hidden` is the only brake. Use explicit arrays, `->only()`, `->through()` or a resource whose `toArray()` is an allow-list. Never `parent::toArray()` or `$this->resource->toArray()` in a resource: that reopens the leak.
- A bare paginator prop sends full model rows plus URL metadata. Map it and keep filters in the page links:
```php
'tickets' => Ticket::query()->latest()->paginate(15)->withQueryString()
    ->through(fn (Ticket $t) => ['id' => $t->id, 'subject' => $t->subject, 'status' => $t->status->value]),
```
- Not every list needs a resource class: an already-mapped array or a `pluck()` of pairs is fine. The rule is "no bare models". History encryption (`Inertia::encryptHistory()`) is not a leak fix.

## Shared props

`share()` runs on every request in the middleware group, including partial reloads, redirects and non-Inertia web routes.

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),                  // keeps the `errors` prop
        'auth' => ['user' => fn () => $request->user()?->only('id', 'name', 'email')],
        'countries' => Inertia::once(fn () => Country::query()->pluck('name', 'code')),   // v2.0.12+; before that a plain closure
    ];
}
```
```php
// bad: whole model, every page
'auth' => ['user' => $request->user()],
```
- Don't share `$request->user()`. The starter kit shares the whole model and relies on `$hidden`: a column added later without a `$hidden` entry leaks into every page. Use `only('id', 'name', 'email')`. If the repo already shares the model, report it as a risk and ask for `only()`; the user's own email is needed by the UI.
- Keep every database, API, cache or session read in `share()` inside a closure; a plain value runs on every request.
- A shared closure still runs on every full visit. For slow-changing reference data use `Inertia::once()` or `shareOnce()` (v2.0.12+ and v3; on an older v2 keep the closure); for data only some pages need, `Inertia::optional()`, which those pages ask for in a partial reload. Not a shared `defer()`: it is fetched after every page's first load.
- Dropping `...parent::share($request)` removes the `errors` prop and breaks all form errors.
- Flash: `Inertia::flash('toast', [...])` before the redirect (v2.0.20+ and v3). It arrives in the page-level `flash` key, not in `props`. The older `with('success', ...)` plus a shared `fn () => $request->session()->only([...])` closure still works. Don't `session()->pull()` eagerly in `share()`. Don't rewrite working flash code on a v2 app without checking the installed version.

## Lazy props and partial reloads

| prop form | first load | partial reload not asking for it | partial reload asking for it |
|---|---|---|---|
| plain value | evaluated | already evaluated in the controller, dropped from output | evaluated |
| `fn () => ...` | evaluated | not evaluated | evaluated |
| `Inertia::optional(fn)` | never | not evaluated | evaluated |
| `Inertia::defer(fn, 'group')` | not evaluated; client fetches after render | not evaluated | evaluated |
| `Inertia::always($v)` | evaluated | evaluated | evaluated |

```php
// bad: runs on every visit and every partial reload
'replies' => $ticket->replies()->latest()->limit(20)->get(),
// good
'replies' => Inertia::defer(fn () => $ticket->replies()->latest()->limit(20)->get()->map(...), 'replies'),
```
- Only closures skip partial reloads; only closures, `optional` and `defer` skip the first load. Use `defer` for slow queries, external API calls, large lists and secondary panels. Cheap, page-critical data stays a plain prop: deferred props cost a second request and need client handling.
- **v2:** partial filtering looks only at top-level props; keep `optional`, `defer` and `merge` at the top level of the props array. **v3:** nested prop types resolve at any depth. `Inertia::lazy()` is deprecated in v2 and removed in v3; use `Inertia::optional()`.

## Resources and wrapping

- A `JsonResource` prop arrives wrapped: `new TicketResource($t)` is `ticket.data.id`; a collection is `tickets.data`; a paginated collection adds `links` and `meta`.
- For one object, pass `(new TicketResource($t))->resolve($request)` to unwrap, or agree on `.data` in the client and the tests. `JsonResource::withoutWrapping()` is global and also changes API responses that share the resource classes.

## Forms, redirects and authorization

- Validate with `$request->validate()` or a Form Request. A failure redirects back, and `errors.field` (first message per field) arrives in the shared `errors` prop. Never return `response()->json(['errors' => ...], 422)` from an Inertia route. For a manual failure: `throw ValidationException::withMessages([...])` or `back()->withErrors([...])`. Two forms with the same field names on one page: `withErrors($errors, 'bagName')` plus the client `errorBag` option.
- Redirect after every write (`to_route('tickets.show', $t)`, `back()`); never return JSON, `noContent()` or a rendered page. The adapter turns the redirect into a 303 for PUT, PATCH and DELETE, but only when the request carries `X-Inertia`. Don't set 303 by hand or add middleware for it. A Blade `_method` form is a POST and unaffected.
- `Inertia::location($url)` for external or non-Inertia redirects: 409 plus `X-Inertia-Location` for an Inertia request, a plain redirect otherwise. Not `redirect()->away()`.
- Authorization stays server-side with policies (`$this->authorize()`, `Gate::authorize()`, `can:` middleware). Hiding a button is not authorization. Ship booleans, not roles:
```php
'can' => ['update' => $request->user()->can('update', $ticket), 'delete' => $request->user()->can('delete', $ticket)],
```
  Don't ship `roles`, `permissions` arrays or `is_admin` so the client can decide. Per-row `can` flags on a list can cause N+1 in the policy; eager load what it reads.
- Never put a secret in a prop even if the client masks it later; send `null` for a password field so the field exists without the stored value.
- Register `HandleInertiaRequests` in the `web` group only. No `Inertia::render` from API routes.

## Tests

Use `actingAs()` and a real request, not a direct call to the controller.

```php
$this->actingAs($user)->get(route('tickets.index'))->assertInertia(fn (Assert $page) => $page
    ->component('Tickets/Index', false)
    ->has('tickets.data', 3)
    ->has('tickets.data.0', fn (Assert $t) => $t->hasAll('id', 'subject', 'status')->missing('body')));
```
- `component($name)` fails with "page file does not exist" when `testing.ensure_pages_exist` is true, which is the default. When page files are intentionally absent, pass `false` or set `inertia.testing.ensure_pages_exist` to `false` in test setup; don't change production config for this. **v3** default page path is `js/pages` (lower-case), v2 `js/Pages`.
- A scoped `has('x', fn)` without `etc()` is strict: it fails on any unasserted key in that scope. The root scope is not strict. Use `->missing('ticket.internal_notes')` for keys that must never ship.
- Leak test independent of design: seed an alphanumeric sentinel in an internal column and `assertStringNotContainsString('SENTINEL73', json_encode($response->inertiaProps()))`. Do this for the index, show and a partial reload.
- Counting queries or sentinels needs a real request; `Inertia::render()` called directly in a unit test proves nothing about shared props.
- Resource props: assert `has('tickets.data', 3)`, not `has('tickets', 3)`.
- Partial reloads: `->reloadOnly('replies', fn ($r) => $r->has('replies')->missing('ticket'))`, `->reloadExcept(...)`, `->loadDeferredProps('replies')`. A hand-built request with `X-Inertia: true` returns JSON and `assertInertia` fails with "Not a valid Inertia response"; read `$response->json('props')` instead.
- Writes: send `->withHeader('X-Inertia', 'true')`, then `assertStatus(303)` for PUT or DELETE (a plain PUT gives 302) and `assertSessionHasErrors('subject')` on failure. Never use the `*Json` helpers for page routes: they return 422.
- Prove laziness with query counts (`DB::listen`, filter by table name): zero statements on the first load and on a partial reload that doesn't ask for the deferred prop, at least one when it does. Add this only for genuinely expensive props.
- `assertInertiaFlash` exists from v2.0.21.

## Version differences

| Topic | v2 | v3 |
|---|---|---|
| Initial payload | `data-page` attribute | script tag; still public |
| `Inertia::lazy()` | deprecated | removed; `optional()` |
| Nested prop types | top level only | any depth |
| 302 to 303 | in `Middleware::handle` | also a global `EnsureGetOnRedirect`, and `QUERY` |
| Config | `testing.{ensure_pages_exist,page_paths}` | `pages.*` plus `testing.ensure_pages_exist` |
| Flash | `Inertia::flash()` from 2.0.20, `assertInertiaFlash` from 2.0.21 | page-level `flash`, `assertInertiaFlash` |
| Requirements | Laravel 10-13 (2.0.21) | PHP 8.2+, Laravel 11.35+ |

`Inertia::optional`, `defer`, `always` and `location` exist in both; `once` and `shareOnce` from v2.0.12.

Evidence: `inertia-laravel/src/{PropsResolver,Response,ResponseFactory,Middleware,ServiceProvider}.php`, `src/Middleware/EnsureGetOnRedirect.php`, `src/Testing/{AssertableInertia,TestResponseMacros,ReloadRequest}.php`, `config/inertia.php`; inertia docs: `the-basics/responses`, `the-basics/validation`, `data-props/shared-data`, `data-props/partial-reloads`, `data-props/flash-data`, `security/authorization`, `security/history-encryption`, `getting-started/upgrade-guide`.
