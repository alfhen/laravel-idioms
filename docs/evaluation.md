# Evaluation

All evaluations used fresh Laravel 13.33 apps: PHPUnit, Pest 5.2 and Filament 5.8 variants. **Claude Sonnet** did the building and **Claude Opus** the blind judging. Judges scored each build from 1 to 10 on:
- collections and helpers
- thin controllers
- validation and Form Requests
- Eloquent
- architecture fit
- modern Laravel
- the task's focus area
- tests
- correctness (with edge cases probed)
- simplicity, which penalises over-engineering

## Tasks

| Task | Exercises |
|---|---|
| sales-report | JSON reporting endpoint, aggregation, validated query filters |
| blog-crud | JSON CRUD, policies, slugs, tags, pagination |
| csv-import | Upload, queued processing, row validation, mail |
| cart-discounts | Pricing rules, domain validation, 422 reasons |
| blade-catalogue | Server-rendered Blade UI, components, forms, pagination |
| filament-admin | Filament 5 resource, filters, actions, relation manager, widget |
| caching | Per-team cache with invalidation and a stale window, stampede lock, stale fallback |
| webhook-worker | Signed webhooks, idempotency, rate-limited retries, pruning, worker config |
| shipment-sync | Queued API call after payment: idempotency, retries, 429s, failure state |
| statement-batch | Batch fan-out with failures allowed, completion summary |
| scheduled-reports | Schedules on three servers: timezone, overlap, one-server locks, memory-safe prune |
| export-purge-command | Destructive Artisan command: dry run, production confirmation, partial failure, nightly run |
| stock-sync-dispatcher (holdout) | Scheduled dispatcher with claims, rate limits and retries |
| queue-bugfix (holdout) | Fix a seeded app whose job swallows failures and double-sends |
| inactive-count (probe) | A small scheduled count that tests for over-engineering |

## Skill vs no skill

For these rounds, a workflow sub-agent read the skill. Rounds are separate because the skill was revised between them.

| Round | Skill wins | Mean score, with vs without |
|---|---|---|
| 1 (first version) | 3/4 | 7.70 vs 6.75 |
| 2 (revised from judge critiques) | 4/4 | 8.13 vs 6.25 |
| 3 (Pest/Blade/Filament/caching/queues added), new tasks | 4/4 | 8.18 vs 6.05 |
| 3, original tasks re-run | 3/4 | 7.90 vs 6.90 |

## Against Laravel Boost 2.9.1

Each build ran as a headless Claude Code session inside the project: `claude -p` with project settings only and no user-level skills. Boost setups got Boost's own MCP server. The prompt was identical for every setup and never mentioned a skill or Boost. Judges saw anonymised copies with all tooling files removed and Boost uninstalled. Two judges per task each ranked all builds; the second reviewed them in reverse order.

**First comparison** (6 tasks):

| Setup | Mean | Ranked #1 (of 12) |
|---|---|---|
| plain | 5.61 | 0 |
| this skill | 7.90 | 8 |
| Boost | 6.19 | 1 |
| Boost + skill copied into `.claude/skills` | 7.07 | 3 |

With Boost installed, the skill activated in only 1 of 6 sessions. Boost's generated `CLAUDE.md` points the agent to Boost's own skills.

**Second comparison** (stricter judges). This package's guideline makes the agent activate both skills:

| Setup | Mean | Ranked #1 (of 12) | Bugs per build | Cost per build |
|---|---|---|---|---|
| this skill | 7.10 | 4 | 3.83 | $2.42 |
| Boost | 5.82 | 0 | 4.50 | $2.10 |
| Boost + skill, no guideline | 6.54 | 2 | 3.25 | $2.13 |
| **Boost + this package** | **7.27** | **6** | 3.25 | $3.27 |

With the guideline, the skill activated in 6 of 6 sessions. Head to head, the package with Boost and the skill alone tied 6–6. Loading both skills does not weaken this one, but it costs about 35% more tokens.

## Queues, scheduler and commands

This round added jobs, workers, the scheduler and Artisan commands to the skill (`SKILL.md` §7, §7b and §7c, and the `queues-and-workers`, `scheduling` and `console-commands` references). It compares the skill with `main` (the version before the change) and with no skill.

**Method.** Builds ran on Sonnet, one fresh headless session each, with three builds per arm for each queue task. Every task has a hidden behavioural test file that the builder never sees, and its pass rate is the primary measure. Blind Opus judges scored two replicates, with two judges per task. The skill was revised only from the five target tasks. The two holdout tasks were never used to revise it, and the probe checks that a small task stays small. Seven earlier tasks with no queue work are the regression set: they check that the new text doesn't make the rest of the skill worse.

**Hidden tests** (3 builds per arm and task, 120 tests per arm):

| | No skill | `main` | This version |
|---|---|---|---|
| Passed | 40/40 (1 build per task) | 116/120 | 119/120 |

The hidden tests are near the ceiling for every arm, so they mainly catch broken builds. The one failure left is in queue-bugfix: one build in three, with either skill version, leaves a lock that a killed process holds too long.

