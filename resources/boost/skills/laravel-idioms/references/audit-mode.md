# Audit mode: refactor suggestions for an existing Laravel codebase

Goal: a prioritised, verified list of refactors that make the code more idiomatic, delivered as one self-contained HTML report. Read-only: don't change application code unless asked.

## 1. Establish the baseline

1. Version: `laravel/framework` (and `php`) in `composer.lock`, falling back to `composer.json`. Note Pest vs PHPUnit (`tests/Pest.php`, `pestphp/pest`), Pint config, Livewire/Inertia, `filament/filament` major version, `laravel/horizon`, the cache and queue drivers in `.env.example`/`config/`, spatie packages.
2. Structure era: an upgraded app that still has `Http/Kernel.php`, `Console/Kernel.php`, `RouteServiceProvider` or `EventServiceProvider` gets one finding (medium, often effort L), not one per file.
3. House style: sample 3-4 models, controllers and jobs (properties or attributes, `scopeX` or `#[Scope]`, actions/services). Findings converge on the better convention; they don't punish consistency.
4. Only recommend APIs that exist in the installed version. When a claim depends on framework behaviour, read the method in `vendor/laravel/framework` rather than trusting memory.

## 2. Map the app, and count mechanically

Fix `scope` (e.g. `app routes database tests`) first. Every number in `stats` is the output of one of these commands, run over exactly that scope. Never estimate; omit a stat you couldn't compute.
```bash
S="app routes database tests"                                    # = the report's "scope"
find $S -name '*.php' | wc -l                                    # filesScanned
find app/Http/Controllers -name '*.php' ! -name Controller.php | wc -l   # controllers
find app/Models -name '*.php' | wc -l                            # models
find app/Jobs -name '*.php' 2>/dev/null | wc -l                  # jobs
find resources/views -name '*.blade.php' 2>/dev/null | wc -l     # views (add resources/views to scope if audited)
find tests -name '*Test.php' | wc -l                             # testFiles
find app -name '*.php' | sed 's|/[^/]*$||' | sort | uniq -c | sort -rn   # inventory
find app -name '*.php' -exec wc -l {} + | sort -rn | head -25          # largest files
php artisan route:list --json 2>/dev/null | head -c 20000       # else read routes/*.php
```
Every file in `scope` belongs to exactly one area and is read by that area's reviewer. `coverage.areas` lists each area with its file count, and the counts sum to `filesScanned`. Providers, `bootstrap/app.php`, routes, migrations and factories are the files most often skipped.

Areas: routes, controllers, form requests, policies, resources, models, factories, queries (anywhere), jobs/listeners/events/notifications/mail, caching (any `Cache::`/`cache(`/`RateLimiter::` call site), Blade views and `app/View`, `app/Filament`, providers and `bootstrap/`, config (including `queue.php`, `cache.php`, `horizon.php`, Supervisor files), tests. Skip `vendor/`, `node_modules/`, `storage/`, compiled views and IDE helper files.

## 3. Fan out

With a sub-agent tool (Agent/Task), launch one sub-agent per area in parallel; otherwise work through the areas in order. Give each: the version, house-style notes, its file list, the matching reference file, and the schema in §4. At most ~15 findings per area, highest impact first; one pattern repeated in 30 places is one finding with `locations`.

