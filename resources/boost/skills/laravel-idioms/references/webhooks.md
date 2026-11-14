# Inbound webhooks

Applies to a receiver for a provider that signs and retries deliveries. Not for webhooks the app sends (`http-client.md`), and not for ordinary authenticated API endpoints. The examples use a made-up provider `Example` with header `X-Example-Signature` (hex HMAC-SHA256 of the raw body).

## Verify the signature

```php
// bad: re-encoding changes key order, "\/" escaping, unicode escapes, number formats
$expected = hash_hmac('sha256', json_encode($request->all()), $secret);
// good: the raw bytes, a constant-time compare, every configured secret
class VerifyExampleSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) $request->header('X-Example-Signature', '');
        $secrets = array_filter((array) config('services.example.webhook_secrets'), fn ($s) => is_string($s) && $s !== '');

        $valid = $given !== '' && collect($secrets)->contains(
            fn (string $secret) => hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $given)
        );
        abort_unless($valid, 401);

        return $next($request);
    }
}
```
- Sign `$request->getContent()`. A test that signs the same re-encoded array passes, and real payloads fail.
- Use the provider's exact encoding: hex (`hash_hmac(...)`) or base64 of the raw digest (`base64_encode(hash_hmac(..., true))`). Mixing them up is the common bug. Take it from the provider docs, never guess.
- Compare with `hash_equals($known, $given)`, both strings (cast the header: a null throws `TypeError`, a 500). Never `==`, `===` or `!==`.
- Fail closed: a missing or empty header, or an empty or missing configured secret, is a 401. `hash_hmac` with an empty key still returns a digest anyone can compute. Never default a secret to `''` or a placeholder string, and never `env()` outside config.
- Rotation: `config('services.example.webhook_secrets')` is a list (`[current, previous]`); accept any, drop the old one after the provider's cutover.
- Verify in middleware, before parsing, validation and the controller. Answer 401 for a bad or missing signature, with a body that doesn't say which check failed. A FormRequest `authorize()` runs later and answers 403.
- The signature is the authentication. A provider that sends only a static bearer token or Basic credentials takes the same `hash_equals` in the same middleware slot; there is no body HMAC.
- A receiver for several tenants: read the tenant from a header, look up that tenant's secret, and answer 401 (not 404) for an unknown one, so the endpoint doesn't list tenants.
- Don't use URL signing (`signed` middleware, `ValidateSignature`): it is for links the app generates, not for provider deliveries.
- If the project already uses a provider SDK's verifier correctly, keep it and add only what is missing (fail-closed secret, unknown types, queueing, dedupe). Spatie's `laravel-webhook-client` is an option when the project wants its stored calls and replay tooling; don't add it unprompted, and don't hand-roll over an installed one.

## Timestamped signatures and replay windows

Some providers sign `"{timestamp}.{rawBody}"` and send the timestamp in the header. Then:
- Parse the timestamp from the header, recompute the HMAC over `"{$t}.{$raw}"`, `hash_equals` it, and reject if `abs(time() - $t) > $tolerance` (a few minutes; pin the number from the provider docs).
- A missing or non-numeric timestamp is a 401, before any HMAC work.
```php
$t = (string) $request->header('X-Example-Timestamp', '');
abort_unless(ctype_digit($t) && abs(time() - (int) $t) <= config('services.example.tolerance', 300), 401);

$expected = hash_hmac('sha256', $t.'.'.$request->getContent(), $secret);
```
- A valid signature inside the window can still be replayed within it: the delivery-id dedupe below is what stops that.
- A header may carry several signatures during a secret rotation: accept the delivery if any one matches.

## Routing

- Put the route in `routes/api.php` (no session, cookies or CSRF) without `auth` or `auth:sanctum`, or in a dedicated routes file registered in `withRouting()`. An `auth` middleware makes a signed delivery a 401 or a 500 (`Route [login] not defined`).
- A fresh Laravel 13 skeleton has no `routes/api.php`. `php artisan install:api` creates it and also installs Sanctum, which a webhook-only app doesn't need: add the file and `api:` in `withRouting()` by hand.
- A route that must stay in the `web` group is excluded from CSRF: Laravel 13 `$middleware->preventRequestForgery(except: ['webhooks/*'])`; Laravel 11-12 `$middleware->validateCsrfTokens(except: [...])` (deprecated on 13). A route already in `api` needs no exclusion; don't add a dead one.
- Never throttle a receiver like a user API: providers send bursts from few addresses and retry a 429, and may disable the subscription. No `throttle`, or `->withoutMiddleware('throttle:api')` if it is applied globally.

## Acknowledge fast, then queue

```php
// good: store the verified raw body, dispatch, answer 2xx with no body
$event = WebhookEvent::query()->createOrFirst(
    ['provider' => 'example', 'event_id' => $request->header('X-Example-Event-Id')],
    ['type' => $request->header('X-Example-Type'), 'payload' => $request->getContent()],
);
if ($event->wasRecentlyCreated || $event->processed_at === null) {
    ProcessWebhookEvent::dispatch($event->id);   // ->afterCommit() only when this runs inside a transaction
}

return response()->noContent();
```
- Inline API calls, mail or a long transaction run past the provider's timeout; the provider retries while the first run is still going, so you get duplicates and parallel runs.
- Store `getContent()` verbatim in a text column and decode it in the job (`json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR)`). No `array` cast on that column: it would encode the string a second time. The job takes the row id, not the payload.
- Validate only what every delivery carries (delivery id, type) before answering. An unknown type is acknowledged before its payload is checked; a known type's fields are checked where it is processed, and never more strictly than the provider's contract, because a rejected delivery comes back for as long as the provider retries.
- Answer 202 or 204 with an empty body; the provider reads the status only. Don't return the processing result.
- A naturally idempotent single write (delete a session row, set a flag) needs no inbox: verify, do it inline, answer 200. The inbox is for slow, multi-step or non-idempotent work.

