# Outbound HTTP

## One class per integration

```php
// bad: token, URL and timeouts repeated at every call site; one PendingRequest kept in a property
$this->http = Http::withToken($key);                       // later: ->withHeaders(['X-Region' => $r]) merges, never replaces
// good
class CarrierClient
{
    public function rates(string $postcode): array
    {
        return $this->client()->get('/v2/rates', ['postcode' => $postcode])->throw()->json('rates');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('services.carrier.base_url'))
            ->withToken(config('services.carrier.key'))
            ->acceptJson()
            ->timeout(config('services.carrier.timeout', 3))
            ->connectTimeout(2)
            ->retry(2, 200, fn (?Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->tooManyRequests())));
    }
}
```
- Settings live in `config/services.php` (`base_url`, `key`, `timeout`); never `env()` elsewhere. Build the `PendingRequest` inside a method on every call. Reading `config()` there also avoids a stale token in a long-lived worker (queue, Octane) and a test that sets config after the service resolved.
- `PendingRequest` is mutable. `withHeaders()` and `withQueryParameters()` merge with `array_merge_recursive`, so the same header name sent twice goes out as two values. Other `with*` calls replace, then persist into every later call on that instance. A shared instance can leak one tenant's token into another's call.
- A tiny integration (one endpoint, no retry) can be an `Http::macro('carrier', ...)` instead of a class. Once it has retries, error mapping or several endpoints, use the class.
- Don't use `file_get_contents()`, `curl_*` or a hand-built Guzzle client in app code: you lose fakes, events and `preventStrayRequests()`. Streaming a large download is still the client: `->sink($path)`.

## Check the response

- The client never throws on 4xx/5xx (`http_errors` is `false`). Call `->throw()` on every call whose body you use, or branch on `failed()`/`successful()`. Never `->json()` or cache an unchecked response.
```php
// bad: an error body is cached for an hour
Cache::remember('jwks', 3600, fn () => Http::get($uri)->json());
// good: nothing is cached when the closure throws
Cache::remember('jwks', 3600, fn () => Http::timeout(5)->get($uri)->throw()->json());
```
- `throw()` does nothing on a 3xx (`toException()` returns `null` unless the response `failed()`). An API that answers 200 with an error payload (GraphQL `errors`, JSON-RPC `error`) needs an explicit check of the body.
- Use `throwIf`, `throwUnless` or `throw(fn ($response, $e) => ...)` only when you must act before the exception leaves. Don't write `if ($response->failed()) { return []; }` where a caller needs to know.
- `ConnectionException`: no response (timeout, DNS, TLS, refused), so there is no `->response`. `RequestException`: from `throw()` or exhausted retries, has `->response`. Both extend `HttpClientException`.
- Catch the narrow type, and only where you have a fallback or a mapping (404 to 422, a cached rate). Call `report($e)` only for what a person must act on (a rejected key, a changed contract), not for transient failures a fallback already covers; give expected domain exceptions `ShouldntReport`. In a queued job let both propagate. Never wrap a call in `catch (Throwable)`: it also swallows `StrayRequestException` and `TypeError`, so a test that should fail goes green.

## Timeouts

- Defaults: 30 s total and 10 s connect, per attempt. `timeout(0)` waits forever and is never right.
- Set both on every integration. A request-path call wants about 3-5 s total and 2 s connect, with a fallback. A queued job can take longer.
- Worst case is `attempts x timeout + sleeps`. It must fit the caller's budget: the request's own limit, or `#[Timeout]` on a job (HTTP timeout < `#[Timeout]` < `retry_after`, see `queues-and-workers.md`).
```php
// bad: 2 attempts x 30 s = 60 s, above the default worker timeout of 60
Http::timeout(30)->retry(2, 200)->get($url)->throw();
// good: worst case about 40 s
Http::connectTimeout(5)->timeout(20)->retry(2, 200, fn (?Throwable $e) => $e instanceof ConnectionException
    || ($e instanceof RequestException && $e->response->serverError()))->get($url)->throw();
```

## Retries