| Area | Reference | Look for |
|---|---|---|
| **Behaviour & bugs (run first, always)** | all | Idiom findings are worth less than one real bug. For each user-facing flow (route → request → controller → model/job → side effects), trace what actually happens and probe it with a throwaway test in the scratch copy. Check: auth guard and middleware match the route group (session guard on stateless `api` routes); authorization on every write; retries that multiply calls against a rate limit (`Http::retry` inside a rate-limited job); side effects (mail, notifications, dispatches) fired inside a transaction without `afterCommit`; `firstOrCreate`/`updateOrCreate` inventing records that should already exist; idempotency and duplicate delivery; date boundaries (`<=` on a date vs a datetime); inactive or soft-deleted records reachable by URL; unvalidated payload fields written to the DB; `old()` for checkboxes. Every confirmed bug is `high` unless its impact is trivial |
| HTTP layer | `http-layer.md` | actions over ~10 lines or with private query/format helpers; GET filters/`per_page` read without a FormRequest; inline `validate()`; `$request->all()` into `create/update`; create/update arrays built by hand from `$data[...]`, or defaults (status, owner) set in the controller, instead of `$request->user()->relation()->create($request->validated())`; derived fields (slug, `published_at`) computed in the controller instead of a model `creating`/`saving` hook; `authorize()` that just returns `true`; mixed authorization mechanisms in one controller; inline role checks; non-CRUD methods on resource controllers; manual `where('slug')` lookups; string rules; `json([], 204)`; `fresh()` where `load()`/`refresh()` is enough; resources touching unloaded relations or formatting dates by hand (`->toJSON()`, `->format()`) to the same output default serialisation gives |
| Eloquent & queries | `eloquent.md` | the same filter written twice (scope + `DB::table()`); string constants instead of backed enums; missing casts; N+1 in loops/views/resources; `->get()->count()`, `count() > 0`, `first()->col`, `all()->each`; offset `chunk()` while updating; hard-coded foreign keys; `$casts` property; string status comparisons; legacy accessors; find-then-create; `updateOrCreate` in loops; `$fillable`/`#[Fillable]` containing owner foreign keys or derived columns (`user_id`, `slug`: security, medium); factories that call model helpers which query the database |
| Collections & helpers | `collections-and-helpers.md` | accumulate-in-foreach; `array_map`/`array_filter` on collections; `is_array ? : [$x]`; nested `isset`; `optional()`; `empty()` for input; `sleep()`; hand-rolled retries; missing `->values()` before JSON |
| Architecture | `architecture-and-testing.md` | unguarded parsing of files/payloads; DTOs that only exist for `toArray()`; sync work that should be queued; `env()` outside config; `app()`/`new` inside methods; empty layers (a repository/service wrapping one Eloquent call); missing policies; manual `Gate::policy()` / `Event::listen()` registrations for classes that are auto-discovered |
| Queues & workers | `queues-and-workers.md` | status-tracking jobs without `failed()`, or `failed()` reading state set in `handle()`; non-idempotent `handle()` (increments, charges without a state check or idempotency key); default single try on jobs that `release()` or use `RateLimited`/`WithoutOverlapping`; no backoff; `#[Timeout]`/`--timeout` ≥ `retry_after`; HTTP calls without a timeout inside jobs; dispatch inside transactions without `afterCommit`; `ShouldBeUnique` without `uniqueId()`/`#[UniqueFor]`; `WithoutOverlapping` without a key or `expireAfter()`; `Redis::throttle`/try-catch backoff in `handle()`; arrays of model data or eager-loaded relations in constructors; `$this->delete()` expected to stop a chain; `$this` in batch callbacks; jobs wrapping a notification instead of queueing it; four-trait jobs; property and attribute both set; `build()` mailables; `queue:listen` or no `queue:restart` in deploy/Supervisor config; Horizon `balance => auto` assumed to prioritise; no scheduled `queue:prune-failed`/`prune-batches`; personal data in payloads without `ShouldBeEncrypted` |
| Caching | `caching.md` | `put()`/`cache([...])` with no TTL; `has()` then `get()`/`put()`; `get()+1` then `put()` counters; `remember()` closures that can return null; bare or unscoped keys (`'users'`, tenant data without a tenant id); cached Eloquent models/collections; `Cache::flush()` in app code; invalidation inside transactions without after-commit; `tags()` on file/database stores; `lock()` without seconds or without `finally`; `forceRelease()`; hand-rolled overlap/concurrency counters; `Http::get()` cached without `->throw()` or a timeout; `throttle:60,1` magic numbers or several limits sharing one `by()` key; locks/limiters on a per-server store in a multi-server app; cache mocked with `shouldReceive('remember')` in tests |
| Blade | `blade.md` | queries, `Model::` calls, lazy-loaded relations or `->count()` on relations inside views; `@php` queries in layouts instead of a composer; `@extends` mixed with component layouts; anonymous components without `@props`, or `$attributes` missing/not on the root; props passed without `:`; forms missing `@csrf`, using `method="PUT"`, or lacking `old()`/`@error`; `isset($errors)`; ternaries instead of `@checked`/`@selected`/`@disabled`; `@if` inside `class=""`; `{!! !!}` on user data or flash messages with user input; `json_encode` or `'{{ $x }}'` in JS; hard-coded URLs; `view()` returned from POST handlers; pagination without `withQueryString()`; manual loop counters; inline ownership checks instead of `@can`; `mix()` or `/build` paths |
| Filament | `filament.md` | v3 APIs on v4/v5: `Filament\Forms\Form`, `Filament\Tables\Actions\*`, layout components or `Get`/`Set` from `Filament\Forms`, `->actions()`/`->bulkActions()`, `Action::form()`, `getActions()`, static widget or relation-manager members, `MultiSelectFilter`; monolithic `form()`/`table()` instead of `Schemas/` and `Tables/` classes; per-column colour/option maps instead of enum `HasLabel`/`HasColor`/`HasIcon`; `pluck()` options instead of `->relationship()`; `getStateUsing` N+1; custom actions or inline-edit columns without authorization; tenant scoping in filters or tabs instead of `getEloquentQuery()`; User without `FilamentUser`; policies missing `viewAny`; sensitive attributes not hidden; deprecated test helpers (`callTableAction`, `assertFormSet`); pages tested with `$this->get()` |
| Tests | `architecture-and-testing.md` | requirements or failure paths (401/403/422, limits, empty results) with no test; validation tests that don't assert the message; raw foreign-key arrays instead of `->for()`; real HTTP calls; `Event::fake()` before factories; `DB::table()->count()` asserts; duplicated overrides instead of states; untested policies/requests; untested job `failed()`/release paths; queue tests that switch to `sync` instead of `Queue::fake()`/`withFakeQueueInteractions()` |
| Pest | `pest.md` | PHPUnit classes in a Pest suite (or Pest files in a PHPUnit-only suite); `static` test/hook closures; `uses(RefreshDatabase::class)` repeated per file, or RefreshDatabase assumed while commented out in `Pest.php`; Laravel-dependent tests in an unbound `tests/Unit`; `$this` in `beforeAll`; dataset values that create models outside closures; `$this->assertX` where `expect()` chains fit; `toBe()` on decimal casts or separate model instances; missing `refresh()` before asserting; try/catch + `fail()` instead of `->throws()`; fakes set after the action; bare `Mockery::mock()` for container-resolved classes; `Pest\Laravel` functions without `use function`; committed `->only()` or commented-out tests; no arch tests, or a preset deleted instead of `->ignoring()` |
| Outdated patterns | `modern-laravel.md` | Kernel/Handler/old providers; `$listen` arrays (listeners running twice); `$this->middleware()` in constructors; `AuthorizesRequests`/`ValidatesRequests` re-added to the base controller; legacy `Rule` interface; `withValidator`; `$dates`; schedules in Console Kernel |
| Style | `style.md` | only style that hurts readability (deep nesting, `else` chains, `switch` mapping). Recommend Pint instead of listing whitespace |

