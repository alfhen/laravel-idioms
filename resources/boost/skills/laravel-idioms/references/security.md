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

Identifiers are never bound. The grammar quotes them (a `"` is doubled), so `orderBy($request->sort)` is not injectable. The failures are:
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
- `#[Scope]` is Laravel 12.4+; before that write `scopeSearch()`.
- A backslash escape (`\%`) relies on the MySQL and Postgres default escape character and matches nothing on SQLite.
- Only for user-facing literal search, or where wide scans are costly. A prefix search on a small admin table doesn't need it. `whereFullText` (MySQL/Postgres) is for natural-language search, not a wildcard escape.

## Tenancy in validation

`exists:` and `unique:` check the table, not the owner. `'project_id' => 'exists:projects,id'` accepts another organization's id, and the controller then uses it.
```php
// bad: no owner, no type
'project_id' => ['required', 'exists:projects,id'],
'code'       => ['required', 'exists:invites,code'],
// good: a type rule first, then the owner scope
'project_id' => ['required', 'integer:strict', Rule::exists('projects', 'id')->where('organization_id', $this->user()->organization_id)],
'code'       => ['required', 'string', 'max:64', 'exists:invites,code'],
// or skip the rule and resolve through the owner: $request->user()->organization->projects()->findOrFail($id)
```
- Every `exists` and `unique` gets a type rule before it: `integer:strict` for ids in a JSON body (plain `integer` accepts `true` as 1, and the validated value stays `true`), `integer|min:1` for ids in a query string or form post, which arrive as strings, `string` (with `max`) for codes, slugs and emails. Below 13.43.0 / 12.73.0 on MySQL and MariaDB, a JSON `0`, `false` or `[0]` reaches the query uncast and matches any non-numeric string, so `{"code": 0}` passes `exists:invites,code` (GHSA-wwq3-m3j5-wv66). The type rule fails it first.
- A null owner matches unowned rows. `->where('organization_id', null)` becomes `whereNull`, and the closure form `->where(fn ($q) => $q->where('organization_id', null))` also compiles to `IS NULL`. A user with no organization then passes `exists` for every row that has none. Neither form prevents it; refuse the request first:
```php
public function authorize(): bool
{
    return $this->user()->organization_id !== null;   // 403, and it runs before rules()
}
```
- The scope value comes from the authenticated user, never from input. Below the same floor, the scalar `where()` writes the value into the rule string, so a value of `NULL`, `NOT_NULL` or one starting with `!` turns into `IS NULL`, `IS NOT NULL` or `!=` (GHSA-ch34-gjmh-c4hp). When a client-supplied value must be a scope, use the closure form: it binds the value.
- The same goes for `Rule::unique(...)->where(...)` and array items (`'ids.*' => ['integer', Rule::exists(...)->where(...)]`).
- Never accept `organization_id` or `user_id` from the body; set it from the authenticated user.
- `Rule::exists()` runs a plain query: it ignores Eloquent global scopes, so a scope-based tenant filter is not applied.
- Reference tables (countries, currencies, shared catalog ids) don't need an owner filter.

## Decimal input below the patch floor

Below 13.43.0 / 12.73.0 (and on all of 11.x) the `decimal` rule's regex backtracks on a long digit run ending in an exponent (`1111…1e5`), which passes `numeric` first. One request costs seconds of CPU (GHSA-h84h-g58v-xg2h). On those versions put a length-bounded check before `decimal`, under `bail`:
```php
// bad below the floor
'amount' => ['required', 'decimal:0,2', 'min:0'],
// good on every version
'amount' => ['bail', 'required', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'decimal:0,2', 'min:0', 'max:1000000'],
```
- `max:20` is no length cap here. With `decimal`, `numeric` or `integer` in the rules, `max` compares the value, so a small number with many digits passes it. `string|max:20` measures length but rejects JSON numbers.
- Give every amount an upper bound from the domain (`max:1000000`), on any version.
- From 13.43.0 / 12.73.0 `decimal:0,2` alone is safe. Money storage and rounding: `dates-and-money.md`.

## Host header and absolute links