Signature: `retry(array|int $times, Closure|int $sleepMilliseconds = 0, ?callable $when = null, bool $throw = true)`.
- `$times` is total attempts: `retry(3)` is the first try plus two retries. An array is the delays, so `retry([200, 1000, 3000])` is 4 attempts.
- With no `when`, every failed response is retried, 404, 422 and 401 included. Always pass one that retries transient failures only: `ConnectionException`, 429, 5xx (and 408).
- The sleep defaults to 0 ms. Give a number, an array or a closure.
- `when` receives `($exception, $pendingRequest, $httpMethod)`. Type the first parameter `?Throwable`: for a 3xx (not successful, not failed) it is `null`, and `fn (Throwable $e)` throws a `TypeError`. Rare, because Guzzle follows redirects, but it is the same cost to write it right.
- With more than one attempt and `throw: true`, a failed last response throws `RequestException` without `->throw()`, even if `when` returned `false` on the first failure. `throw: false` returns the last `Response`, so you must branch on it. A `ConnectionException` is thrown in both modes. With `retry(1)` or no retry nothing throws.
- Retry-After: the client does not read it. For a short wait in a synchronous call, use a sleep closure `($attempt, $exception)` and cap it:
```php
->retry(3, function (int $attempt, ?Throwable $e): int {
    $wait = $e instanceof RequestException && $e->response->tooManyRequests()
        ? $e->response->header('Retry-After') : null;

    return is_numeric($wait) ? min((int) $wait, 5) * 1000 : 200 * 2 ** $attempt;   // absent or an HTTP date: back off
}, fn (?Throwable $e) => $e instanceof ConnectionException
    || ($e instanceof RequestException && ($e->response->serverError() || $e->response->tooManyRequests())))
```
- Never sleep for a long Retry-After in a web request, and don't sleep in a job: `$this->release($seconds)` and size `#[Tries]`/`retryUntil()` for releases, or use `RateLimited` middleware to stay under the limit up front.

## Idempotency for writes

- A `ConnectionException` (timeout, reset) does not mean the server did nothing. Retrying a POST that creates something can charge, ship or email twice.
- Retry such calls only with an `Idempotency-Key` the partner honours, stable per business operation (derived from the order id, reused across attempts and job retries). Otherwise limit `when` to GET/HEAD using its third argument:
```php
->withHeaders(['Idempotency-Key' => "shipment:{$order->id}"])
->retry(3, 300, fn (?Throwable $e) => $e instanceof ConnectionException
    || ($e instanceof RequestException && $e->response->serverError()))   // safe only because the key is sent
```
- This does not apply to reads or to APIs documented as idempotent. A job that calls such an API is idempotent itself (`queues-and-workers.md`).

## Concurrent reads: `Http::pool`

```php
$responses = Http::pool(fn (Pool $pool) => [
    $pool->as('rates')->baseUrl($base)->timeout(3)->get('/rates'),
    $pool->as('stock')->baseUrl($base)->timeout(3)->get('/stock'),
], concurrency: 5);

$rates = $responses['rates'] instanceof Response && $responses['rates']->successful() ? $responses['rates']->json() : [];
```
- Each key is a `Response` or a `Throwable`. A `ConnectionException` is returned, not thrown. With `throw()` or exhausted retries, a `RequestException` is returned too. Check `instanceof Response` first, then `failed()`.
- Each `$pool->as()` starts a fresh request with factory defaults (30 s timeout). Set `baseUrl`, `withToken` and timeouts on every entry.
- Name entries with `as()`. Set `concurrency:` when the upstream rate-limits. Use a pool for independent reads that would be a sequential `foreach`, not for writes that need ordering.

## User-supplied URLs and paths