**Authorization advice uses one mechanism per controller** (SKILL.md §3). If every action that needs a check already has a FormRequest, put the policy call in its `authorize()`. Otherwise use one controller-level mechanism for all of them: route `->can()` or, on 13, `#[Authorize('update', 'post')]` on each action. Never suggest adding a second mechanism next to an existing one. Boilerplate `authorize(): bool { return true; }` either becomes the check or is deleted.

## 4. Finding schema

| Field | Values |
|---|---|
| `id` | `F-001`... |
| `title` | imperative, under 80 chars ("Move thread validation into a FormRequest") |
| `category` | `http`, `eloquent`, `collections`, `architecture`, `queues`, `caching`, `blade`, `filament`, `testing`, `modern`, `style`, `security`, `performance` |
| `severity` | `high`: bug, security or data risk, or measurable performance cost (mass assignment from `$request->all()`, N+1 on a list page, `env()` outside config, a listener registered twice, a job reading uncommitted rows, a non-idempotent job whose timeout exceeds `retry_after`, tenant data under an unscoped cache key, `{!! !!}` on user input, an unauthorized Filament action). `medium`: maintainability or an outdated pattern with real friction. `low`: idiom polish |
| `effort` | `S` (< 30 min, local), `M` (a few files), `L` (cross-cutting, or needs tests first) |
| `file`, `line` | path from the repo root; 1-based line of the **offending statement** (the `use AuthorizesRequests;` line, not the file start), even when `current` starts earlier |
| `locations` | other `file:line` occurrences of the same pattern (optional) |
| `current` | the exact current code, 3-15 lines |
| `suggested` | the idiomatic rewrite, runnable in the installed version |
| `why` | 1-3 sentences: what goes wrong or gets easier. Not a restatement of the rule |
| `reference` | the rule/section in this skill, or a Laravel docs URL |
| `dependsOn` | ids that must be applied first because this `suggested` assumes them (a 422 test needs the validation finding; `Rule::enum` needs the enum finding) |
| `overlaps` | ids whose rewrite touches the same code. Prefer merging; if you keep both, cross-reference |