**Judges** (mean overall score, round 3, replicates 1 and 2):

| Task set | `main` | Round 2 | This version |
|---|---|---|---|
| Target (5 tasks) | 7.50 / 6.97 | 6.68 / 7.54 | **7.88 / 8.05** |
| Holdout (2 tasks) | 6.45 / 6.42 | 7.72 / 7.70 | **8.22 / 8.25** |
| Probe (1 task) | 6.75 / 8.00 | 8.00 / 7.25 | 8.25 / 7.00 |
| Regression (7 tasks) | 7.41 / 6.93 | 7.89 / 7.98 | 7.52 / 7.58 |
| All 15 | 7.27 / 6.95 | 7.47 / 7.75 | **7.78 / 7.79** |

The final version ranked first on 13 and 15 of 30 judgements, against 6 and 4 for `main`. On the regression set it scores at or above `main` in both replicates. It scores about 0.4 below the round-2 builds there, and most of that gap is one task: both blog-crud builds put the `web` middleware group on the API routes. Neither skill version mentions that, and four fresh blog-crud builds didn't repeat it (they scored 8.15 and 8.30, level with round 2).

**What the rounds changed.** The judges' notes, checked against every build with grep, drove each revision:
- The first draft told builders to `Schedule::useCache('redis')`, and they hardcoded Redis, which breaks tests and local runs. The skill now says to choose the lock store by environment.
- Builders added `--force` and `--dry-run` to a cleanup that only the scheduler runs. The skill now keeps those guards for commands people run by hand.
- Builders scheduled nightly prunes at 01:00–02:00 UTC to stay out of the DST change window, which also moved them off the business timezone. The skill and `scheduling.md` now agree: when the task names a business timezone, use it, and run at 03:00 or later. Nightly prunes now run in that timezone at 03:00–03:30 in 3 of 3 builds, and no build invents a timezone the task didn't give.
- Rewriting the jobs section dropped two habits `main` had (`#[DeleteWhenMissingModels]`, `Retry-After` on 429s), and no version had covered a dispatcher whose state change is safe to repeat. With those three added back, `Retry-After` handling went from 0 to 3 of 3 shipment-sync builds, and all 3 claim the `markAsPaid()` transition with a conditional update.
- `Isolatable` takes its lock before `handle()`, so a support engineer's `--dry-run` was refused during the nightly run. All 3 export-purge builds now lock only around the deleting part.
- `#[UniqueFor]` equal to the schedule interval let an orphaned lock skip the next run. The skill now states the window (longer than the worst run, shorter than the interval), and 3 of 3 builds pick 3300 seconds for an hourly job.

**Non-regression checks.** Besides the regression tasks above:
- **Triggering:** on 22 prompts with Haiku (3 runs each, full user environment), this version loaded the skill on 42 of 42 should-trigger runs and 0 of 24 should-skip runs (`main`: 40/42 and 1/24). The six new prompts include a Symfony console command, a Node cron job and Celery, which should not trigger it.
- **Static checks** (`eval/static.py`, no LLM): over the regression tasks, every check is flat or lower than `main`, and Pint finds the same number of files to fix. Raw foreign-key arrays in tests are the one count that rose, and every hit was a legitimate test input.
- **Audit traps:** the audit-mode trap fixture scored the same as `main`. Both versions sometimes over-rewrite traps b and d. This change doesn't touch them, and they are tracked separately.
- **Cost:** builds cost the same with this version as with `main` ($0.38 vs $0.37 on average) and take about as long. The skill grew from 250 to 308 lines.

Raw judgements, mappings, per-build stats and hidden-test results are in [`eval/results/queues-round/`](../eval/results/queues-round). CI checks that the round 3 and round 4 tables still re-derive from them.

## Audit mode

Audit mode was checked against lists of problems that independent reviewers had already found in baseline apps:
- **Recall:** 6 of 11 on a queue-heavy app, including 3 real high-severity bugs the auditor reproduced; 7 of 12 on a CSV-import app.
- **Accuracy:** no false positives across any run.
- **Known gap:** test updates that a finding requires are sometimes described in prose instead of included as code.

## Skill triggering

The build prompts above never mention the skill, so the agent has to decide to load it. This test measures that decision on its own: does a fresh session call the `Skill` tool for `laravel-idioms` without being asked to?

**Method.** 12 prompts, 3 runs each, so 36 fresh `claude -p` sessions per row. 8 prompts should trigger the skill: review a model, review a Filament resource, plan a refactor, refactor a widget, build a feature, a performance question, write tests, assess codebase health. 4 should not: review a plain PHP controller, review a TypeScript file, an HTTP 429 question, a shell one-liner. The Laravel prompts ran in a larger Laravel 13 / Filament 5 ticketing app (tickets, replies, customers, a Filament resource and a stats widget). That app is not committed, so the table below can't be re-derived from this repo. `eval/trigger/fixture/laravel-app` is a much smaller stand-in with the same file names. Sessions were read-only (`Read`, `Grep`, `Glob` and `Skill`) and capped at 6 turns. A run counts as a hit when the model made a `Skill` call for `laravel-idioms`. The plugin was loaded with `claude --plugin-dir`.

