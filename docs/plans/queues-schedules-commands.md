# Plan: queues, workers, scheduling and Artisan commands (round 5)

Goal: make the skill reliably steer agents on queued jobs, workers in production, the scheduler and console commands, and prove it with blind A/B builds against both no skill and the current release.

## What the research found

Six Sonnet researchers worked on one topic each: jobs, workers, chains/batches/events, scheduling, commands, and a survey of 14 production apps. A second Sonnet then checked each topic's APIs and defaults against laravel/framework 13.40 and the 13.x docs. That left 128 rules (18 rejected), 39 corrections to the current text and 14 task ideas. Raw output: `~/Code/laravel-idioms-eval/r5/research.json` (not committed).

Coverage today:

| Area | Current state | Gap |
|---|---|---|
| Jobs | Good (`queues-and-workers.md`, SKILL §7) | soft-deleted models restored by SerializesModels; `afterResponse()` is not queueing; `ThrottlesExceptions` swallows errors (`->report()`); `WithoutOverlapping` defaults (release 0, expiry 0); `#[CountCrashesAsExceptions]`; no backoff → tight retry loop |
| Workers | Supervisor + Horizon basics | pcntl requirement; containers (one worker per container, exec-form PID 1, grace period); SQS has no `retry_after` (visibility timeout, 1 MiB payload, 15-min delay cap); `queue:monitor` + `QueueBusy`; liveness; restart/pause need a shared cache; scoped bindings vs statics |
| Chains/batches/events | Examples, one of them wrong | `then()` never runs once any job fails, even with `allowFailures()`; use `finally()` + `hasFailures()`; a batch has one queue; batches/chains have no `afterCommit()`; queued listeners need `ShouldQueueAfterCommit`, not `ShouldHandleEventsAfterCommit`; Context carries no secrets/PII; `Mail::assertQueued` for `ShouldQueue` mailables; `Bus::fake()` can't see queued listeners |
| Scheduling | Two lines | nearly everything: `->name()` before `withoutOverlapping/onOneServer` on closures; `withoutOverlapping(expiresAt)` (default 24 h); `Schedule::job()->withoutOverlapping()` only covers dispatch; `onOneServer` + shared `Schedule::useCache`; timezone/DST; frequency-chain order (`->mondays()->weekly()`); `runInBackground` only for command/exec; sub-minute + `schedule:interrupt`; `pingOnSuccess` vs `thenPing`; hooks on `Schedule::job` mean "dispatched"; testing via `app(Schedule::class)->events()` |
| Commands | Five lines in `style.md` | non-zero exit on failure (`self::FAILURE`, `$this->fail()`); no work in constructors; `confirm(default: false)`; `confirmToProceed()` + `--force`; `--dry-run`; `Prohibitable`; `chunkById` when the loop updates the filter column; `Isolatable`; `trap()`; L13 `#[Signature]` + `#[Description]`; `PromptsForMissingInput`; testing with `artisan()->...->assertExitCode()` |
| Production survey | — | Recurring real bugs: log-and-return in jobs (exit 0 / no retry), dispatcher commands that re-dispatch the same rows every tick, `withoutOverlapping()` without expiry on multi-container ECS, jobs stuck in `processing` after SIGKILL, `chunk()` over the column being updated, untested schedules |

Corrections to make (all source-verified): the chain/`delete()` wording, the batch example (`then()` + `allowFailures()`), the `Schedule::job(...)->withoutOverlapping()` housekeeping example, timeout ordering (add SQS visibility timeout and orchestrator grace), the attribute list (`CountCrashesAsExceptions`, class-only attributes), the listener `afterCommit` interface, `make:queue-batches-table` (the skeleton ships `job_batches`), the testing helper list, `horizon:snapshot` marked Horizon-only, the "Console Kernel is dead" wording (only with `Application::configure()`), and the SKILL.md claim that Boost covers scheduling.

## Skill changes

Earlier rounds showed that builders ignore rules that live only in references. A rule changes behaviour when it sits in SKILL.md with a bad→good snippet and a checklist line. So:

