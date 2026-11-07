# Plan: races, API auth, files, schema, HTTP client and events (round 6)

Goal: close six gaps that the queue round's judges flagged in every arm, and prove the change with the same evidence bar as that round: hidden behavioural tests first, blind Opus judges second, holdout and regression tasks to catch over-fitting and dilution.

Gaps, in priority order (1–3 were real bugs in every arm of the queue round's builds):
1. Concurrency and races
2. API authentication and error responses
3. Untrusted files and uploads
4. Migrations and schema
5. Outbound HTTP integrations
6. Events, listeners, observers, notifications and mail

## What the research found

Six Sonnet researchers worked on one topic each against laravel/framework 13.40, the 13.x docs, Sanctum 4.x and the 13.x skeleton. Each also surveyed the production Laravel apps we maintain, read-only, for recurring bugs. A second Sonnet checked every rule and correction against the source. That left 140 rules (none rejected, 62 corrected), 22 corrections to the current text and 22 task ideas.

| Area | Current state | High-priority misses |
|---|---|---|
| Races | `Cache::has`+`put`, locks, one line on conditional updates in §7 | unique index is the only real guard (`firstOrCreate` without one is check-then-insert; a nullable column in the key lets duplicates through); decide the winner from the DB result (`createOrFirst()->wasRecentlyCreated`, `insertOrIgnore() === 1`, `where(old)->update() === 1`) before side effects; guards in the `UPDATE`'s `WHERE` for stock/quotas; `$model->increment()` leaves a stale attribute; `lockForUpdate()` only inside a transaction and only on SQLite as a no-op; `DB::transaction(..., attempts)` re-runs the closure; `unique` validation is a pre-check; `Cache::add` is atomic only with a TTL; `forget()` vs an in-flight recompute |
| API auth | one line on `Sanctum::actingAs` | guest without `Accept: application/json` → redirect to the missing `login` route → 500; `shouldRenderJsonWhen` fixes the handler but not the redirect (`redirectGuestsTo`); `getJson()` tests can never see it; validation without `Accept` → 302; `auth` vs `auth:sanctum`; `throttle:api` needs a limiter named `api`; `Sanctum::actingAs` grants no abilities; `$request->user()` uses the default guard on public routes; framework error shapes, no `catch → json(['error' => $e->getMessage()])`; 401/403/404/422 semantics; `ModelNotFoundException` message names the model class |
| Files | one line ("rows are hostile") | `mimes:csv` fails real CSVs; never store under the client's filename; type allow-list + size cap; private disk by default; temporary signed routes (a plain signed route never expires); BOM, header normalisation, column count, blank lines, line numbers; close handles in `finally`; store before queueing; `Storage::fake('<disk>')` fakes one disk; CSV formula injection on export |
| Migrations | nothing (Boost covers it when installed) | modifiers before `constrained()`; choose `ON DELETE` explicitly; `->change()` restates every attribute; required column on a full table = add nullable → backfill → tighten; never rename/drop in the same deploy as the code; backfill with `DB::table()->chunkById()`, not models; never edit a merged migration; unique index as the invariant; index what you filter and sort, equality columns first; `timestamps()` are nullable for raw inserts |
| HTTP client | `Http::retry` and fakes in caching/queues refs | the client never throws on 4xx/5xx; `timeout`/`connectTimeout` per attempt (defaults 30 s / 10 s); `retry($times)` counts attempts and by default retries 4xx too, so pass `when`; retry throws by itself; don't retry non-idempotent POSTs without an idempotency key; `preventStrayRequests()` suite-wide; fake order (first match wins); no HTTP inside a transaction; one client class per integration, fresh `PendingRequest` per call; `Http::pool` returns exceptions |
| Events | observer + `ShouldHandleEventsAfterCommit`; queued listener after-commit line | model events don't fire on mass writes (`where()->update()`, `insert`, `upsert`, builder `increment`); before-events always run inside the transaction; `notifications` table not in the skeleton; a "sent" marker next to a queued send records "queued"; `Mail::to($many)` is one message to everyone; `Event::fake()` stops observers; mail/notification fakes never render; register each listener once; event only for 2+ independent effects |

Corrections (all source-verified):
- `caching.md`: `RateLimiter::attempt()` is check-then-act; after-commit invalidation doesn't stop an in-flight recompute; `add()` is atomic only with a TTL; `flexible()`'s refresh lock defaults to 0 seconds.
- `eloquent.md`: `createOrFirst` needs the unique index and only the key columns as attributes; the empty-`uniqueBy` exception applies to every driver.
- SKILL.md §5 "derived fields go in model events … every write path": false for mass writes.
- SKILL.md §11 "listeners are auto-discovered": only with `Application::configure()`, and only `handle*`/`__invoke` with a class-typed first parameter.
- SKILL.md §7 and `queues-and-workers.md`: `updateOrCreate` makes a row idempotent but not an accumulating write.
- `queues-and-workers.md:62`: `retry(2)` + 30 s timeout exceeds the worker timeout it sits under.
- `architecture-and-testing.md`: `Sanctum::actingAs` without abilities, guest tests only through `getJson`, `Storage::fake()` fakes only the default disk, blanket `Event::fake()`.
- `audit-mode.md`: the HTTP timeout row (there is always a 30 s default).

## Skill changes

Builders follow rules that sit in SKILL.md with a bad→good snippet and a checklist line, and mostly ignore rules that live only in references. Snippets get copied literally, so every snippet uses neutral names and no environment-specific values.

1. **SKILL.md** (308 lines now; it landed at 389, see the review below)
   - **§3b API routes, auth and errors** (new, snippet): `bootstrap/app.php` with `redirectGuestsTo` returning null for `api/*` and `shouldRenderJsonWhen`; `auth:sanctum`; framework error shapes; guest tests without `Accept`.
   - **§4b Schema and migrations** (new, bullets): FK chain order and explicit `ON DELETE`; unique index as the invariant (no nullable key column); indexes for filters/sorts; expand → backfill → contract, never rename/drop in the deploy that changes the code; backfill with `DB::table()->chunkById()`; `->change()` restates every attribute; never edit a shipped migration.
   - **§5** fix "every write path" (mass writes skip model events).
   - **§7d Files and uploads** (new, bullets): validation with a type allow-list + size, `store()` on a private disk, temporary signed downloads; the CSV reader bullet (BOM, headers, counts, line numbers, `finally`) replaces §7's "hostile rows" bullet.
   - **§8b Races and atomic writes** (new, snippet): claim-then-side-effect with `createOrFirst`/conditional `update`/`decrement` in the `WHERE`; `lockForUpdate` inside a transaction; closure `DB::transaction`; `unique` rule is a pre-check; `add()` needs a TTL; forget vs recompute.
   - **§8c Outbound HTTP** (new, snippet): one client class, `timeout`/`connectTimeout`, `retry` with `when` for transient failures, `throw()`, no HTTP inside transactions, `preventStrayRequests()`.
   - **Events**: one bullet in §12 (when an event earns its place) and one in §7 (`Mail::to($many)`, sent markers, `notifications` table).
   - **§13 Tests**: guest requests without `Accept`; `Sanctum::actingAs($u, [abilities])`; `Storage::fake('<disk>')`; partial `Event::fake([X::class])`; render mail once; a race simulated by a one-shot hook.
   - **Checklist**: new **Races**, **API**, **Files**, **Schema**, **HTTP client**, **Events/mail** lines, each with a grep.
   - **References table**: the new files below.
   - Description: add "migrations, API auth, file uploads, HTTP clients" only if the trigger eval shows a need; keep valid YAML.
2. **New references**: `concurrency.md`, `files-and-uploads.md`, `migrations.md`, `http-client.md`, `events-and-notifications.md`. `http-layer.md` gets an **API auth and errors** section. Each API is cited with an evidence path.
3. Apply the corrections above to `caching.md`, `eloquent.md`, `queues-and-workers.md`, `architecture-and-testing.md`, `audit-mode.md` (new audit rows for races, API, files, schema, HTTP, events).

## Eval changes

1. **`setup.sh`**: new bases `base-api` (Pest) and `base-api-phpunit` with `php artisan install:api` already run and `HasApiTokens` on `User`, so every arm starts from the same Sanctum install and no build spends its time on it; `base-orders-schema` (`base-api-phpunit` + `fixtures/orders-schema/`), a seeded app with a legacy `orders` table.
2. **`tasks.json`**: new tasks with pinned interfaces, a behavioural `focus` (no API names) and hidden PHPUnit tests under `eval/hidden/`:

   | id | Gap | Behaviour the hidden tests check | Set |
   |---|---|---|---|
   | `coupon-redeem` | races | repeat by one user → 201 then 200, one row, one event; cap reached → 409 and the count never exceeds the cap, also when a competing redemption lands between the read and the write (one-shot `retrieved` hook); the DB rejects a duplicate (coupon, user); guest without `Accept` → 401 | target |
   | `orders-api` | API auth | guest with and without `Accept` → 401 JSON; read-only token can't write (403); other users' orders hidden; invalid body without `Accept` → 422 JSON, not 302; with debug off, a missing order's 404 doesn't name the model class | target |
   | `stock-csv-import` | files | BOM + `SKU , Quantity` headers, CRLF, reordered columns all import; ragged and invalid rows reported with their line number; blank lines ignored; non-CSV and oversized uploads → 422; re-import updates, no duplicates | target |
   | `document-vault` | files | stored name is never the client's name; file on the private disk only; disallowed types → 422; link works without auth, expires after 10 minutes, rejects tampering; other users' documents → 403/404; missing file → 404, not 500 | target |
   | `orders-customer-link` | migrations | seeded legacy app: new nullable `customer_id` FK, `customer_email` kept (expand-only release), deleting a customer keeps orders detached, backfill command links mixed-case/space-padded emails, is idempotent and leaves `updated_at` alone, an index leads with `customer_id`, `PlaceOrder` writes both columns | target |
   | `carrier-quote` | HTTP client | bearer token and postcode sent; 404 → 422 after one request; 500, 500, 200 → real rates; persistent 5xx or connection failure → flat-rate fallback marked estimated after ≤ 3 requests; 401 not retried; per-request timeout ≤ 5 s | target |
   | `ship-order` | events/mail | one shipment row, one mail to the customer, points awarded once; shipping again → 409 with no second mail; a rollback around `Order::ship()` sends nothing; non-staff 403; the mail renders the tracking number | target |
   | `token-login` | API auth | token issued and usable; wrong credentials 401 with the message; 6th attempt in a minute 429, even with the right password; limit per email + IP; revoking deletes only the token used | holdout |
   | `thread-mentions` | events + races | one mail per mentioned user with one `To` each, case-insensitive, author and unknown names skipped; `reply_count` incremented once; database notifications stored | holdout |
   | `profile-nickname` | over-application | a nullable column and `PATCH /api/me`; judged on simplicity (no locks, idempotency keys or retries) | probe |
   | 13 earlier tasks: sales-report, blog-crud, csv-import, blade-catalogue, caching and the queue round's eight | — | their own hidden tests where they have them | regression |

3. Hidden tests use the array mail transport, `Http::fake` with captured options, real temp files (so content sniffing runs), real disks under the build's own storage, and one-shot model hooks, so they check behaviour, not the APIs a build chose. Every API task also checks a guest request without `Accept`, the bug the previous round's judges found in every arm; `aggregate.py` reports that test as its own row and the pass rate without it.
4. **Reference gate** (before any build): Sonnet built two correct designs per task from the brief alone (pessimistic lock vs conditional `UPDATE`, signed route vs `temporaryUrl()`, `retry()` vs a manual loop, and so on), 19 builds in all. Every hidden test must pass every correct design. Three tests failed a correct design and were fixed: an `original_name` assertion that kept `../` (Symfony strips it), a disk reset that dropped `serveUsing()` set in `boot()`, and a profile test that rejected a `{data: …}` wrapper. All 19 then passed. Every reference build first returned the guest 500 itself, which is what the check is for.
5. **`static.py`**: greps for the new misses (`getMessage()` in a response, `Http::` without `timeout()`, `retry()` without `when`, client file names stored, `mimes:csv` without `txt`, `Mail::to($users)`, models in migrations, `beginTransaction()`, `signedRoute()`) and for over-application on the probe and regressions (`lockForUpdate`, `Cache::lock`, `Idempotency-Key`).
6. **Trigger prompts**: +5 positives (guest 500, a migration on a big table, uploads, a hanging HTTP call, a ticket-claim race) and +3 negatives (Django migrations, an Express upload, a curl question).

## Test protocol

- **Arms:** `plain`, `prev` (skill at `main`) and `skill`, headless `claude -p --model sonnet`, the skill in the project's `.claude/skills/`, a prompt that never mentions it.
- **Builds:** targets + holdout (9 tasks): 3 × `skill`, 3 × `prev`, 1 × `plain`. Probe: 2 × `skill`, 2 × `prev`, 1 × `plain`. Regression (13 tasks): 2 × `skill`, 2 × `prev`. 119 builds. A build that can't run at all (setup failure, a killed session) is rebuilt once and recorded.
- **Judging:** 2 blind sandboxed Opus judges per task over replicates 1 and 2.
- **Pass bar (all must hold):**
  1. targets + holdout, **without the shared guest test**: hidden-test pass rate `skill` ≥ `prev` in every gap area and > `prev` in at least four of the six, pooled over replicates; no task worse by more than one replicate's worth (one build's tests for that task);
  2. judge bugs per build `skill` ≤ `prev`, and correctness not lower, on targets and holdout;
  3. regression and probe: mean overall `skill` ≥ `prev` − 0.3 in both replicates, regression hidden tests not lower, probe simplicity ≥ `prev`;
  4. the skill fired in every `skill`/`prev` build;
  5. triggering holds on the existing 22 prompts (Haiku, 3 runs);
  6. audit traps no worse than `main`.
