# Collections and helpers

In application code use `collect()` and `str()`. Framework and package internals use `new Collection(...)` / `new Stringable(...)` so they don't depend on global helpers; don't copy that style into apps.

## Pipelines instead of loops

| Loop shape | Replace with |
|---|---|
| push matching items into `$out[]` | `filter()` / `reject()` then `values()` |
| push transformed items | `map()` |
| `$out[$key] = $value` | `mapWithKeys(fn ($x) => [$k => $v])`, or `keyBy('field')` |
| two arrays by condition | `[$yes, $no] = $c->partition(fn ...)` |
| `$total += ...` | `sum('field')` or `sum(fn ...)` |
| flag + `break` | `contains(fn ...)`, `every(fn ...)`, `doesntContain(...)` |
| find first match | `firstWhere('name', $name)`, `first(fn ...)` |
| build a joined string | `map(...)->implode('|')` |
| `new Money($x)` per item | `mapInto(Money::class)` |
| `instanceof` check in loop | `ensure(Handler::class)` at the boundary |

```php
$names = $users
    ->filter(fn (User $u) => $u->active)
    ->map(fn (User $u) => strtoupper($u->name))
    ->values();

$columns = Collection::wrap($column)->mapWithKeys(fn ($c) => [$c => $time])->all();

[$framework, $other] = collect($providers)
    ->partition(fn ($p) => str_starts_with($p, 'Illuminate\\'));

$fails = collect(explode(' ', $value))
    ->contains(fn ($word) => filter_var($word, FILTER_VALIDATE_URL));

$regex = $paths->map(fn (string $p) => preg_quote($p, '/'))->implode('|');
```

Use `reduce()` only for folds that aren't sums (building a string, composing state).

## Higher-order messages

When the closure calls one method or reads one property, use the proxy:
```php
$notifications->each->markAsRead();
$ids   = $models->map->getKey();
$total = $orders->sum->total;
collect($handlers)->ensure(Handler::class)->each->handle();
```
Proxies exist for `map, each, filter, reject, sum, contains, every, first, groupBy, keyBy, partition, sortBy, unique, when, unless` and others in `EnumeratesValues::$proxies`. `reduce`, `pluck` and `mapWithKeys` have none.

## Keeping a chain unbroken

```php
$posts = Post::query()
    ->when($request->filled('tag'), fn ($q) => $q->whereHas('tags', fn ($q) => $q->where('slug', $request->tag)))
    ->latest()
    ->get();

$lines->filter()->whenNotEmpty(fn ($c) => $this->newLine());
$data->pipe(fn ($d) => $section !== 'Environment' ? $d->sort() : $d);
```

## Strictness and memory

- `sole()` when exactly one match is an invariant; it throws on zero or many. Don't null-check it like `first()`.
- `lazyById(500)` / `chunkById(1000, ...)` / `cursor()` for large tables. `chunk()` skips rows when the loop updates the filtered column.
- Wrap generators in `LazyCollection::make(function () { ... yield ...; })`. It is single-pass: `count()` then iterate runs the source twice unless you `->remember()`.

## When foreach wins

Keep `foreach` for side-effect loops that need early `return`/`continue`, write to outer state per item, or batch external calls:
```php
foreach ($urls->pluck('path')->unique()->chunk(100) as $paths) {
    $viewCounts = $viewCounts->merge($this->fetch($paths));
}
```
Never force this into `each(function () use (&$viewCounts) { ... })`.

## When array functions win

A pipeline earns its place by replacing an accumulator loop or a multi-step transformation. A one-line `array_keys(array_filter(...))` or `array_values(array_unique(...))` over a plain array is already idiomatic; don't rewrite it if the rewrite costs a type.

The framework's generics make `all()` `array<TKey, TValue>` and `keys()`/`values()` `static<int, …>`, so `->keys()->all()` and `->values()->all()` are `array<int, T>` (as is `Eloquent\Collection::modelKeys()`). Larastan never narrows that to `list<T>` (PHPStan reports the mismatch from level 7), while PHPStan types `array_keys()` and `array_values()` as lists.
```php
// bad: a temp exists only to carry a @var
/** @var list<string> $enabled */
$enabled = collect($this->languages($account))
    ->filter(fn (array $language): bool => $language['enabled'] ?? false)
    ->keys()
    ->all();

return $enabled;

// good
return array_keys(array_filter($this->languages($account), fn (array $language): bool => $language['enabled'] ?? false));
```
When the method returns or a property holds `list<T>`, write it without a temp:
- one step (including a loop that only maps or only filters): `array_map(fn ..., $list)`, `array_keys(array_filter(...))`, `array_values(array_filter(...))`;
- a real multi-step transformation, or the loop it replaces: `return array_values(collect($rows)->map(...)->filter(...)->sort()->all());`;
- widen the declared type to `array<int, T>` only when no caller relies on a list, e.g. a private property read only by `in_array()`, `foreach` or `=== []`; then assign the pipeline directly (`$this->hosts = collect($hosts)->map(...)->values()->all();`).