1. **SKILL.md** (stays under ~300 lines)
   - §7 Queues: tighten the existing bullets and add the high-priority misses (soft-delete restore, `afterResponse`, `WithoutOverlapping` with `releaseAfter`+`expireAfter`, `ThrottlesExceptions->report()`, batch `finally()`, listener `ShouldQueueAfterCommit`, never log-and-return).
   - New **§7b Scheduler**: one bad→good snippet (a closure with `name`, `withoutOverlapping(expiresAt)`, `onOneServer`, timezone, `pingOnFailure`; `Schedule::job` + `ShouldBeUnique` instead of `withoutOverlapping`), plus 5–6 bullets.
   - New **§7c Console commands**: one bad→good snippet (`#[Signature]`/`#[Description]`, `handle()` injection, `confirmToProceed`, `--dry-run`, `chunkById`, `self::FAILURE`), plus 5–6 bullets.
   - §0: add `#[Description]` to the L13 attribute list; in an upgraded app, find where schedules live today before adding one.
   - Remove "Boost covers … scheduling" from the Boost paragraph.
   - Checklist: split "Queues/workers" into **Jobs**, **Workers/deploy**, **Scheduler** and **Commands**, each with concrete greps (e.g. `grep -n "withoutOverlapping()" routes/console.php`). Extend **Tests** with "`handle()` run twice, `failed()` called, schedule asserted via `events()`, every command exit path asserted".
   - Description frontmatter: add "scheduled tasks, Artisan commands, workers". Re-run the trigger eval, because the description drives triggering. Keep the frontmatter valid YAML (no `": "`).
2. **New `references/scheduling.md`**: the full scheduler rules, deploy/cron/`schedule:work`, monitoring, testing recipes.
3. **New `references/console-commands.md`**: signature syntax, prompts/non-interactive behaviour, destructive-command guards, big-table iteration, signals, isolation, output, testing.
4. **`references/queues-and-workers.md`**: apply the corrections; add **Drivers** (SQS/Redis/database limits), **Containers** (ECS/k8s), **Monitoring** (`queue:monitor`, `QueueBusy`, failed-job alerts, liveness) and **Crash safety** (claim timestamps, recovery command) sections; expand testing (`serializeAndRestore`, listener assertions, `assertQueued`).
5. **`style.md`** Commands section → a pointer to `console-commands.md`. **`modern-laravel.md`** Scheduling → a pointer to `scheduling.md`, plus `DB::prohibitDestructiveCommands`. **`pest.md`**: Testing commands and schedules. **`audit-mode.md`**: add Scheduler and Commands rows and extend the Queues row.
6. References table in SKILL.md: add the two new files.

Every new API is cited with an evidence path, as the existing references do. I re-check anything the verifier marked `fixed` against the source before it goes in.

## Review (Opus) and what changed

An Opus reviewer read the skill, this plan and the research, and checked the key claims against the 13.40 source. Adopted:

- **The original pass bar sat inside the noise.** Judges differ by 0.6–0.75 on the same build. "Wins at least 4 of 6" passes a no-op change 34% of the time. So: hidden behavioural tests run by the harness are the **primary metric**, and judge scores are secondary.
- **No judge-read checklists drawn from the skill's own rules.** Each brief pins its interface (class, command and table names). A fixed Pest file per task then tests behaviour, not API names, so `plain` can pass too.
- **Holdout.** `stock-sync-dispatcher` and `queue-bugfix` are never read during revision. After a revision, every arm is rebuilt and judged together in fresh sessions.
- **The rules are conditional.** `--dry-run`/`confirmToProceed` apply to destructive commands, `onOneServer` to multiple servers, `->timezone()` to business-time tasks. An **over-application probe** (a read-only command plus one daily entry) is scored on simplicity.
- **"Never log-and-return" is worded against the idempotency guard:** nothing to do → return; something failed → throw or `fail()`.
- **SKILL.md growth capped at +40 net lines.** Non-queue bullets (hostile files, `readStream`, `Validator::make` on rows) and the Supervisor details move out of §7.
- **The three `withoutOverlapping` APIs and the two `*AfterCommit` interfaces get one line each.**
- **`worker-deploy` dropped** (an unexecutable README turns into keyword matching). Replaced by **`queue-bugfix`**: a seeded app holding the production-survey bugs, with a symptom-only brief.
- **`webhook-worker` counts as a target, not a regression check.** The regressions are `sales-report` + `blade-catalogue`, whose sections come after the inserted text.
- **Trigger negatives added:** a Symfony Console command, a Node crontab question, a Celery worker question.