**Results.** Should-trigger runs that loaded the skill, out of 24:

| Setup | Haiku 5.5 | Sonnet 5.5 |
|---|---|---|
| v0.2.0 description | 5/24 | 8/24 |
| New description | 13/24 | not run |
| v0.2.0 description + SessionStart hook | 22/24 | not run |
| New description + SessionStart hook | 23/24 | 24/24 |

The new description is trigger-first: it opens with the situations to use the skill in and names the Laravel signals in a project (an `artisan` file, `laravel/framework` in `composer.json`). The hook is the plugin's `SessionStart` hook. In a Laravel app whose `CLAUDE.md` and `AGENTS.md` don't already mention the skill, it adds a short note that points at the skill. See the README.

In every measured row, the should-not prompts loaded the skill 0 times out of 12.

With the new description and the hook, Sonnet loaded the skill in all 3 runs of every should-trigger prompt.

On the committed fixture, with `eval/trigger.py` as committed, Haiku 5.5, 3 runs per prompt:

| Prompt | v0.2.0 | v0.2.1 |
|---|---|---|
| Review a model | 3/3 | 3/3 |
| Review a Filament resource | 3/3 | 3/3 |
| Plan a refactor | 0/3 | 3/3 |
| Refactor a widget | 2/3 | 3/3 |
| Build a feature | 0/3 | 3/3 |
| Performance question | 0/3 | 3/3 |
| Write tests | 1/3 | 3/3 |
| Assess codebase health | 1/3 | 3/3 |
| **Should trigger** | **10/24** | **24/24** |
| Should not trigger | 0/12 | 0/12 |

The per-run results are in `eval/results/trigger/`. v0.2.0 does better here than in the first table (10/24 against 5/24). These sessions had only four tools and no MCP servers, so less competed with the skill. Both tables show the same gap: the v0.2.0 description triggers on "review" and "refactor" and rarely on anything else.

**Monorepos.** In a repository whose root is not the Laravel app, the hook looks one and two folders down. The `mono-*` prompts run in a repo that holds the fixture app as `backend/` and the TypeScript fixture as `frontend/`. Should-trigger runs (review a model, a performance question, build a feature) that loaded the skill, out of 9:

| Version | Haiku 5.5 | Sonnet 5.5 |
|---|---|---|
| v0.2.0 | 2/9 | 0/9 |
| New description, hook only at the root | 4/9 | not run |
| New description, hook with monorepo detection | 9/9 | 9/9 |

Reviewing `frontend/src/RequestContext.ts` in the same repo loaded the skill 0 times out of 3 in every row. The per-run results are in `eval/results/trigger/monorepo-*.json`.

With the v0.2.0 description on Sonnet, only the explicit review and refactor prompts triggered it. The plan, build, performance, test and assessment prompts did not trigger it in any of their 3 runs.

**Caveats.**
- The samples are small: 24 runs per cell, and 3 per prompt. A difference of one or two runs is noise.
- The runs were made in one user environment with about 15 other plugins installed, so the skill list was crowded. Another environment can give other rates. `eval/trigger.py --isolated` measures the isolated case, and its numbers are not comparable with the table.
- `eval/trigger.py` always passes `--strict-mcp-config` and `--tools Read,Grep,Glob,Skill`. The runs in the table did not pass `--strict-mcp-config`, so their sessions also had the MCP servers of that user environment.
- Runs stopped after 6 turns. A skill the model would have loaded on turn 7 is counted as a miss.
- The results are model-specific. Haiku and Sonnet behave differently, and the table says nothing about Opus or later releases.
- The prompts are short and plain. They were written for this test, and users phrase requests in other ways.
- A hit shows that the skill was loaded, not that the work improved. The earlier sections measure the effect of the skill.

## Reproduce

The harness is in [`eval/`](../eval): setup, headless builds, anonymisation, judging and aggregation. `eval/trigger.py` reruns the triggering test. The raw judgements and per-build stats behind the tables above are in [`eval/results/`](../eval/results). `eval/aggregate.py` re-derives the tables from them.

## Caveats

- Samples are small: one build per setup per task.
- The rubric's idiom dimensions encode the same values as the skill. Correctness and bug counts are the more neutral signals.
- The results cover Claude models only.
- In the published runs, judges worked next to the impl-to-arm mapping and the arm-named build directories, so they could have unblinded themselves. The judge transcripts show none did: none of the 24 judgements read the mapping, the session logs, the unblinded builds or a non-plain arm. The harness now stages judges in a separate tree and runs them in Claude Code's OS-level sandbox. They can read only that tree and the PHP toolchain, and a canary check aborts judging if the sandbox isn't enforced.
- In the published runs, both judges of a task worked in the same directories at the same time and wrote probe tests under the same file name. The transcripts show no overlapping probe windows on any build, and no probe was left behind. The harness now runs a task's judges one after another.
- The published judging ran as Claude Code workflow agents. `eval/judge.py` sends the same prompt through headless `claude -p` sessions.
