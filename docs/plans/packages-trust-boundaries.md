# Plan: installed packages and trust boundaries (round 8, v0.5.0)

Goal: close the highest-severity gaps from the November 2026 research pass. The evidence bar is the same as rounds 6 and 7: hidden behavioural tests first, blind Opus judges second, and holdout and regression tasks to catch over-fitting and dilution.

## What the research found

Six researchers each covered one area: the Laravel 12/13 framework, its docs and its advisories; Spatie's guidelines and packages; first-party packages; open-source Laravel apps; PHP tooling; and the agent-skill ecosystem. A skeptic per area then checked every finding against the skill text and a primary source. 76 findings survived: 55 confirmed and 21 partly covered. They are published with the plan as an artifact. The ones this round acts on:

| Area | High-priority misses |
|---|---|
| Wrong text in the skill | `modern-laravel.md` shows `trustProxies(at: '*')` with nothing pinning the host, so a reset or invite link can carry an attacker's host. `exists`/`unique` have no type rule (on MySQL below 13.43, `{"code":0}` passes `exists:invites,code`). A null tenant scope matches `IS NULL` rows. A closure `where()` does not prevent that, but a 403 for a user with no tenant does. Uploads are read with `$request->file()` after validation instead of the validated file (GHSA-hxc4). `Str::markdown()` passes raw HTML and `javascript:` links by default. `/** @test */` doesn't run under PHPUnit 12. `pint --dirty` does nothing outside Git. |
| Spatie packages | A permission named like a policy ability (`update`, `delete`) makes the Gate grant it before the policy runs, for every model. A bare query-builder filter is a partial `LIKE`, so `filter[user_id]=1` matches user 10. `Data::from(array)` never validates, and PATCH needs `Optional`. `transitionTo()` is check-then-save. medialibrary defaults to the public disk and keeps the original file name. |
| Real-app bug classes | `ORDER BY` without a unique tiebreaker duplicates or skips rows across pages. Account state (blocked) applies only at login, not to tokens, sessions or resets. Morph types are stored as class names. |
| Currency | The November 2026 advisory wave sets a floor of 13.43.0 / 12.73.0. Laravel 11 is out of security support. Pest 5 needs PHP 8.4. Newer 13.x APIs: strict typed accessors, `refreshForUpdate`, `#[RetryFor]`, `deferred`/`background`/`failover` queue connections, `#[Scoped]`. |
| Delivery | The research reported that only the first ~5,000 tokens of a skill survive auto-compaction, and the checklist sits at line 372. This round does not move the checklist (see below). |

Deferred to later rounds: broadcasting, MCP and AI SDK tools, Cashier, Scout, Pennant, Fortify and Socialite (a first-party entry-points round needing its own bases); Larastan, arch presets and mutation scoring (a verification round); outbound webhooks.

## Skill changes

Builders follow rules in SKILL.md that come with a bad→good snippet and a checklist line. They mostly ignore rules that live only in references. SKILL.md is 431 lines; the budget is about +40, capped at 475, paid for where possible by tightening existing text.

1. **SKILL.md**
   - §0: run `composer audit`, report `laravel/*` advisories, Laravel 11 is unsupported. Point to `references/packages.md` when `composer.lock` lists a covered Spatie package.
   - §2: every `exists`/`unique` gets a type rule first; abort with 403 when the scope (tenant) is null; read the stored file from validated data; a paged or walked `ORDER BY` ends in a unique column; query-builder filters on ids, enums and booleans are exact; Data objects are built from the request or validated data, never `from($request->all())`.
   - §3: permission names never equal policy abilities; check with `can()`; seeders are idempotent.
   - §9: Markdown from users is rendered with HTML escaped and unsafe links off.
   - §11: absolute links come from `APP_URL`. Pin trusted hosts or force the root URL, and limit trusted proxies.
   - Checklist: lines for package idioms, typed `exists`, the validated file, Markdown, tiebreakers, absolute links, account state on every auth path, attribute-based tests.
2. **New `references/packages.md`**, date-stamped: laravel-permission, laravel-query-builder, laravel-data, laravel-model-states and laravel-medialibrary. For each: the idioms, the default that bites, and the renames per major version.
3. **Corrections and additions in references**: `modern-laravel.md` (bootstrap example, version line, `#[Scoped]`, 13.x APIs), `security.md` (host header and absolute links, typed `exists`, account state on every auth path, Markdown), `files-and-uploads.md` (validated file, medialibrary), `concurrency.md` (model-states transitions), `eloquent.md` (morph map, deterministic ordering), `blade.md` and `inertia.md` (tiebreaker, Markdown), `architecture-and-testing.md` (`#[Singleton]` statelessness, PHPUnit 12 attributes), `pest.md` (Pest 4/5 matrix), `style.md` (`pint --dirty` outside Git), `audit-mode.md` (a Dependencies row with `composer audit`, and the new Security rows).

The checklist stays in SKILL.md this round. Moving it out without a compaction test would risk the skill's most measured lever. A compaction check needs builds long enough to compact, which the harness doesn't have yet.

## Eval changes

