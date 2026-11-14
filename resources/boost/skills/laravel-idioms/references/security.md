# Security baseline

Rules agents get wrong that no other reference covers. Files, signed URLs and header-secret middleware are in `files-and-uploads.md` and `http-layer.md`; webhook signatures are in `webhooks.md`. Each rule below says where it does not apply: flag a request-derived value, not every raw expression or every `like`.

## Raw SQL

```php
// bad: request data inside the SQL string
->whereRaw("lower(email) = '{$request->email}'")
->orderByRaw("FIELD(status, {$request->statuses})")
// good
->whereRaw('lower(email) = ?', [Str::lower($data['email'])])
->whereIn('status', $data['statuses'])
```
- `whereRaw`, `selectRaw`, `orderByRaw`, `havingRaw`, `groupByRaw`, `DB::select()` and `DB::statement()` take the string verbatim. Only `?` and `:name` values are bound. A request value inside the string is injection, including in `selectRaw("COUNT(*) as {$alias}")`.
- Try a builder method first (`whereIn`, `whereLike`, `whereDate`, `orderBy`).
- `where('col', '=', DB::raw($input))` is the same hole: an `Expression` skips binding.
- A `?` can't stand in for an identifier or `asc`/`desc`; see the next section.
- Not a problem: literal raw expressions (aggregates, `CASE`), and a fragment chosen by `match` or a constant map from a validated key (`'daily' => "date_trunc('day', created_at)"`). Flag only a variable that is request-derived or unvalidated.

## Column names and sort direction

Identifiers are never bound. On Laravel 13 the grammar quotes them (a `"` is doubled), so `orderBy($request->sort)` is not injectable. The failures are:
- an unknown column is a `QueryException` 500, and `?sort[]=x` is a `TypeError` 500;
- a real hidden column (`?sort=password`, `?filter[api_token]=...`) works as a sort or filter oracle that leaks the value bit by bit, and `select($request->fields)` returns columns `$hidden` would have kept back;
- a direction other than `asc`/`desc` throws `InvalidArgumentException` (500);
- `where($col, $op, $val)` with a request operator: an unknown operator is silently treated as the value with `=`, not an error;
- `where($request->input('filter'))` (an array) makes every key a column name.
```php
// bad
$query->orderBy($request->input('sort', 'id'), $request->input('dir', 'asc'));
// good: in the FormRequest
'sort' => ['sometimes', Rule::in(['title', 'created_at', 'price'])],
'dir'  => ['sometimes', Rule::in(['asc', 'desc'])],
// in the controller
$query->orderBy($request->validated('sort', 'created_at'), $request->validated('dir', 'desc'))->orderBy('id');
```
- Allow-list with `Rule::in` (422 for arrays, junk and unknown names). `in` is case-sensitive, `orderBy` is not.
- When the public name differs from the column, use a `match` or map (`'newest' => ['created_at', 'desc']`).
- Constants, enums and a trusted admin path need no `Rule::in`. Don't wrap every static column.

## LIKE search

Binding stops injection, not wildcards: `?q=%` matches every row, `?q=_` any one character, and it makes scans cheap to trigger.
- Laravel has no escape helper, and `whereLike()` does not escape its value.
- Escape with an explicit `ESCAPE` character in one scope or macro, so every search shares it:
```php
// bad
$q->where('title', 'like', "%{$term}%");
// good: works on SQLite, MySQL and Postgres
#[Scope]
protected function search(Builder $q, string $term): void
{
    $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    $q->whereRaw("title like ? escape '!'", [$like]);
}
```
- `#[Scope]` is Laravel 13; on 11-12 write `scopeSearch()`.
- A backslash escape (`\%`) relies on the MySQL and Postgres default escape character and matches nothing on SQLite.
- Only for user-facing literal search, or where wide scans are costly. A prefix search on a small admin table doesn't need it. `whereFullText` (MySQL/Postgres) is for natural-language search, not a wildcard escape.

## Tenancy in validation

