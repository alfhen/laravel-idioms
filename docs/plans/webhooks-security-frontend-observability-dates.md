# Plan: webhooks, security, Livewire and Inertia, observability, dates and money (round 7)

Goal: close five gaps found in a review of `main` (v0.3.0), with the same evidence bar as the queue and races rounds: hidden behavioural tests first, blind Opus judges second, holdout and regression tasks to catch over-fitting and dilution.

Gaps, in priority order:
1. Inbound webhooks, for any provider
2. Security baseline
3. Livewire (without Filament) and Inertia
4. Observability and error reporting
5. Dates and money

## What the research found

Six Sonnet researchers worked on one area each against laravel/framework 13.42, the 13.x docs and skeleton, Livewire 4.4.8 (and its docs and starter kit), inertia-laravel 3.7.0 (and its docs and the React starter kit), Pulse 1.10 and Nightwatch 1.30. Where a claim was cheap to run, they ran it against those versions. Each also surveyed the production Laravel apps we maintain, read-only. Their findings in those apps are follow-ups for those repositories, not skill content.

| Area | Current state | High-priority misses |
|---|---|---|
| Webhooks | three passing mentions | HMAC over `getContent()` (re-encoding the parsed JSON changes the bytes), `hash_equals`, fail closed on a missing header or empty secret, verify in middleware before parsing, 401; route outside the `web` group (on 13 the exclusion API is `preventRequestForgery(except:)`, and a test can never see a 419); store the raw event, queue, answer 2xx fast; dedupe on the delivery id with a unique index, duplicate → 2xx; apply state only if newer (conditional `UPDATE`); unknown types → 2xx and ignore; test with `call()` and the exact signed bytes (`postJson` re-encodes) |
| Security | `{!! !!}`, `validated()`, policies, API status codes, login limiter | request data never in a raw SQL string (bind with `?`); column names and sort direction are never bound: allow-list them (`orderBy($request->sort)` is quoted on 13, so the failure is a 500 and a hidden-column oracle, not injection); LIKE needs `%`/`_` escaped with an explicit `ESCAPE` (`whereLike` doesn't); `exists:` is not tenancy, scope it; secret columns `#[Hidden]`, `encrypted` only protects at rest; verify-only tokens stored as a hash; no `$request->all()` in logs, `#[\SensitiveParameter]`; `hash_equals` for secrets; unnamed `throttle:N,M` shares one counter across routes; open redirects |
| Livewire | only through Filament | every public property is browser-writable: `#[Locked]` ids or a typed model property (Livewire ignores a tampered model key); every public method and `#[On]` listener is an endpoint, so authorize in each action, not only `mount()`; only a few route middleware re-run on `/livewire/update`, and `Livewire::test()` skips middleware; `validate()` before every write (`#[Validate]` keeps the invalid value and runs only on updated properties, `rules()` never on update); query in `#[Computed]`, never Eloquent collections in public properties (they lose eager loads: 8 queries vs 2 measured); `cache: true` is shared across users; uploads validated, stored under a generated name |
| Inertia | one line about the `web` group | every prop and shared prop is public JSON in the page: never a model, relation graph or bare paginator; `auth.user` as `only(...)`, not the model; a plain value in `share()` or the props array runs on every visit and every partial reload, only closures/`optional`/`defer` are lazy; a JsonResource prop is wrapped in `data`; validation errors come back as a redirect with session errors, never a 422; 303 after PUT/PATCH/DELETE comes from the adapter, only with `X-Inertia`; `assertInertia()->component()` checks the page file unless `testing.ensure_pages_exist` is off |
| Observability | `Context::`, `report()` in loops, `ShouldntReport` once | pass the exception object (`report($e)` or `['exception' => $e]`), never `getMessage()` alone; report and continue, or rethrow, never both; `catch (\Exception)` misses `TypeError`; expected domain exceptions `ShouldntReport`; `$exceptions->level()`, `throttle()`, `dontFlash()`; `logger()` is debug level; constant messages with id-only context; containers log to stderr as JSON; `/up` only proves boot (it answers 500) unless a `DiagnosingHealth` listener checks the app's own stores; Pulse and Nightwatch capture through `report()`, so `dontReport`/throttle hide exceptions there too; gate `/pulse`, keep emails out with `Pulse::user()`/`Nightwatch::user()` |
| Dates and money | `Number::currency($cents / 100)` | money as integer minor units or a decimal string, never a float or `(int) ($price * 100)` (19.99 → 1998); round once at a named point; split a total by largest remainder; the minor-unit exponent depends on the currency (JPY 0, KWD 3); `Number::currency` takes major units, display only; parse money input with string math; store UTC, parse user input in the user's zone and `->utc()`; a user's "today" is a half-open UTC range, not `whereDate` (which also defeats the index); `addMonth()` overflows (Jan 31 → Mar 3); default Carbon is mutable (`Date::use(CarbonImmutable::class)` in new apps; a model's own cast attribute is not the hazard, a shared local or parameter is); `diffInDays` is a signed float |

Corrections to the current text (all source-verified):
- `SKILL.md` §6 and checklist, `collections-and-helpers.md`: `Number::currency($cents / 100)` is wrong for currencies without two minor digits.
- `pest.md`: compares money as `(float)`.
- `modern-laravel.md`: `health: '/up'` with no caveat; `dontReport` as the only example (prefer `ShouldntReport` on owned classes).
- `queues-and-workers.md` / `SKILL.md` §7: the `Log::error($e->getMessage())` bad example should also say it drops the trace.
- `http-client.md`: "never `catch (Throwable)`" reads as contradicting `console-commands.md`; reword to "never a `catch (Throwable)` that swallows".
- `http-layer.md`: name `preventRequestForgery(except:)` (13) and say `validateCsrfTokens` is deprecated; `install:api` also installs Sanctum.
- `concurrency.md`: `insertOrIgnore()` sets no timestamps; `Cache::add` is atomic but not durable, so not a dedupe store for irreversible effects.

## Skill changes

Builders follow rules that sit in SKILL.md with a bad→good snippet and a checklist line, and mostly ignore rules that live only in references. Snippets get copied literally, so every snippet uses neutral names (a made-up provider, `X-Acme-Signature`), no provider-specific headers, and no environment-specific values.

1. **SKILL.md** (394 lines now; budget about +70)
   - **§3c Inbound webhooks** (new, snippet): middleware that verifies `hash_equals` over `getContent()` and fails closed; controller that `createOrFirst`s the delivery and dispatches only when it won, answers 2xx; bullets for route placement, unknown types, newer-only updates, signed raw-body tests. Provider-neutral.
   - **§2** one bullet: request-derived column names and sort directions are allow-listed (`Rule::in`); LIKE terms escaped; `exists`/`unique` scoped to the owner for tenant data.
   - **§5** one bullet: secret columns `#[Hidden]`; `encrypted` only at rest; verify-only tokens hashed.
   - **§9b Livewire** (new, short snippet): `#[Locked]`/typed model, authorize in every action, `validate()` before write, `#[Computed]` with eager loading; one test bullet.
   - **§9c Inertia** (new, bullets): explicit prop shapes, `auth.user` with `only()`, closures/`optional`/`defer`, redirects with session errors, `component($name, false)` when pages are absent.
   - **§11b Logging and errors** (new, bullets): exception objects, report or rethrow, `Throwable` at boundaries, `ShouldntReport`, levels and throttling in `bootstrap/app.php`, `logger()` is debug, `/up` + `DiagnosingHealth`, Pulse/Nightwatch only when installed.
   - **§6** fix the money line; one bullet for money (minor units, per-currency exponent, largest remainder, string parsing) and one for dates (UTC, user zone at the edges, half-open day ranges, `addMonthNoOverflow`, immutable dates).
   - **Checklist**: new **Webhooks**, **Security**, **Livewire/Inertia**, **Errors and logs**, **Dates and money** lines, each with a grep.
   - **References table**: the new files below.
   - Description: add "webhooks, Livewire, Inertia, logging" only if the trigger eval shows a need; keep valid YAML.
2. **New references**: `webhooks.md`, `security.md`, `livewire.md`, `inertia.md`, `observability.md`, `dates-and-money.md`. Each API cited with an evidence path.
3. Apply the corrections above. `audit-mode.md` gets rows for the new areas.

## Eval changes

1. **`setup.sh`**: two new bases. `base-livewire` (`base-pest` + `livewire/livewire:^4`, a minimal `layouts/app.blade.php`, a stub `login` route, no Flux or Fortify). `base-inertia` (`base-pest` + `inertiajs/inertia-laravel:^3`, `HandleInertiaRequests` appended to `web`, a root view without `@vite`, published config with `testing.ensure_pages_exist` off, no Node). Builds then spend their time on the rules, not on scaffolding.
2. **`tasks.json`**: new tasks with pinned interfaces, a behavioural `focus` (no API names), and hidden PHPUnit tests under `eval/hidden/`:

   | id | Gap | Behaviour the hidden tests check | Set |
   |---|---|---|---|
   | `acme-webhooks` | webhooks | a signature over the exact bytes sent (a body with surrounding spaces, an empty string, `10.50` and pretty printing, which every re-encoding breaks); tampered body, missing header or an empty configured secret → not 2xx and no effect; a duplicate delivery and a redelivery after full processing → the effect once; an unknown type → 2xx, no effect, no failed job; an older event processed after a newer one keeps the newer state; under `Queue::fake()` the request queues work and sends no HTTP; the CSRF middleware, made to run under the test runner, lets the delivery through | target |
   | `notes-search-api` | security | `?sort`/`?dir` outside the allow-list (an expression, an array, a hidden column) → 422 or the default order, never 500 and never ordered by the hidden column; "100%" never matches "1000 things", and (its own row, since SQLite has no default escape character) matches the literal title; another team's `project_id` → not 2xx and nothing created; `team_id`/`user_id` in the body ignored; secret columns never in any response | target |
   | `livewire-notes` | Livewire | a non-owner gets 403/404 on mount, and on `save()`/`delete()` after the owner mounted; tampering every public id or model property never changes another user's note; invalid input (including an untouched field) never reaches the DB; queries on a second request stay flat from 5 to 25 notes; scoping and pagination | target |
   | `inertia-projects` | Inertia | sentinel values (internal notes, cost, 2FA secret, another owner's email) never in props on index, show or a partial reload; `auth.user` keys ⊆ {id, name, email}; `project.title` at the pinned path; `activity` not queried on first load or on a partial reload that doesn't ask for it; validation failure → redirect with session errors; policy 403s | target |
   | `payments-errors` | observability | on the real handler with debug off: a declined card → 402 and no record carrying it at error or above; a gateway outage → 503 and exactly one log record carrying the exception object at the pinned level; a `TypeError` → 500 or 503, its message not in the body, one record carrying it; the card token in no log record | target |
   | `invoice-totals` | money | three 0.10 lines at 25% VAT, per-line half-up → exact integer minor units; JPY has no minor digits, more decimals than the currency allows → 422; `12,30`, `1e2`, negative → 422; a split of 100.00 into 3 sums back, parts differ by at most one; no JSON floats for money | target |
   | `team-invitations` | security | the plain invite token is in no table, log or response; a token works once; `return_to` never leaves the site; the 6th accept attempt for one email and IP in a minute → 429, another email from that IP still allowed | holdout |
   | `partner-sync` | observability | a command that syncs ids through a partner API: all ids attempted past failures, exit non-zero; a burst of identical partner failures logged at most three times a minute, again after a minute; a `TypeError` for one id logged with the object and the rest synced; the health check fails when the DB is unreachable, a separate liveness URL doesn't | holdout |
   | `subscription-renewals` | dates | IANA zone validation; "orders today" in Pacific/Auckland, Los Angeles and on the Copenhagen DST day; renewals anchored on the start day (Jan 31 → Feb 28 → Mar 31 → Apr 30, leap year Feb 29); timestamps with a zero offset | holdout |
   | `team-activity` | over-application | an internal Blade page with "last seen 3 minutes ago" and a 7-day login count in one business timezone; judged on proportion (no `Date::use`, per-user zones, throttles, logging, Livewire or caching the brief doesn't need) | probe |
   | 12 earlier tasks: sales-report, blog-crud, blade-catalogue, cart-discounts, filament-admin, scheduled-reports, shipment-sync, export-purge-command, carrier-quote, orders-api, stock-csv-import, token-login | — | their own hidden tests where they have them | regression |

3. Hidden tests check behaviour through the pinned interface, never which APIs a build used. They sign raw bytes with `call()`, collect log records with `MessageLogged`, run the real exception handler with debug off (`Exceptions::fake()` skips an exception's own `report()`, report callbacks and logging), count queries with `DB::listen` filtered by table, assert Inertia props by searching the JSON for sentinels, and assert Livewire tampering by the database outcome (a locked property throws, a typed model ignores the write, an unlocked one plus an authorize check 403s).
4. **Reference gate** (before any build): two independent correct reference builds per task from the brief alone (for example inbox table vs `ShouldBeUnique` job, `#[Locked]` id vs typed model property, resource vs `->only()`, `ShouldntReport` vs `dontReport`, integer columns vs `decimal` strings). Every hidden test must pass every correct design.
5. **`static.py`**: greps for the new misses (`getMessage()` passed to `Log::`, `logger(` in a catch, `catch (\Exception` , `orderBy($request`, `like.*\$` without `escape`, `exists:` on a tenant table, `(int) (… * 100)`, `addMonth(` , `whereDate(`, `Inertia::render` with a bare model, `json_encode($request->all())` near `hash_hmac`) and for over-application on the probe and regressions (`brick/money`/`moneyphp` added, `Date::use` in apps that don't do date maths, `#[Locked]` on `wire:model` fields).
6. **Trigger prompts**: +5 positives (a webhook receiver, a Livewire component, an Inertia page leaking data, error logging, a money rounding bug) and +3 negatives (a Stripe webhook in Express, a Vue component, Django timezones).

## Test protocol

- **Arms:** `plain`, `prev` (skill at `main`, v0.3.0) and `skill`, headless `claude -p --model sonnet`, the skill in the project's `.claude/skills/`, a prompt that never mentions it.
- **Order:** briefs and hidden tests are frozen and pass the reference gate first. Then every `prev` and `plain` build of the new tasks runs as a pilot, before any skill text is written: it shows which checks discriminate. After the pilot a test may be fixed (a wrong expectation) but never added or dropped for being easy or hard.
- **Builds:** targets + holdouts (9 tasks): 3 × `skill`, 3 × `prev`, 1 × `plain`. Probe: 2 × `skill`, 2 × `prev`, 1 × `plain`. Regression (12 tasks): 2 × `skill`, 2 × `prev`. 116 builds. A build that can't run at all (setup failure, a killed session) is rebuilt once and recorded.
- **Judging:** 2 blind sandboxed Opus judges per task over replicates 1 and 2.
- **Pass bar (all must hold):**
  1. targets + holdouts: (a) hidden-test pass rate `skill` ≥ `prev` in every area (Livewire and Inertia reported apart), no task worse by more than one build's tests; (b) on the checks `prev` failed in the pilot, `skill` fixes at least half of `prev`'s failures, pooled over targets, and separately over holdouts; (c) a greppable miss list per task, written before the skill (for example `getMessage()` passed to `Log::`, a re-encoded body inside an HMAC, `whereDate(` for a user's day, `(int) (… * 100)`, an unscoped `exists:`, a public collection of models in a Livewire component), is lower for `skill` than `prev` in total and not higher on any holdout;
  2. judge bugs per build `skill` ≤ `prev`, and correctness not lower, on targets and holdouts;
  3. regression and probe: mean overall `skill` ≥ `prev` − 0.3 in both replicates, regression hidden tests not lower, probe simplicity ≥ `prev`, and per-build counts of `Date::use`, `#[Locked]`, `throttle:`, `encrypted` casts, `report(`, `Log::` and `catch (\Throwable` no higher than `prev` + 0.5;
  4. the skill fired in every `skill`/`prev` build;
  5. triggering holds on the existing 30 prompts plus the new ones (Haiku, 3 runs);
  6. audit traps no worse than `main`.
- **Revise loop (at most two):** revise from target-task misses only, each miss confirmed with grep across every build; rebuild `skill` on all tasks the revision could touch, and judge fresh against the same `prev` builds.

## Cost and time

About 116 builds plus about 25 reference builds at $0.40–2.70 (~$140), about 80 judgements (~$70), up to two revisions of about 40 builds each (~$80), trigger and audit runs (~$10). Eight to ten hours wall clock, most of it builds.

## Deliverables

A PR on `feat/webhooks-security-frontend-observability-dates` with the skill changes, the harness changes and a `docs/evaluation.md` section; the raw results in a stacked PR if the main PR would pass 100 files. No release tag until the user decides.

## Review (Opus) and what changed

An Opus reviewer read the plan, the skill, the research and the harness, and ran experiments on clones of the base apps. Adopted:
- **Three test designs would have failed every correct build.** `Exceptions::fake()` skips an exception's own `report()`, report callbacks and logging, so "reported once" plus "a warning record" passed only a build that both reports and logs; the observability tasks now use the real handler and `MessageLogged`. `Http::assertNothingSent()` fails every correctly queued build under the sync queue, so it runs only under `Queue::fake()`. "The route has no CSRF middleware" failed a correct `web` route with an exception; the test now makes the CSRF middleware run under the test runner and posts without a token.
- **Checks that dictated a design** were rewritten to assert effects: a duplicate or redelivered webhook changes the domain once (an inbox table, a job-level dedupe and a unique key on the domain row all pass); Livewire tampering is judged by the database, accepting 403 or 404 and a thrown locked-property exception; a cross-team `project_id` may answer 403, 404 or 422; an unknown sort may answer 422 or fall back to the default order; a `TypeError` may answer 500 or 503.
- **Version and driver traps:** Livewire 4's `make:livewire` makes single-file components, so tests find components by name; SQLite has no default LIKE escape character, so a backslash-escape build that is right on MySQL fails only the positive literal-match check, which is its own row; Inertia partial reloads use `reloadOnly()`; the renewal anchor, the VAT rounding point and the money JSON shape are pinned in the briefs.
- **Task set.** The webhook holdout repeated the target, so it became `team-invitations` (hashed tokens, open redirects, limits). A second observability task without an HTTP layer (`partner-sync`) joined as a holdout. `webhook-worker` left the regression set (this change targets it); `cart-discounts`, `filament-admin` and `scheduled-reports` came back in place of `caching` and `coupon-redeem`, which nothing here touches. The probe moved to a page that brushes dates and admin UI where none of the heavy rules applies.
- **The skill snippet and the target don't share a provider** (header name and encoding differ), and briefs state requirements, never the API.
- **Pilot first.** `prev` doesn't depend on this round's text, so its builds run before any skill text exists. Pass bar 1 now measures the checks `prev` failed in the pilot and a pre-registered miss list, because both earlier rounds ended at the hidden-test ceiling.
- **SKILL.md budget** is about +100 lines, capped near 480, paid for by moving rarely used text to references. Round 6 builds read the matching new reference in 3 of 18 target builds, so a rule that matters goes in SKILL.md.
- **Rules that would over-apply:** `Date::use(CarbonImmutable::class)` would land in every fresh eval app, so the rule is "never mutate a date you didn't create", and switching the default is a project decision; `#[Locked]` never on a `wire:model` field; `encrypted` only for secrets the app reads back; LIKE escaping with an explicit `ESCAPE` character, for user-facing literal search only; no throttle on webhook receivers; the inbox only for slow or non-idempotent processing; version-specific APIs marked.

## Pilot and pre-registered misses

Every pilot build (`prev` × 3 and `plain` × 1 on the nine target and holdout tasks, `prev` × 2 and `plain` × 1 on the probe) passed every hidden test, after two hidden checks that rejected correct builds were fixed (an enum cast compared to a string, and an activity seed whose newest entry had the lowest id). The hidden tests therefore measure regressions only. Two Opus reviewers read every pilot build against this round's rules. The builds already met the main rules in every area: raw-body HMAC with `hash_equals`, the sort allow-list, LIKE escaping, scoped `exists`, hashed invite tokens, per-action Livewire authorization, allow-listed Inertia props, integer money, anchored renewals and half-open day ranges.

The misses they found, counted by `eval/misses.py` and fixed here before any skill text was written (pilot counts in brackets, `prev` then `plain`):
- acme-webhooks: the receiver sits in the `web` group with a CSRF exclusion, so every delivery starts a session and a bad payload gets a 302 (3/3, 1/1).
- notes-search-api: `when($term)` treats a search for `0` as no search (3/3, 0/1).
- team-invitations: the invite token is not in `dontFlash`, so a failed accept stores it in the session (1/3, 1/1).
- subscription-renewals: renew reads a counter and writes it back without a lock or an atomic update (1/3, 0/1).
- livewire-notes: the query-count test measures only the first render, never a later request (3/3, 1/1).
- inertia-projects: a global `JsonResource::withoutWrapping()` to unwrap a prop (1/3, 0/1); two or more resource classes for three-field props (3/3, 0/1).
- partner-sync (look at, not in the bar): the exception throttle covers every `Throwable`, not only the noisy class (3/3, 1/1); the brief's "same kind of error" makes this debatable.

Pass bar 1(b) has no pilot-failed hidden checks to measure, so it falls away. Bar 1(c) uses this list.