- **Revise loop (at most two):** revise from target-task misses only, each miss confirmed with grep across every build; rebuild `skill` on the targets the revision touches, the holdout and the probe (about 30 builds), and judge fresh against the same `prev` builds.

## Review (Opus) and what changed

An Opus reviewer read the plan, the skill, the research and the harness, and checked the key claims against 13.40, Sanctum and the skeleton. Adopted:
- **Three hidden-test designs would have failed correct builds.** The `retrieved` hook fired inside a locked read (SQLite has no locks) and inside the app's own transaction, so it now fires only at the test's baseline transaction level, and the tests assert invariants. They don't demand a specific status when the hook never fired. `Storage::fake()` replaces `temporaryUrl()` with an unsigned URL, so the vault tests use the real disks. Sanctum caches the user across requests, so guest and token cases sit in separate methods or call `forgetGuards()`.
- **One snippet could carry the pass bar.** The guest check runs in eight tasks. It is reported on its own row, and the bar counts the rest.
- **Reference gate** before any build, as above.
- **Pinned interfaces**: ability names and 404 for other users' orders; the error shape and line numbering for the CSV import; "at most 3 calls" and the fallback JSON for the carrier; `Order::ship()` arranging the mail and points; the backfill command, MySQL and the unnormalised emails for the schema task.
- **Checks every arm passes were dropped** (validation without `Accept` already renders JSON in the 13 skeleton; `store()` already defaults to the private disk) and replaced with ones that discriminate: a PHP file named `.png`, a header-only CSV that sniffs as `text/plain`, a session cookie on an API response.
- **Holdout swap.** `stock-reserve` was the coupon task in another shape. `token-login` tests the API area instead.
- **Regression trim.** filament-admin and cart-discounts are dropped: nothing in this change touches them.
- **SKILL.md growth.** +60 lines was unrealistic for six areas. Three snippets (API guests, races, HTTP client) sit in SKILL.md. Migrations, files and events are bullets only, and the deeper rules are in five new references. §7, §7b and §7c were not rewritten, only extended, and each edited rule was diffed against `main`.
- **Rule fixes:** a unique index guards one-per-X rows, and guarded `UPDATE`s and locks guard the rest; `mimes:csv` passes comma CSVs on current PHP but rejects header-only, single-column and semicolon files; a lock is a no-op on SQLite, not only on SQLite; after `$model->increment()` the model holds the loaded value plus n.
- **Conditions against over-application:** locks and claims only for contended counters, caps and one-per-X rows; idempotency keys only for retried POSTs; expand/contract only for tables with rows and running code; private disks and signed links not for public assets; "an event only for two or more effects" never removes an event the brief asks for.

## Cost and time

About 119 builds at $0.40–2.70 (~$150), about 92 judgements (~$80), up to two revisions of about 30 builds each (~$60), trigger and audit runs (~$5). About 6–8 hours wall clock, most of it builds.

## Deliverables

A PR on `feat/races-api-files-schema-http-events` with the skill changes, the harness changes and a `docs/evaluation.md` section. The raw results go in a stacked PR if the main PR would pass 100 files. No release tag until the user decides.