## Idempotency

Delivery is at-least-once: retries after a timeout or 5xx are normal.
- Table `webhook_events` with `unique(['provider', 'event_id'])`. Use the provider's delivery id, stable across redeliveries, and only one the signature covers (an `id` in the signed body, or a header the provider includes in the signed string). An unsigned id header lets anyone replay a captured delivery under a new id.
- Claim with `createOrFirst()` + `wasRecentlyCreated`, or `insertOrIgnore([...]) === 1`. `insertOrIgnore` is a query-builder call: no timestamps, casts or events, so set `created_at` yourself or use `fillAndInsertOrIgnore` on the model builder.
- A duplicate answers the same 2xx, never 409 or 422: the provider must stop retrying.
- Never `Cache::has()` then `put()` (races, marks before the work succeeded). `Cache::add()` is atomic but not durable: a cache flush means duplicates. Use the table for orders, stock, money, deletions. A cache key is fine only for loss-tolerant notifications.
- The unique row protects intake, not processing. If the insert committed but the dispatch was lost, a redelivery hits the duplicate branch and nothing runs. Set `processed_at` at the end of the job; on a duplicate with `processed_at` null, re-dispatch; or sweep rows with `processed_at IS NULL` older than N minutes with a scheduled command. A processed duplicate does nothing.
- A re-dispatch can overlap a job that is still running. Unless the effects are idempotent, the job claims the row first with one conditional update (`whereKey($id)->whereNull('processed_at')->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(10)))->update(['claimed_at' => now()]) === 1`) and returns when the claim fails. The stale-claim window lets a crashed worker's row be picked up again.

## Ordering and unknown types

- Deliveries are not ordered. For payloads that carry state, update only if newer, as one statement:
```php
// bad: last delivery wins
Order::updateOrCreate(['remote_id' => $id], ['status' => $s, 'remote_updated_at' => $at]);
// good
$newer = fn ($q) => $q->whereNull('remote_updated_at')->orWhere('remote_updated_at', '<', $at);
$n = Order::where('remote_id', $id)->where($newer)->update(['status' => $s, 'remote_updated_at' => $at]);
if ($n === 0 && ! Order::where('remote_id', $id)->exists() && Order::query()->insertOrIgnore([...]) === 0) {
    // a concurrent delivery inserted first: apply the same guarded update again
    Order::where('remote_id', $id)->where($newer)->update(['status' => $s, 'remote_updated_at' => $at]);
}
```
- Compare on the payload's own timestamp, not arrival time. Use `<=` only if re-applying is harmless.
- A delete arriving before the create: write a tombstone (`remote_deleted_at`) and make an older create or update a no-op.
- A thin payload: treat the event as a signal and refetch the object.
- Event streams of deltas (stock movements, ledger entries) are deduped by id, not ordered: a "newer only" guard would drop real events.
- A type you didn't subscribe to or don't handle: answer 2xx, log at info, ignore. A 4xx or 5xx makes the provider retry and may disable the subscription. An expected type whose payload is invalid still fails loudly. Map type to job with `match`, `default => null`.
- Redaction or deletion events go through the same verified, queued path; deleting an already deleted row is success, not `firstOrFail`.

## Tests

- `postJson()` re-encodes its array with `json_encode`, so a signature over your own string doesn't match. Send exact bytes through `call()`, whose seventh argument is the raw body; headers are server vars (`HTTP_X_EXAMPLE_SIGNATURE`, unprefixed `CONTENT_TYPE`):
```php
$raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sig = hash_hmac('sha256', $raw, config('services.example.webhook_secrets')[0]);

$this->call('POST', '/api/webhooks/example', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_EXAMPLE_SIGNATURE' => $sig,
    'HTTP_X_EXAMPLE_EVENT_ID' => 'evt-1',
    'HTTP_X_EXAMPLE_TYPE' => 'order.updated',
], $raw);
```
- Cases: valid (2xx, one row, `Queue::assertPushed` once); tampered by one byte (401, nothing stored or queued); missing header (401, not 500); empty configured secret (401); previous secret accepted; stale timestamp; duplicate id (2xx twice, one row, one job); redelivery after processing (no second effect); redelivery with `processed_at` null (processed once); older-after-newer (newer value stays); unknown type (2xx, no job). Sign a body that no re-encoding reproduces: pretty-printed, with a string that has surrounding spaces (the global `TrimStrings` middleware changes the input bag), an empty string (becomes `null`) and a float written `10.50`. A compact body with `/` and non-ASCII is reproduced by `json_encode` with the right flags, so it proves nothing.
- A 419 can't appear under the test runner: CSRF returns early there. To prove the route is exempt, rebind it so it runs, then post without a token:
```php
$this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
    protected function runningUnitTests(): bool { return false; }
});
```

Evidence: `Illuminate/Http/Request.php`, `Illuminate/Foundation/Configuration/Middleware.php` (`preventRequestForgery`, `validateCsrfTokens`, `throttleApi`), `Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php`, `Illuminate/Database/Query/Builder.php` (`insertOrIgnore`, `update`), `Illuminate/Database/Eloquent/Builder.php` (`createOrFirst`, `fillAndInsertOrIgnore`), `Illuminate/Foundation/Testing/Concerns/MakesHttpRequests.php` (`call`, `postJson`), `Illuminate/Routing/Middleware/ThrottleRequests.php`; `laravel/docs/routing.md`, `laravel/docs/csrf.md`, `laravel/docs/queues.md`.