Applies when the app builds an absolute URL while handling a request: password reset, email verification, invitations, signed links, `url()` or `route()` in mail or API output. Laravel takes the host from the request. `POST /forgot-password` with `Host: evil.test` mails a link to `evil.test` that carries a valid reset token.
```php
// bad: bootstrap/app.php, every proxy trusted and any host accepted
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
})

// good: bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustHosts();                       // APP_URL's host and its subdomains
    $middleware->trustProxies(at: ['10.0.0.0/8']);   // your load balancer's addresses; leave out with no proxy
})
// and AppServiceProvider::boot()
if (! $this->app->isLocal()) {
    URL::forceRootUrl(config('app.url'));
    URL::forceHttps(str_starts_with(config('app.url'), 'https://'));   // 11.31+; forceRootUrl() alone keeps the request's scheme
}
```
- `trustHosts()` with no argument trusts `^(.+\.)?<APP_URL host>$`, anchored and escaped. Any other `Host` gets a 400. Extra hosts are regexes: write them anchored and escaped (`'^admin\.example\.com$'`). A bare `'example.com'` also matches `example.com.evil.test`. `trustHosts(at: [...], subdomains: false)` drops the APP_URL pattern entirely.
- `TrustHosts` does nothing in the `local` environment or when `runningUnitTests()`. `forceRootUrl` is what a test can prove (tests run outside `local`), and it pins links in every other environment, so `APP_URL` must be right there.
- Build links with `route()`, `url()` or `URL::temporarySignedRoute()`, including inside `ResetPassword::createUrlUsing()` and `VerifyEmail::createUrlUsing()`. Never from `$request->getSchemeAndHttpHost()`, `$request->root()` or a header.
- Trusted proxies: list the proxy addresses. `'*'` only when the app cannot be reached except through a proxy that overwrites the `X-Forwarded-*` headers (a cloud load balancer with unknown addresses). Otherwise a client sends `X-Forwarded-For` and `X-Forwarded-Host` itself: per-IP limiters and allow-lists key on a forged `ip()`, and the forged host goes into links.

## Blocked, suspended and placeholder accounts

Applies when a user state must stop someone signing in (blocked, suspended, deactivated, an unclaimed placeholder row). `Auth::attempt([..., 'active' => 1])` and `attemptWhen()` guard only the login form. Existing sessions, remember-me cookies, API tokens and the password broker find the user through other paths.
```php
// bad: only the login form checks
Auth::attemptWhen($credentials, fn (User $user) => $user->blocked_at === null);

// good: AppServiceProvider::boot()
Auth::provider('active-users', fn ($app, array $config) => (new EloquentUserProvider($app['hash'], $config['model']))
    ->withQuery(fn ($query) => $query->whereNull('blocked_at')));
// config/auth.php
'providers' => ['users' => ['driver' => 'active-users', 'model' => App\Models\User::class]],
```
- The provider's query runs in `retrieveById` (every request of an existing session), `retrieveByToken` (remember-me), `retrieveByCredentials` (login) and in the password broker, which builds its own provider from `config/auth.php`. So it must be a config driver: `withQuery()` on `Auth::guard()->getProvider()` does not reach the broker.
- Sanctum resolves a token's user through `tokenable`, not the provider. When you block a user, delete their tokens (`$user->tokens()->delete()`), or check the state in `Sanctum::authenticateAccessTokensUsing(fn ($token, bool $isValid) => $isValid && $token->tokenable?->blocked_at === null)`.
- `Auth::login($user)` (social login, impersonation, sign-up) skips the provider. Check the state before calling it.
- Exclude placeholder rows (invited, not yet registered) the same way, or a reset sets the password on a row someone else created.
- Not for a soft state that only limits features (unverified email, unpaid plan): that is a middleware or policy.

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
- `validated()` includes every field the rules mention: `role`, `is_admin`, `organization_id`, `status`, `price` never appear in the rules or in `$fillable`. Set them in code.
- Key/value stores (settings, preferences, meta rows) where the client names the key: the key is the column name one level down. Validate it against the user-writable keys, `'key' => ['required', Rule::enum(UserSetting::class)]` or `Rule::in([...])`. Tokens, verification codes and admin flags live in real columns or a table no user endpoint writes.
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

## User Markdown

Applies to Markdown a user writes and someone else reads. `Str::markdown()`, `Str::inlineMarkdown()` and `str()->markdown()` pass your options straight to CommonMark, whose defaults keep raw HTML (`html_input` is `allow`) and `javascript:` links (`allow_unsafe_links` is `true`). `{!! Str::markdown($reply->body) !!}` is stored XSS.
```php
// bad
{!! Str::markdown($reply->body) !!}

// good: one helper, so every call site gets the same options
final class UserMarkdown
{
    public static function toHtml(?string $text): string
    {
        return Str::markdown((string) $text, [
            'html_input' => 'escape',          // or 'strip'
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }
}
// Blade: {!! UserMarkdown::toHtml($reply->body) !!}
```
- `escape` shows tags as text; `strip` drops them. `allow_unsafe_links => false` drops `javascript:`, `vbscript:`, `file:` and `data:` URLs, except `data:image/png|gif|jpeg|webp`. `max_nesting_level` is unlimited by default.
- Use an HTML purifier on the converted output only when some raw HTML must survive.
- Not for Markdown the app itself owns (mail templates, docs pages from the repository).

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