Severity calibration: rate the impact you can show, not the pattern. An N+1 over a naturally small set (a post's tags on a write path) is `low` unless the set is unbounded or on a list endpoint. Downgrade anything speculative.

## 5. Verify before reporting

Re-open each file at the cited line and confirm:
- the excerpt matches exactly and `line` is the offending statement;
- the problem is real in context (the relation isn't eager loaded upstream; the `$request->all()` isn't already narrowed by `only()`);
- the API exists in the installed version and the rewrite preserves behaviour: keys, ordering, events fired, null handling;
- **the rewrite does not introduce a race or weaken a guarantee.** Check what the current call already does (e.g. `firstOrCreate` falls back to `createOrFirst` on recent versions, so a bare `insert()` replacement can throw on a unique index; use `upsert`/`insertOrIgnore`). Don't widen the mass-assignment surface, drop a transaction, or skip model events;
- the rewrite introduces no name collision (a `#[Scope]` or accessor named like a column changes what `$model->status` resolves to);
- it isn't a documented project convention (`CLAUDE.md`, README, comments).

Then across all findings:
- **Latent bugs:** if writing a rewrite exposes a separate bug (input trimmed for the check but not the write, an off-by-one, a missing null guard), report it as its own finding, rated by its impact (usually `high`), and link the two via `overlaps`. Never fix a bug silently inside a refactor.
- **Consistency:** suggestions must work together. Once one finding introduces an enum, the others use `Rule::enum` and its cases; once one moves a method, the others don't patch its old location. `line` and `current` always refer to the unmodified revision; `suggested` assumes its `dependsOn` are applied.
- **Recall floor:** walk SKILL.md's "Before you finish" list item by item against the audited code. Each item either produces a finding or goes into `coverage.checklist` as `cleared` with the evidence (the grep or files checked). An item with no evidence isn't cleared.

- **Grounded claims:** every factual statement in `why` (what an API throws, what the framework does, what the project does "elsewhere") is backed by a line you read in `vendor/` or the repo. If you can't point at one, cut the claim.
- **Checklist honesty:** before clearing Collections, run `grep -rnE 'foreach|array_(map|filter|walk|reduce)|\+\+|\+= ' app/` and judge every hit. Before clearing Tests, run `grep -rnE 'assertStatus\((200|201|204|401|403|404|422)\)|::create\(' tests/`.
- **Checklist rule:** `cleared` means you found no instance anywhere in scope. Manual counters in a job, `array_map` closures, or `assertStatus(403)` where a helper exists are small findings (`low`), not "compliant".

### 5b. Prove the apply order (mandatory when `php artisan test` runs)

A report whose fixes break the app is worse than no report. Before building it:
```bash
T=$(mktemp -d) && cp -R . "$T/app" && cd "$T/app"     # scratch copy; never touch the audited repo
php artisan test                                       # baseline
```
Apply each finding's literal `suggested` text from your JSON (not a cleaned-up version you keep in your head) in apply order (the `dependsOn` topological order), including the imports it needs and every listed location, and run `php artisan test` after each one. When something breaks:
- a hidden prerequisite (a column dropped from `$fillable` that another write path still sets; an enum cast that existing tests compare to a string) → add it to `dependsOn`, or widen `suggested`/`locations` to cover the call site;
- a combination that breaks (strict mode + `create($request->validated())` with non-fillable keys) → fix the later finding's `suggested` (`safe()->except([...])`) and add `overlaps`;
- a rewrite that is simply wrong → fix it or drop the finding.
Anything you had to add to make it work goes back into the report: missing `use` imports go into `suggested`, since every snippet shows the imports it adds or changes and removes the ones it orphans. A prerequisite you had to apply first goes into `dependsOn`, so the report's apply order is exactly the order that passed. Then run `vendor/bin/pint --test` if Pint is installed.
`coverage.verified.suite` is the literal summary line printed by the final `php artisan test` run after every `suggested` block was applied as written, including tests the findings change. If a finding deletes something a test reads (a property, a method), its `suggested` includes the updated test. Never write a pass count you didn't see.
Also exercise the edge cases the finding itself raises (malformed rows, duplicate keys, a second chunk, a blank query param with `ConvertEmptyStringsToNull`), with a throwaway test in the scratch copy. A test that the report itself suggests must pass once its finding and that finding's dependencies are applied. Quick wins must be applicable on their own: a finding with unmet `dependsOn` is not a quick win. Record in `coverage.verified` which findings were applied and run (`"applied": ["F-001", ...], "suite": "19 passed"`). If the suite can't run, say so in the summary and mark the report "unverified".

Drop anything that fails. When sub-agents proposed the findings, verify them yourself or with a separate verifier agent.

## 6. Prioritise

The summary never calls an app healthy or "well-built" unless the Behaviour & bugs pass came back empty *and* says which flows were probed.

Sort by severity, then effort (S before L), then number of locations. Quick wins = `high`/`medium` with effort `S` and no unmet `dependsOn`, max ~8. The template derives the apply order from `dependsOn`. Summary: 2-4 sentences covering overall health, the top 2-3 themes and the first step.

## 7. Build the report

1. Write the data as JSON to a temp file (e.g. `/tmp/audit.json`) in this shape:
```json
{
  "project": "acme/shop", "generated": "2026-09-22", "laravelVersion": "12.28.1", "phpVersion": "^8.3",
  "summary": "Two to four sentences.",
  "scope": ["app/", "routes/", "database/", "tests/"],
  "stats": { "filesScanned": 214, "controllers": 31, "models": 18, "jobs": 9, "testFiles": 57 },
  "coverage": {
    "areas": [{ "name": "HTTP layer", "files": 42, "note": "all controllers, requests, resources" }],
    "checklist": [
      { "item": "Validation", "status": "finding", "findings": ["F-001"] },
      { "item": "Collections", "status": "cleared", "note": "grep for foreach/&$ in app/: none" }
    ]
  },
  "findings": [{
    "id": "F-001", "title": "...", "category": "http", "severity": "high", "effort": "S",
    "file": "app/Http/Controllers/PostController.php", "line": 42, "locations": [],
    "current": "...", "suggested": "...", "why": "...", "reference": "http-layer.md#formrequests",
    "dependsOn": [], "overlaps": []
  }]
}
```
   `checklist.status` is `finding`, `cleared` or `n/a` (with a note saying why), one entry per "Before you finish" item.
2. Build with this script (`<skill>` is this skill's directory; output goes to the repo root unless the user named a path). It validates references, embeds the JSON safely, and writes the static no-JS findings list:
```bash
python3 - <skill>/assets/audit-report-template.html /tmp/audit.json laravel-refactor-report.html <<'PY'
import json, re, sys, html
tpl, src, out = sys.argv[1:]
d = json.load(open(src)); fs = d['findings']; ids = {f['id'] for f in fs}
assert len(ids) == len(fs), 'duplicate ids'
for f in fs:
    assert f['severity'] in ('high', 'medium', 'low') and f['effort'] in ('S', 'M', 'L'), f['id']
    bad = set(f.get('dependsOn', []) + f.get('overlaps', [])) - ids
    assert not bad, f"{f['id']} links unknown {bad}"
fs.sort(key=lambda f: ({'high': 0, 'medium': 1, 'low': 2}[f['severity']], 'SML'.index(f['effort']), -len(f.get('locations', []))))
e = html.escape
li = ''.join(f"<li id=\"s-{e(f['id'])}\"><b>{e(f['id'])}</b> [{e(f['severity'])}, {e(f['effort'])}] {e(f['title'])}<br><code>{e(f['file'])}:{f['line']}</code>"
             + (f" (needs {e(', '.join(f['dependsOn']))})" if f.get('dependsOn') else '') + f"<br>{e(f['why'])}"
             + f"<details><summary>Code</summary><pre>{e(f['current'])}</pre><pre>{e(f['suggested'])}</pre></details></li>" for f in fs)
s = open(tpl).read()
s = re.sub(r'(<script id="report-data" type="application/json">).*?(</script>)',
           lambda m: m[1] + json.dumps(d, indent=1, ensure_ascii=False).replace('</', '<\\/') + m[2], s, count=1, flags=re.S)
s = re.sub(r'<!--static:start-->.*?<!--static:end-->', lambda m: f"<!--static:start--><p>{e(d['summary'])}</p><ol>{li}</ol><!--static:end-->", s, count=1, flags=re.S)
open(out, 'w').write(s)
PY
```
   Don't edit the template's CSS/JS unless something is broken. The report must stay self-contained: no CDN scripts, external fonts or stylesheets, and no network requests.
3. If an Artifact publishing tool is available, publish the file and return the link. Otherwise give the absolute path.

## 8. Reply

Keep it short: the report path/link, finding counts by severity, the quick wins as bullets, and any area you couldn't cover (e.g. artisan wouldn't boot). Don't paste the report into chat.