`exists:` and `unique:` check the table, not the owner. `'shipment_id' => 'exists:shipments,id'` accepts another warehouse's id, and the controller then uses it.
```php
// bad
'shipment_id' => ['required', 'exists:shipments,id'],
// good
'shipment_id' => ['required', Rule::exists('shipments', 'id')->where('warehouse_id', $this->user()->warehouse_id)],
// or skip the rule and resolve through the owner: $request->user()->warehouse->shipments()->findOrFail($id)
```
- The same goes for `Rule::unique(...)->where(...)` and array items (`'ids.*' => Rule::exists(...)->where(...)`).
- Never accept `warehouse_id` or `user_id` from the body; set it from the authenticated user.
- `Rule::exists()` runs a plain query: it ignores Eloquent global scopes, so a scope-based tenant filter is not applied.
- Reference tables (countries, currencies, shared catalog ids) don't need an owner filter.

## Secret columns

- Every column holding a token, secret, API key, OTP or password hash is hidden: Laravel 13 `#[Hidden(['api_token', 'signing_secret'])]`, Laravel 11-12 `protected $hidden`. Prefer an API Resource over returning a model. Don't hide a column an API must return.
- `encrypted` (and `encrypted:array`, `AsEncryptedCollection`) is for secrets you must read back, such as an upstream access token. It does not hide the value from JSON, can't be queried, sorted, indexed or made unique, needs a `TEXT` column, and ties the data to `APP_KEY`.
- Rotating `APP_KEY`: list old keys in `APP_PREVIOUS_KEYS` (comma-separated). Reads try the current key and then the previous ones; writes use the current key; nothing is re-encrypted for you. Without it every encrypted column throws `DecryptException`.
- Adding `encrypted` to an existing column doesn't convert data: old plaintext rows throw on read. Backfill in a migration with `DB::table()`.
- A token you only verify (an API key a client sends) is stored as `hash('sha256', $plain)` in a unique column, shown once, and compared with `hash_equals`. Don't encrypt it.
- `Str::random()` is already a CSPRNG; don't swap it for `bin2hex(random_bytes())`. Flag `rand()`, `mt_rand()`, `uniqid()`, `md5(time())` as token generators. Use 40+ characters for a bearer-style token.
- Not for every personal-data column: encrypting a searchable column breaks the search.

## Secrets out of logs

- Don't log `$request->all()`, `$request->headers->all()`, `$response->body()` or a model whose secret columns aren't hidden: passwords, bearer tokens and OTPs end up on disk and in the error tracker. The framework does not redact. Log ids and an allow-list (`$request->safe()->only([...])`); `$request->except([...])` is the fallback.
- `#[\SensitiveParameter]` replaces that argument with a `SensitiveParameterValue` in stack traces only. Put it on your own parameters that take a secret (`#[\SensitiveParameter] string $apiKey`). It does not scrub log context arrays or dumps, and not every method needs it.
- `dontFlash` (default: `current_password`, `password`, `password_confirmation`) only decides what a validation redirect flashes as old input. Add your own fields with `$exceptions->dontFlash([...])` in `bootstrap/app.php`. It does not touch logs.
- `APP_DEBUG=false` in production. Never return `config('services.x.secret')`, `env()` or `$e->getMessage()` in a response.

## Comparing secrets

- Use `hash_equals($known, $given)` with both cast to string for tokens, HMACs, OTP and reset codes, query-string secrets, `Auth::viaRequest` callbacks and custom guards. Use `Hash::check()` for stored password hashes.
- Never `==`, `===` or `!==` on a secret: not constant-time, and `==` juggles numeric strings (`"0e12" == "0e34"`).
- Refuse when the configured secret is empty: `'' === ''` otherwise authenticates.
- `===` on ordinary values (status strings, slugs) is correct.

## Mass assignment

- `$guarded = []`, `#[Guarded([])]` and `#[Unguarded]` are the same as `$fillable` with every column, for anything that reaches `create`, `update` or `fill` from input. `Model::unguard()` turns the guard off for the whole process: seeders only, as `Model::unguarded(fn () => ...)`.
- `forceFill($request->validated())` bypasses `$fillable`. `forceFill` server-computed values only (`forceFill(['verified_at' => now()])`).
- `validated()` includes every field the rules mention: `role`, `is_admin`, `warehouse_id`, `status`, `price` never appear in the rules or in `$fillable`. Set them in code.
- Seeders, factories, tests and server-computed assignments may use `forceFill` and `unguard`. The rule is about request-derived arrays.

## Open redirects