- `whereIn('id', $request->ids)->update(...)` touches other owners' rows. Scope it: `$request->user()->projects()->whereKey($ids)`.
- `Route::resource('boards.cards')` binds `/boards/1/cards/99` even when 99 belongs to board 2. Add `->scopeBindings()` for a 404. Binding doesn't authorize.
- `DB::table()` skips Eloquent global scopes, as does `withoutGlobalScopes()`: add the owner `where` by hand in exports and reports.
- A denormalized owner column (a `user_id` copied from a parent row) goes stale when the parent changes. Authorize every endpoint, single and bulk, against the same source column, and derive the copy in a `saving` hook. That hook doesn't run when only the parent changes owner, so update the children's copies in the same transaction as the parent's change, or never read the copy for authorization.

## Tests

- Sort and direction: valid values return 200 in order; `?sort=(select 1)`, `?sort[]=x`, a hidden column and `?dir=sideways` each return 422, never 500.
- LIKE: seed `100% sure`, `1000 things`, `a_b`, `axb`; `?q=100%25` returns only `100% sure`, `?q=a_b` only `a_b`.
- Tenancy: another owner's id in the body is 422 (or 404); the owner column comes from the user even if the body sends one; a user with no owner gets 403; `{"code": 0}` and `{"id": [0]}` are 422.
- Links: `$this->post('http://evil.test/forgot-password', [...])` with `Notification::fake()`, then assert `$notification->toMail($user)->actionUrl` starts with `config('app.url').'/'`.
- Account state: after blocking, the existing session, a remember-me login, the API token and a password reset all fail to sign the user in.
- Markdown: `<img src=x onerror=alert(1)>` and `[x](javascript:alert(1))` render with no `<img` tag and no `javascript:` href.
- Decimal: below the floor, `str_repeat('1', 5000).'e5'` returns 422 quickly.
- Responses: no secret column in list or show output, with the secret seeded.
- Secrets: an empty configured secret with an empty header is rejected; assert the log file holds no password or token string after a login.
- Redirects: `https://evil.example`, `//evil.example`, `/\evil.example` and `javascript:alert(1)` land on the fallback.
- Limits: the Nth + 1 attempt is 429 with `Retry-After`; `travel()` past the window clears it.

Evidence: `Illuminate/Database/Query/Builder.php` (`whereRaw`, `orderBy`, `where`, `whereLike`), `Illuminate/Database/Grammar.php` (`wrapValue`), `Illuminate/Database/Eloquent/Concerns/{HasAttributes,GuardsAttributes}.php`, `Illuminate/Database/Eloquent/Model.php` (`forceFill`), `Illuminate/Validation/Rules/{In,Exists}.php`, `Illuminate/Encryption/{Encrypter,EncryptionServiceProvider}.php`, `Illuminate/Foundation/Exceptions/Handler.php` (`dontFlash`), `Illuminate/Routing/{UrlGenerator,Redirector}.php`, `Illuminate/Routing/Middleware/ThrottleRequests.php`, `Illuminate/Filesystem/{ServeFile,ReceiveFile}.php`, `Illuminate/Support/Str.php` (`random`, `markdown`, `inlineMarkdown`), `Illuminate/Validation/Rules/DatabaseRule.php` (`where`), `Illuminate/Validation/Concerns/ValidatesAttributes.php` (`validateDecimal`, `getSize`), `Illuminate/Validation/ValidationRuleParser.php` (regex parameters), `Illuminate/Validation/ValidatesWhenResolvedTrait.php` (authorize before rules), `Illuminate/Foundation/Configuration/Middleware.php` (`trustHosts`, `trustProxies`), `Illuminate/Http/Middleware/{TrustHosts,TrustProxies}.php`, `Illuminate/Routing/UrlGenerator.php` (`forceRootUrl`, `forceHttps`), `Illuminate/Auth/{EloquentUserProvider,CreatesUserProviders,AuthManager,SessionGuard}.php`, `Illuminate/Auth/Passwords/PasswordBrokerManager.php`, `Illuminate/Auth/Notifications/{ResetPassword,VerifyEmail}.php`, `laravel/sanctum/src/{PersonalAccessToken,Guard,Sanctum}.php`, `league/commonmark/src/Environment/Environment.php` (option defaults), `league/commonmark/src/Util/RegexHelper.php` (`isLinkPotentiallyUnsafe`); `laravel/docs/{queries,validation,eloquent-serialization,eloquent-mutators,encryption,errors,rate-limiting,fortify,requests,passwords,authentication,strings}.md`; advisories GHSA-wwq3-m3j5-wv66, GHSA-ch34-gjmh-c4hp, GHSA-h84h-g58v-xg2h (laravel/framework, fixed in 13.43.0 / 12.73.0), koel GHSA-gmx6-r3hc-5xw5 and solidtime GHSA-rf33-hmh9-h593 (host header resets), firefly-iii GHSA-3wcx-g7jc-h9vc (blocked user tokens, writable preference keys), solidtime GHSA-9xxg-8rpx-5jcj (placeholder resets) and GHSA-jj53-w7j5-jhp7 (stale owner copy), invoiceninja GHSA-xph7-9749-56mh (Markdown XSS).