- Guzzle follows up to 5 redirects by default. For a URL a user, tenant or webhook config supplies, call `->withoutRedirecting()` and validate each hop yourself (scheme, host, resolved IP: only globally routable addresses, so no loopback `127.0.0.0/8`/`::1`, private, link-local, CGNAT or reserved ranges, checked after normalising IPv4-mapped IPv6). Checking only the first URL lets a 302 to `169.254.169.254` through. Validation and connection resolve DNS separately, so a rebinding host can pass the check and then connect inside: pin the connection to the IP you validated (Guzzle's `curl` `CURLOPT_RESOLVE` option via `withOptions()`, keeping the hostname for TLS and `Host`), or send such calls through an egress proxy that enforces the same rules. The pin holds only with the curl handler and a direct connection: `stream => true` switches Guzzle to its stream handler, which ignores curl options, and an `HTTPS_PROXY` resolves the host itself, so disable both for pinned calls (`withOptions(['proxy' => ''])`) or rely on the enforcing proxy. Cap the size of downloads (`sink()`/stream). Fixed first-party hosts need none of this.
- Path parameters: `->withUrlParameters(['org' => $org])->get('https://api.example.test/orgs/{org}')` percent-encodes `/ ? #` in the value. `"/orgs/{$org}"` does not. A bare `.` or `..` survives both and `rawurlencode()`, so validate the id format when the value is user-supplied.
- Query data goes in the second argument of `get()` or `withQueryParameters()`, not in a concatenated string.

## Secrets and logs

- Credentials go in headers (`withToken`, `withHeaders`), never in the query string. A `ConnectionException` message carries the full request URL, and `report()` sends it to logs and error trackers.
- A `RequestException` message embeds the first 120 characters of the response body (`RequestException::$truncateAt`; `truncateAt()`/`dontTruncate()` change it). Log the status, the endpoint path and a request id, not the full URL, the request headers or `$response->body()` of an API that echoes input or returns personal data.
- Cross-cutting logging is one `Http::globalRequestMiddleware()` or event listener (`ResponseReceived`, `ConnectionFailed`) with an allow-list of fields, not scattered `Log::info($response->body())`.

## Transactions, queues, pagination

- No HTTP inside `DB::transaction()`. The call holds a connection and locks for `timeout x attempts`, and a rollback after the partner succeeded leaves the two out of sync. Commit, then call, or dispatch a queued job with `->afterCommit()`. A fast, cached, read-only lookup the transaction needs is the exception. For effects that must not be lost, write an outbox row with an idempotency key in the transaction.
- Slow, optional or fan-out calls (webhooks, syncs, partner emails, anything over about 2 s or with retries) go to a job that throws on failure, with `#[Tries]`, `#[Backoff]` and `#[Timeout]`. The request path makes only the calls its response needs, with a short timeout and a fallback.
- A permanent 4xx (except 408 and 429) fails the job at once (`$this->fail($e)`). A 5xx, 408, 429 or `ConnectionException` throws or releases for retry.
- No sequential `foreach` of N calls. Use the API's bulk endpoint, `Http::pool`, or one job per item.
- Paginated reads: `throw()` every page, cap the pages, and never `break` on a failed page and return the partial list as complete. Page-number APIs advance from your own counter. Cursor APIs take the next cursor from a response you have already checked.

## Global options and OAuth tokens

- `Http::globalOptions()` assigns (a second call replaces the first). `globalRequestMiddleware()` and `globalResponseMiddleware()` append. All apply to every request in the app, packages included. Use them in `AppServiceProvider::boot()` only for cross-cutting concerns (`User-Agent`, request-id propagation, redacting logging). Never per-integration auth, base URLs or timeouts, and never `allow_redirects` or `verify` to fix one integration. `Http::withoutGlobalConfiguration(fn () => ...)` bypasses them for one call.
- Short-lived tokens: fetch once and cache until `expires_in` minus a margin. `Cache::remember` fixes its TTL before the closure runs, so fetch, then `Cache::put($key, $token, $expiresIn - 60)`, under a `Cache::lock()` so a cold start sends one token request.
- On a 401, refresh at most once and retry with the new token by changing the request in the `when` callback:
```php
->retry(2, 0, function (?Throwable $e, PendingRequest $request) {
    if (! $e instanceof RequestException || $e->response->status() !== 401) {
        return false;
    }
    $request->withToken($this->freshToken());   // forget the cached token first

    return true;
}, throw: false)
```
Never request a token per call, and never retry a 401 with the same token.

## Testing

```php
// Pest.php or TestCase::setUp()
Http::preventStrayRequests();
Sleep::fake();

it('retries a 429 once and sends the key in a header', function () {
    Http::fake([
        'carrier.test/*' => Http::sequence()
            ->pushStatus(429, ['Retry-After' => '1'])
            ->push(['rates' => []]),
    ]);

    app(CarrierClient::class)->rates('1234');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer test-key') && ! str_contains($r->url(), 'test-key'));
});
```
- When the app calls HTTP, `Http::preventStrayRequests()` once for the whole suite (`tests/Pest.php` or the base `TestCase`, not per file; an app that calls nothing needs none), then fake every host a test touches. Without it an unmatched URL makes a real call. A stray throws `StrayRequestException`, so the code under test must not catch `Throwable`. `Http::allowStrayRequests(['http://127.0.0.1:*'])` takes `Str::is` patterns for deliberate exceptions.
- The first stub whose pattern matches wins, across all `fake()` calls: specific URLs before `'*'`, and a broad `fake()` in `setUp` cannot be overridden by a later one for the same URL. Every `fake()` call resets the recorded requests.
- Failures: `Http::sequence()->push(...)->pushStatus(503)->pushFailedConnection()`, `Http::failedConnection()` for a `ConnectionException`, `Http::response(status: 503)`. An exhausted sequence throws `OutOfBoundsException` unless `whenEmpty()` or `dontFailWhenEmpty()` is set.
- `Http::assertSentCount(n)` proves what was retried (every attempt is one record). `assertNothingSent()` and `assertSentCount(0)` mean something only after `fake()` or `Http::record()`. Use `assertSent` for URL, method, auth header and body, `assertNotSent` for "must not call".
- Retries sleep for real: `Sleep::fake()`, then `Sleep::assertSlept(fn ($d) => $d->totalSeconds > 5, 0)` to prove a long Retry-After is capped.
- A fake closure gets the Guzzle options as its second argument: `Http::fake(function (Request $request, array $options) use (&$seen) { $seen = $options; return Http::response(); })`, then assert `$seen['timeout']` and `$seen['connect_timeout']`. The same closure can record `DB::transactionLevel()` to prove no call runs inside a transaction (an SQLite test shows no lock wait).
- Test your client class at the HTTP layer. Test its consumers with a bound fake of the client class, not `Http::fake()`.

Evidence: `Illuminate/Http/Client/{PendingRequest,Factory,Response,Request,RequestException,ConnectionException,ResponseSequence,Pool,Batch}.php`, `Support/{helpers,Sleep}.php`; `laravel/docs/http-client.md`; laravel/laravel `config/services.php`.