1. **`setup.sh`**: new `base-spatie` (`base-api` + spatie/laravel-permission 8.5, laravel-query-builder 7.3, laravel-data 4.23, laravel-model-states 2.14, laravel-medialibrary 11.23), with config and migrations published and no traits added.
2. **Tasks** (round `packages-trust`), with pinned interfaces, a behavioural `focus` and hidden PHPUnit tests:

   | id | Base | Set | Behaviour the hidden tests check |
   |---|---|---|---|
   | `team-permissions` | spatie | target | an editor of one team can't update or delete another team's post; no role, no write; the seeder is idempotent; a newly granted permission applies on the next request |
   | `shipments-api` | spatie | target | `filter[user_id]=1` never returns user 10; exact status and boolean filters; unknown filter or sort → 400/422, never 500; a user with no warehouse sees no unscoped rows; an id sent as an array, `0`, `false` or SQL → 422 |
   | `profile-patch` | spatie | target | a PATCH with only `name` keeps the other fields; privileged fields ignored; posts not queried unless included; the import command rejects bad rows |
   | `payment-states` | spatie | target | two stale instances paying at once → one ledger row and one mail; invalid transitions and unknown states → 4xx; nothing mailed on rollback |
   | `invoice-uploads` | spatie | target | nothing on the public disk; owner-only download; no client file name stored; bad type → 422; a re-upload leaves one file; the downloaded bytes are the uploaded bytes |
   | `markdown-comments` | pest | target | script, event handlers, raw HTML and `javascript:` links inert in Blade and JSON; Markdown still renders; tied timestamps page without duplicates or gaps |
   | `block-user` | api | holdout | a blocked user can't log in, reset into a session, or use an existing token or session |
   | `password-reset-links` | base | holdout | a forged `Host` never reaches a mailed link |
   | `activity-feed` | pest | holdout | a polymorphic feed with tied timestamps pages exactly; type filter; a deleted subject doesn't break the feed |
   | `team-roles-page` | spatie | probe | admin-only roles page with no N+1, judged on proportion |
   | 12 earlier tasks: sales-report, blog-crud, blade-catalogue, filament-admin, shipment-sync, export-purge-command, carrier-quote, orders-api, stock-csv-import, token-login, notes-search-api, document-vault | — | regression | their own hidden tests where they have them |

3. **Reference gate** (before any build): two independent correct reference builds per task from the brief alone. Every hidden test must pass both.
4. **`misses.py`**: the round's misses are pre-registered from the pilot (below) before any skill text is written.

## Test protocol

- **Arms:** `plain`, `prev` (skill at `v0.4.0`) and `skill`. Headless `claude -p --model sonnet` with the skill in the project's `.claude/skills/` and a prompt that never mentions it.
- **Order:** briefs and hidden tests are frozen once they pass the reference gate. Every `prev` and `plain` build of the new tasks then runs as a pilot, before any skill text is written. After the pilot, a test may be fixed (a wrong expectation) but never added or dropped.
- **Builds:** targets and holdouts (9 tasks): 3 × `skill`, 3 × `prev`, 1 × `plain`. Probe: 2 × `skill`, 2 × `prev`, 1 × `plain`. Regression (12 tasks): 2 × `skill`, 2 × `prev`.
- **Judging:** 2 blind, sandboxed Opus judges per task, over replicates 1 and 2.
- **Pass bar (all must hold):**
  1. Targets and holdouts: hidden-test pass rate `skill` ≥ `prev` with no task worse by more than one build's tests, and pre-registered misses lower for `skill` than `prev` in total and not higher on any holdout.
  2. Judge bugs per build: `skill` ≤ `prev`, and correctness not lower, on targets and holdouts.
  3. Regression and probe: mean overall `skill` ≥ `prev` − 0.3 in both replicates, regression hidden tests not lower, and probe simplicity ≥ `prev` − 0.5.
  4. The skill fired in every `skill` and `prev` build.
- **Revise loop (at most two):** revise from target-task misses only, and rebuild `skill` on the tasks the revision could touch.

## Outcome

The draft was written from the research before the pilot. Two revisions followed, each from defects the judges found in target-task builds. Against the pass bar:
1. Hidden tests: this version passes 473/473 against 470/473 for v0.4.0, and no task is lower. **Met.** Pre-registered misses: 7 against 13 in total, and 4/21 against 11/21 on targets. **Met.** Not higher on any holdout: **not met**. activity-feed is 1/3 against 0/3 (no morph map), and password-reset-links is 2/3 against 1/3, because builds read the trusted-proxy range from `env()` in `bootstrap/app.php`.
2. Bugs per build 1.05 / 1.05 against 1.39 / 1.50. Correctness on targets 8.42 / 8.50 against 7.46 / 7.17, and on holdouts 8.00 / 8.50 against 7.67 / 7.50. **Met.**
3. Regression mean 8.03 / 7.82 against 8.05 / 7.76, regression hidden tests not lower, probe simplicity 9.00 / 7.50 against 7.50 / 7.50. **Met.** The probe's overall score is lower in both replicates.
4. The skill fired in every `skill` and `prev` build. **Met.**

Triggering (75/75 and 0/45 for both versions) and audit traps (the same for both) did not change.

Follow-ups for the next round, from the two holdout misses:
- The trusted-proxy rule should say the range lives in config (`config('app.trusted_proxies')` or a `TRUSTED_PROXIES` key read in a config file), not `env()` in `bootstrap/app.php`.
- The morph-map rule (`Relation::enforceMorphMap()`) is only in `eloquent.md`. Give it a line in `SKILL.md` §5 if a fresh round confirms the miss.