## Eval changes

1. **`setup.sh`** (done): `ARMS`/`BASES` env, a `prev` arm holding the skill at `$PREV_REF` (`main` this round).
2. **`tasks.json`**: new target tasks with pinned interfaces, a behavioural `focus` (no API names), and an optional `hidden` Pest file under `eval/hidden/<task>.php`:

   | id | Behaviour the hidden tests check | Set |
   |---|---|---|
   | `shipment-sync` | `handle()` twice → one shipment, one HTTP call; upstream 500 → throws/releases, not silent; `failed()` marks the order | target |
   | `statement-batch` | one statement job fails → the run still completes and records the failure; one mail per customer | target |
   | `scheduled-reports` | schedule events registered with the right cron/timezone/one-server/expiry; `schedule:run` in one minute under a shared cache runs once | target |
   | `export-purge-command` | 2,500 rows all purged (chunk-skip bug); production without `--force` deletes nothing and exits non-zero; `--dry-run` deletes nothing; failure → non-zero | target |
   | `webhook-worker` | the existing task, judge-only | target |
   | `stock-sync-dispatcher` | dispatcher run twice → each row pushed once; a stuck `processing` row is recovered | holdout |
   | `queue-bugfix` | seeded bugs fixed: a failing job retries/fails visibly, no re-dispatch, no skipped rows, overlap lock expires | holdout |
   | `inactive-count` | over-application probe; judged on simplicity | probe |
   | `sales-report`, `blade-catalogue` | unchanged | regression |

3. **`eval/hidden.py`**: copies each build, drops the hidden test file into `tests/Hidden/`, runs only that file, and records pass/fail per test. No LLM involved.
4. **`build.py --runs N`**: replicate builds named `<task>-<arm>-<n>`.
5. **`judge.py`**: optional per-task `skip_dimensions` (n/a dimensions are dropped from the schema and prompt). Judges see one replicate per arm (replicate 1), so judging stays a 3-way blind comparison.
6. **`aggregate.py`**: hidden-test pass rate per arm and task.
7. **Trigger prompts**: +3 positives (schedule, command, worker), +3 negatives.

## Test protocol

- **Arms:** `plain`, `prev` (skill at `main`) and `skill`, each built in a clean headless session (`claude -p --model sonnet --setting-sources project,local --strict-mcp-config`, skill in the project's `.claude/skills/`, a prompt that never mentions it). Logs record whether the skill fired and which references were read.
- **Builds:**
  - targets + holdout (7 tasks): 3 × `skill`, 3 × `prev`, 1 × `plain`;
  - probe and regressions (3 tasks): 2 × `skill`, 2 × `prev`;
  - about 61 builds in total.
- **Judging:** 2 blind sandboxed Opus judges per task, over replicate 1 of each arm.
- **Pass bar (all must hold):**
  1. on targets + holdout, the hidden-test pass rate for `skill` > `prev` (pooled over replicates), and `skill` is not worse on any single task by more than one replicate's worth;
  2. judge bugs per build for `skill` ≤ `prev`, and correctness is not lower;
  3. regressions and probe: the mean overall for `skill` ≥ `prev` − 0.3, and simplicity on the probe ≥ `prev`;
  4. the skill fired in every `skill`/`prev` build (otherwise rebuild);
  5. SKILL.md size stays bounded: it landed at 308 lines (+58), over the reviewer's +40 because the two new snippets and the round 2–4 fixes needed the room. The regression and probe tasks are the real check on dilution;
  6. triggering holds on the existing prompts, fires on the new positives and skips the new negatives.
- **Revise loop (at most one):** revise from target-task misses only, rebuild every arm on the holdout + regressions, judge fresh.

## Cost and time

About 61 builds × ~$2.70 (~$165) + 20 judgements (~$25) + a trigger round (~$1) ≈ **$190**, over 3–4 h wall clock. Hidden tests cost nothing.

## Deliverables

A PR on `feat/queues-schedule-commands` with the skill changes, the eval harness changes, the round's results and a `docs/evaluation.md` section. No release tag until the user decides.