`redirect($request->input('next'))` sends a visitor off-site: `redirect()` passes an absolute URL through unchanged.
```php
// bad
return redirect($request->input('return'));
// good: allow-listed relative path, else a named route
$to = (string) $request->input('return', '');
$safe = str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_starts_with($to, '/\\');

return redirect($safe ? $to : route('dashboard'));
```
- `starts_with:/` alone accepts `//host` (scheme-relative); also reject `//` and `/\`. Or map a short key to a named route.
- Safe: `redirect()->intended()` (the framework stored the URL in the session), `route()`, `back()` for same-site form flows, a fixed `redirect()->away('https://...')`.

## Paths from input

- Storage disks reject `../` with `PathTraversalDetected`. `file_get_contents()`, `unlink()`, `File::get()`, `response()->file()` and `download()` on `storage_path($request->f)` do not.
- Never build a path from input. Resolve it from a row id or an allow-listed key. If a client name must become a file name: `basename()` plus an extension allow-list.

## Rate limiting

- An unnamed `throttle:5,1` keys on user id (or domain and IP), not on the route. Two routes using it share one counter, and `throttle:5,1` with `throttle:60,1` on other routes share a cache key with different limits. Use named limiters (`RateLimiter::for()`), or a distinct third argument (`throttle:5,1,login`).
- Limits belong on: login, registration, password reset, verification resend, OTP and 2FA; public unauthenticated POST forms; endpoints that send mail or SMS or call a paid service; token creation; costly search, export and report endpoints. A limiter on every GET only adds 429 noise.
- Key a credential endpoint by identifier plus IP, with a looser per-IP limit. Two `Limit`s in one limiter with the same `by()` share a counter.
- Fortify (and the starter kits that ship it) already limits login and two-factor through its own named limiters. Don't stack a second login throttle.
- Never on webhook receivers (`webhooks.md`).
- Reset and login return one generic message whether or not the account exists. A sign-up form can't hide "email taken".

## IDOR on bulk ids and nested routes

- `whereIn('id', $request->ids)->update(...)` touches other owners' rows. Scope it: `$request->user()->orders()->whereKey($ids)`.
- `Route::resource('articles.comments')` binds `/articles/1/comments/99` even when 99 belongs to article 2. Add `->scopeBindings()` for a 404. Binding doesn't authorize.
- `DB::table()` skips Eloquent global scopes, as does `withoutGlobalScopes()`: add the owner `where` by hand in exports and reports.

## Tests

- Sort and direction: valid values return 200 in order; `?sort=(select 1)`, `?sort[]=x`, a hidden column and `?dir=sideways` each return 422, never 500.
- LIKE: seed `100% sure`, `1000 things`, `a_b`, `axb`; `?q=100%25` returns only `100% sure`, `?q=a_b` only `a_b`.
- Tenancy: another owner's id in the body is 422 (or 404); the owner column comes from the user even if the body sends one.
- Responses: no secret column in list or show output, with the secret seeded.
- Secrets: an empty configured secret with an empty header is rejected; assert the log file holds no password or token string after a login.
- Redirects: `https://evil.example`, `//evil.example`, `/\evil.example` and `javascript:alert(1)` land on the fallback.
- Limits: the Nth + 1 attempt is 429 with `Retry-After`; `travel()` past the window clears it.

Evidence: `Illuminate/Database/Query/Builder.php` (`whereRaw`, `orderBy`, `where`, `whereLike`), `Illuminate/Database/Grammar.php` (`wrapValue`), `Illuminate/Database/Eloquent/Concerns/{HasAttributes,GuardsAttributes}.php`, `Illuminate/Database/Eloquent/Model.php` (`forceFill`), `Illuminate/Validation/Rules/{In,Exists}.php`, `Illuminate/Encryption/{Encrypter,EncryptionServiceProvider}.php`, `Illuminate/Foundation/Exceptions/Handler.php` (`dontFlash`), `Illuminate/Routing/{UrlGenerator,Redirector}.php`, `Illuminate/Routing/Middleware/ThrottleRequests.php`, `Illuminate/Filesystem/{ServeFile,ReceiveFile}.php`, `Illuminate/Support/Str.php` (`random`), `laravel/sanctum/src/PersonalAccessToken.php`; `laravel/docs/{queries,validation,eloquent-serialization,eloquent-mutators,encryption,errors,rate-limiting,fortify}.md`.