A `Collection<int, string>` return type doesn't avoid this: `TValue` isn't covariant, so `->map(fn (string $h): string => strtolower($h))` returns `Collection<int, lowercase-string>` and fails at level 8.

## Arr and data_get

```php
$classes = Arr::wrap($class);                                    // one-or-many
$attrs   = Arr::only($attributes, ['subject', 'body', 'slug']);
$config  = Arr::except($config, ['read', 'write']);
$psr4    = data_get($composer, 'autoload.psr-4', []);            // nested, may be missing
$model   = Arr::first($this->items, fn ($m) => $m->getKey() == $key, $default);
$matches = Arr::where($providers, fn ($p) => $p instanceof $name);
```
Use `Arr::first()` / `Arr::last()`, not global `array_first()` — Laravel 13 ships `symfony/polyfill-php85`, whose `array_first()` takes no callback.

## Strings

Chain multi-step manipulation with `str()` / `Str::of()`; use `Str::` statics for single operations.
```php
$name    = str($method)->after('has')->lcfirst()->toString();
$path    = Str::of($file)->beforeLast('/')->finish('/');
$excerpt = Str::limit(strip_tags($html), 100);
$host    = Str::chopStart($url, ['https://', 'http://']);
```
`str()` returns a `Stringable`. Cast with `(string)` or `->toString()` before strict comparisons, array keys or typed `string` parameters.

## Flow helpers

```php
return tap($this->makeConnection($name), fn ($c) => $this->connections[$name] = $c);
return new ProcessResult(tap($process)->run($output));   // returns $process, not run()'s result

$attributes = array_merge($attributes, value($values));  // value-or-closure

abort_unless(Storage::disk($disk)->exists($path), 404);  // HTTP code
throw_unless($model instanceof Model, CouldNotLogActivity::couldNotDetermineUser($subject));

return retry(2, fn () => $this->migrator->repositoryExists(), 0, fn ($e) => $e instanceof QueryException);
$class = rescue(fn () => $model->toResource()::class, null, report: false);
Http::retry(3, 100, fn ($e) => $e instanceof ConnectionException)->get($url);

public function frames(): array
{
    return once(fn () => $this->computeFrames());      // per instance, per request
}
```
- `retry()`'s third argument is milliseconds; always pass the `when` callback, or it retries programmer errors too.
- `blank()` / `filled()`: whitespace, `null`, `[]` and empty collections are blank; `0`, `'0'` and `false` are filled. `empty()` treats `'0'` as empty — don't swap them blindly.
- Prefer `?->` over `optional()` for method/property access.

## Numbers, sleep, timing

```php
Number::fileSize($bytes, 2);
Number::currency($amount, in: 'EUR', locale: 'da');
Number::ordinal($position);

Sleep::for(2)->seconds();          // tests: Sleep::fake(); Sleep::assertSleptTimes(3);
Benchmark::dd(fn () => User::count(), iterations: 10);
```
Use `Sleep` instead of `sleep()`/`usleep()` so tests can fake it.

## Pipeline

For ordered pass-through stages:
```php
$order = Pipeline::send($order)->through([ApplyDiscount::class, CalculateTax::class])->thenReturn();
```

## Pitfalls

- `filter`, `reject`, `sortBy`, `unique`, `where*` keep original keys: a list serialises as `{"1":...,"3":...}` without `->values()`.
- Collection methods return new instances; `$c->map(...)` without assignment does nothing. `transform`, `push`, `put`, `forget`, `pull`, `pop`, `shift`, `prepend` mutate.
- `each()` stops when the callback returns exactly `false`: `fn ($x) => $x->save()` can stop early.
- `contains`, `where`, `whereIn` compare loosely; use `containsStrict` / `whereStrict` / `whereInStrict` with mixed `'1'`/`1`/`true`.
- `unique()` with no key runs `array_unique($items, SORT_REGULAR)`: `'0'`/`'00'`, `'1e3'`/`'1000'` and `1`/`'1'`/`true` each collapse to one. `uniqueStrict()` compares with `===`, so it replaces an `in_array($x, $seen, true)` dedupe, at the same O(n²) cost (it rejects through `in_array(..., true)`; about 0.4 s for 20k strings, against 0.3 ms for `array_unique()`).
- Plain `array_unique()` compares as strings: `1`/`'1'` collapse, `'0'`/`'00'` don't, and nested arrays raise "Array to string conversion" and collapse into one. It is neither strict nor the same as `unique()`; on a list of strings, keep it.
- `env()` in app code returns null after `config:cache`.

Evidence: `Illuminate/Collections/Traits/EnumeratesValues.php`, `Illuminate/Collections/Collection.php`, `Illuminate/Support/helpers.php`, `Illuminate/Database/Concerns/BuildsQueries.php`, laravel.io `app/Console/Commands/UpdateArticleViewCounts.php`.
