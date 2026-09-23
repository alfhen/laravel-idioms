# Blade

## Layouts

New code builds layouts as components; keep `@extends/@section` only where the app already uses it, and never mix the two in one layout chain.
```blade
<x-layouts.app :title="__('Tasks')">
    @foreach ($tasks as $task) ... @endforeach
</x-layouts.app>

{{-- components/layouts/app.blade.php --}}
<title>{{ filled($title ?? null) ? $title.' - '.config('app.name') : config('app.name') }}</title>
{{ $slot }}
```
Load assets with `@vite(['resources/css/app.css', 'resources/js/app.js'])` in `<head>` and images with `Vite::asset('resources/images/logo.png')`. No hard-coded `/build` paths, no `mix()`. Feature tests that render views without built assets call `$this->withoutVite()` (per test or in the base TestCase).

## Components

```blade
{{-- components/alert.blade.php --}}
@props(['type' => 'info', 'message'])

<div {{ $attributes->merge(['class' => 'alert alert-'.$type]) }}>
    {{ $message }}
</div>
```
- Anonymous components (one file, `@props`) for presentational pieces. List every data prop in `@props` with defaults for optional ones; without it props leak onto the root element as HTML attributes and defaults never apply.
- `$attributes` goes on the **root** element so callers can add `class`, `id`, `wire:*`, `data-*`. `merge()` appends `class`; any other merged attribute is only a default the caller replaces (use `prepends()` to join). Conditional classes: `$attributes->class(['btn', 'btn-danger' => $danger])->merge(['type' => 'button'])`.
- Several target elements → filter the bag: `$attributes->only('class')`, `->except('class')`, `->whereStartsWith('wire:model')`, `->has('href')`, `->get()`. Don't hand-parse `$attributes['class']`.
- PHP values need a colon: `:user="$user"` or `:$user`. A plain attribute is a literal string. Props are camelCase in `@props`, kebab-case at the call site (`:show-refresh="true"`). `::class="{ danger: isDeleting }"` passes an Alpine binding through untouched.
- Named slots (`<x-slot:heading class="font-bold">`) for markup regions, props for scalars. Slots carry attributes (`$heading->attributes->class('text-lg')`) and `isEmpty()`/`hasActualContent()` for fallbacks.
- Class component (`app/View/Components`) only when there is logic: computed values, injected services, methods the template calls. Promoted public properties and public methods are available in the view (`:method="$method()"`). Not for passing props through.
- `@aware(['color' => 'gray'])` in a child reads a prop passed to the parent. It sees only attributes actually passed, not the parent's `@props` defaults, so repeat the default.
- Prefer components over `@include`. When you do `@include`, pass data explicitly (`@include('posts._row', ['post' => $post])`). `@each` child views don't inherit parent variables.
- Wrap repeated form plumbing (label, input, `old()` default, error, `@csrf`/`@method`) in a few form components once.

## Views render; controllers fetch

```blade
{{-- bad: a query and N+1 per row --}}
@foreach (Post::latest()->get() as $post)
    {{ $post->author->name }} ({{ $post->comments()->count() }})
@endforeach
```
```php
// good: controller
$posts = Post::with('author')->withCount('comments')->latest()->paginate(20)->withQueryString();
return view('posts.index', ['posts' => $posts]);
```
```blade
@forelse ($posts as $post)
    <x-post-row :$post />   {{-- uses $post->comments_count --}}
@empty
    <x-empty-state title="No posts yet" />
@endforelse

@if ($posts->hasPages())
    {{ $posts->onEachSide(1)->links() }}
@endif
```
- No queries, querying relationship methods, or `Auth::user()->relation` in loops. Enable `Model::preventLazyLoading(! app()->isProduction())`.
- Paginate in the controller; `->withQueryString()` (or `->appends(request()->only(...))`) keeps filters on page 2.
- Data many views need (nav counts, current team) → `View::composer('layouts.app', fn (View $view) => $view->with(...))` or `View::share` in a provider, not a `@php` query in the layout. Formatting → model accessors/methods; a presenter only when reused.
- `$loop->first/last/index/iteration/parent` instead of manual counters.

## URLs, forms, flash

```blade
<form method="POST" action="{{ route('posts.update', $post) }}">
    @csrf
    @method('PUT')
    <input name="title" value="{{ old('title', $post->title) }}" class="@error('title') is-invalid @enderror">
    @error('title')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    <input type="checkbox" name="active" value="1" @checked(old('active', $post->active))>
    <option value="{{ $v }}" @selected(old('version', $post->version) == $v)>{{ $v }}</option>
</form>
```
- `route('posts.edit', $post)` with the model; active nav with `request()->routeIs('dashboard')`. No hard-coded paths or string-concatenated URLs.
- Every non-GET form has `@csrf` (a missing one is a 419). PUT/PATCH/DELETE: `method="POST"` + `@method('PUT')`. `method="PUT"` on a `<form>` is sent as GET.
- `old('field', $default)` on every input so a failed validation keeps what the user typed. `old('title', $post)` also works.
- `@error('field') ... {{ $message }} ... @enderror`. `$errors` is always defined on web routes; no `isset($errors)`. Several forms on a page → named bag: `@error('email', 'login')`.
- `@checked`, `@selected`, `@disabled`, `@readonly`, `@required` instead of `? 'checked' : ''`.
- `@class([...])` / `@style([...])` on plain elements; `$attributes->class()` inside components. No `@if` inside `class=""`.
- After a successful POST, redirect and flash: `return to_route('posts.edit', $post)->with('status', __('Post updated.'));`. Handled failure: `back()->withErrors([...])->withInput()`. Never return `view()` from a POST handler (refresh resubmits). Show with `@session('status') {{ $value }} @endsession`.
- When the view branches on the outcome, flash a status key (`'verification-link-sent'`) and keep the copy in the view.
- JS/Alpine live validation → Precognition (`HandlePrecognitiveRequests` + the route's FormRequest), seeded with `old()` and `.setErrors({{ Js::from($errors->messages()) }})`. Don't duplicate rules in JS.

## Escaping and JS

- `{{ }}` escapes. `{!! !!}` only for HTML you produced or sanitised (rendered markdown through a purifier); never user input or flash messages that interpolate user data.
- PHP → JS/Alpine: `x-data="{ open: @js($errors->has('code')) }"`, `<script>var app = {{ Js::from($config) }};</script>`. Never `{!! json_encode() !!}` or `'{{ $x }}'` in a JS string. Pass a plain variable, not a complex expression.

## Other directives

- `@can('update', $post)` / `@cannot` / `@canany`, `@auth` / `@guest`, `Route::has('login')`. Hiding a button doesn't replace authorizing in the controller.
- Page-specific scripts: `@push('scripts')` to a layout `@stack('scripts')`; in a component that may render many times, `@pushOnce('scripts', 'chart.js') ... @endPushOnce`.
- htmx/Turbo partials: wrap the region in `@fragment('user-list')` and `return view('users.index', [...])->fragmentIf($request->hasHeader('HX-Request'), 'user-list');`. No duplicate partial view or endpoint.
- User-facing strings through `__()`, passed to props with a colon: `:title="__('Log in')"`.

Evidence: `laravel/docs/blade.md`, `validation.md`, `precognition.md`, `vite.md`, `pagination.md`; `Illuminate/View/Compilers/Concerns/*`, `Illuminate/View/ComponentAttributeBag.php`, `Illuminate/Http/Concerns/InteractsWithFlashData.php`; laravel.io `resources/views/components/`; livewire-starter-kit `resources/views/`.
