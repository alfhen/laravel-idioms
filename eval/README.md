# Evaluation harness

This harness reproduces the blind comparisons in [docs/evaluation.md](../docs/evaluation.md). Claude Sonnet builds the same features in fresh Laravel apps under different setups ("arms"), and Claude Opus judges score the anonymised results.

| Arm | What the building agent gets |
|---|---|
| `plain` | Nothing but the Laravel skeleton |
| `skill` | This skill in the project's `.claude/skills/` |
| `prev` | The skill as it was at `$PREV_REF` (default: the latest `v*` tag), to compare a change against a release |
| `boost` | Laravel Boost: guidelines, its skills and its MCP server |
| `both` | Boost, plus this skill copied into `.claude/skills/` (no guideline) |
| `augment` | Boost, plus this package installed through Composer (guideline + skill) |

## Requirements

- PHP 8.3+ and Composer
- `rsync`, Python 3 with PyYAML (only CI needs PyYAML)
- The [Claude Code](https://docs.claude.com/en/docs/claude-code) CLI, logged in. Builds run `claude -p` with `--dangerously-skip-permissions` inside throwaway app copies under `$WORK`.

## Run

```bash
export WORK=$PWD/eval/.work          # default; about 5 GB for all arms and runs
export JUDGE=$PWD/eval/.work-judge   # default; the only tree judges can read

eval/setup.sh                        # base apps + one copy per arm and base
python3 eval/build.py --arms skill,boost,augment --tasks sales-report,blog-crud
python3 eval/anonymise.py --arms skill,boost,augment --tasks sales-report,blog-crud
python3 eval/judge.py --judges 2
python3 eval/aggregate.py
```

A change to the skill is compared with the released version, with replicate builds and hidden tests:

```bash
ARMS="plain skill prev" BASES="base base-pest base-queue-bugfix" PREV_REF=main eval/setup.sh
python3 eval/build.py --arms skill,prev --runs 3 --tasks shipment-sync,statement-batch,scheduled-reports,export-purge-command,webhook-worker,stock-sync-dispatcher,queue-bugfix
python3 eval/build.py --arms plain --runs 1 --tasks shipment-sync,statement-batch,scheduled-reports,export-purge-command,webhook-worker,stock-sync-dispatcher,queue-bugfix
python3 eval/hidden.py
python3 eval/static.py
python3 eval/anonymise.py --arms plain,prev,skill --replicate 1 --tasks shipment-sync,statement-batch
python3 eval/judge.py --judges 2
python3 eval/aggregate.py
```

- **`setup.sh`** creates `base` (PHPUnit), `base-pest` (Pest), `base-filament` (Pest + Filament), `base-queue-bugfix` (Pest + the seeded app in `fixtures/queue-bugfix/`), `base-api` and `base-api-phpunit` (Pest or PHPUnit with `install:api` run and `HasApiTokens` on `User`, so every arm starts from the same Sanctum install) and `base-orders-schema` (`base-api-phpunit` + the legacy orders app in `fixtures/orders-schema/`) from the latest `laravel/laravel` 13.x, then builds every arm on each base. `ARMS` and `BASES` (space-separated) limit what it builds; `PREV_REF` picks the git ref for the `prev` arm. It removes the skeleton's stock `CLAUDE.md`/`AGENTS.md`, because those tell agents to install Boost and would turn every arm into a Boost arm.
- **`build.py`** runs one headless session per task and arm. `--setting-sources project,local` keeps your user-level skills and settings out. `--strict-mcp-config` keeps your MCP servers out, so only Boost arms get Boost's server. The prompt is the same for every arm and never mentions a skill or Boost. Session logs go to `$WORK/runs/_logs/`; each build's `.done` file records which skills fired and which skill files the session read. `--runs N` builds each (task, arm) N times, as `<task>-<arm>-<n>`.
- **`anonymise.py`** stages the judges' tree at `$JUDGE`, which defaults to a sibling of `$WORK`:
  - `tasks/<task>/impl-N` holds each build with every file that would reveal the arm stripped, and Boost and this package uninstalled;
  - `bases/<base>` holds the plain base apps to diff against.

  `--replicate N` stages build `<task>-<arm>-<N>` of each arm. `tests/Hidden` is stripped, and staging fails if a build still mentions the skill by name (for example in a comment or README).

  Nothing under `$JUDGE` points back to the arm-named builds, the session logs or the impl-to-arm mapping. Those stay in `$WORK`, and the mapping is written to `$WORK/judge-mapping.json`.
- **`judge.py`** runs one Opus session per task and judge. Each judge reviews every impl in a rotated order, then scores and ranks them against a fixed JSON schema. Tasks run in parallel. Judges of one task run one after another, because their probe tests share the impl directories.

  Judges run in Claude Code's OS-level [sandbox](https://docs.claude.com/en/docs/claude-code/sandboxing) (Seatbelt on macOS, bubblewrap on Linux), with Bash as their only tool so every file access goes through it:
  - they can read and write `$JUDGE`, and read the PHP toolchain;
  - they cannot read the rest of the home directory, `$WORK`, or this repo, which includes the published mappings;
  - Claude Code refuses to start if the sandbox is unavailable (`failIfUnavailable`) instead of warning and running unsandboxed;
  - blocked commands can't be retried outside the sandbox.

  Before any judge starts, a cheap Haiku canary session is asked to `cat` a random token planted in `$WORK`. Judging proceeds only if the session exits cleanly, the token never appears, and its event stream shows the `cat` of that file actually ran and was refused by the OS. A session that skips the command counts as a failure, not a pass.
  - If PHP lives somewhere the script doesn't detect, add its directory to `JUDGE_ALLOW_READ` (colon-separated).
  - Don't put `$JUDGE` under `~/.claude`: the sandbox write-protects it, so tests can't run there.
  - On Linux, install `bubblewrap` and `socat` first.
- **`hidden.py`** runs each task's hidden tests (`hidden/<Name>HiddenTest.php`, a PHPUnit class that Pest also runs) against a copy of every build of that task, and writes `$WORK/hidden-results.json`. The tests check behaviour through the interface the brief pins (class, command and table names), not which APIs a build used, so any correct design passes. A build where the file can't run at all (a missing class, a fatal error) fails every hidden test. `test_hidden.py` checks the parsing without PHP.
- **`aggregate.py`** prints the per-arm and per-task tables, the hidden-test pass rates when `hidden-results.json` exists, and how many builds read a skill reference. When any hidden test is named `…guest_without_accept…`, it also prints that shared check on its own row and the overall rate without it: one `bootstrap/app.php` line flips it in every API task, so it must not carry a comparison on its own.
- **`static.py`** counts checklist violations in every build with regexes and Pint, without an LLM (`request->all()` persisted, `DB::` in controllers, `{!! !!}`, Filament v3 namespaces, a bare `withoutOverlapping()`, a hardcoded `redis` store, `chunk()` instead of `chunkById()`, a caught exception's `getMessage()` in a response, `Http::` calls without `timeout()`, `retry()` without `when`, client file names stored, `Mail::to($users)`, models queried in migrations, and more), plus counts of `lockForUpdate`, `Cache::lock` and idempotency keys to spot over-application on small tasks. It prints the mean count per build for each arm and writes `$WORK/static-results.json`. A rise in a row is something to look at, not a verdict, because some hits are legitimate: a test that posts a forged `user_id` to prove it is ignored matches the raw-FK check.

`tasks.json` holds the task briefs. Besides `base`, `pest`, `focus` and `text`, a task can have:

| Field | Meaning |
|---|---|
| `set` | `target` (measures the change), `holdout` (never read while revising the skill), `probe` (where the right answer is small; judged on simplicity), `regression`, or `archive` (earlier rounds) |
| `hidden` | the hidden test file for `hidden.py` |
| `skip_dimensions` | judge dimensions that don't apply, such as `thin_controllers` for a task with no HTTP layer |
| `round` | the round whose `set` labels these are (`queues`, `races-api-files`); a later round uses earlier tasks as its regression set |

Write `focus` as the behaviour you want ("no duplicate processing"), not as API names, because it reaches the judges verbatim. The Boost comparisons used `blade-catalogue`, `filament-admin`, `caching`, `webhook-worker`, `sales-report` and `blog-crud`. The queue round used the `target`, `holdout`, `probe` and `regression` sets. The races/API/files round used the tasks with `round: races-api-files` as its target, holdout and probe, and 13 earlier tasks as its regression set. Before any build, every hidden test was run against two independent correct reference builds per task, so a test can't reject a correct design.

## Audit mode

The builds above never exercise audit mode. `audit.py` does: it runs the skill's audit on a fixture app with planted traps and scores the report it writes.

```bash
python3 eval/audit.py setup                                   # needs $WORK/arms/plain-base from setup.sh
python3 eval/audit.py run --skill resources/boost/skills/laravel-idioms --label head --runs 2
python3 eval/audit.py run --skill /path/to/older/laravel-idioms --label old --runs 2
python3 eval/audit.py score
```

- **`setup`** overlays `eval/audit/fixture/` on the PHPUnit base app and installs Larastan. It refuses to continue unless the fixture's tests pass and PHPStan level 8 reports nothing.
- **`run`** starts one headless session per run, like `build.py`, with the given skill in `.claude/skills/`. The prompt asks for an audit and for the report's JSON as `audit.json`; it never mentions the skill.
- **`score`** prints one row per trap and one column per run, and writes the details to `$WORK/audit/scores.json`. For traps a, b and d it also applies each matching `suggested` block to a fresh fixture copy and runs PHPStan and the tests. `python3 eval/test_audit.py` checks the matching and verdict logic without PHP.

A finding matches a trap when its `file` or one of its `locations` names the trap's file, after dropping anything from the first `:`, `#` or `(` (a Windows drive letter is kept) and any leading `./`. Absolute paths count only inside the run's directory. Verdicts for traps a, b and d:

| Verdict | Meaning |
|---|---|
| `pass (left alone)` | no finding touches the trap |
| `pass (flagged safely, verified)` | every matching finding has a complete suggestion, and each applied cleanly with PHPStan and the tests still passing |
| `pass (flagged safely, not verified: …)` | either PHPStan produced no result for an applied rewrite, or the named findings have no complete suggestion (blank `current`, or a `suggested` with nothing but imports and comments; an import is an unindented `use` line before any code, and any other `use` is a trait use and counts as code); everything else applied cleanly |
| `UNVERIFIED (rewrite not applicable)` | at least one complete suggestion's `current` isn't in the file, so it couldn't be tested |
| `FAIL` | a suggestion matches the trap's bad pattern (it is still applied, for the evidence) |
| `FAIL (rewrite breaks …)` | an applied suggestion makes PHPStan or the tests fail |

Traps c and e are never applied, so a finding there reads `pass (flagged safely)` or `FAIL`.

| Trap | Passes when the audit |
|---|---|
| a | leaves `return array_keys(array_filter(...))` in a `list<string>` method alone, or offers a form without a `@var` temp |
| b | rewrites the `in_array(..., true)` dedupe loop, if at all, without `unique()` or a callback-less `filter()` (the tests feed it `'0'` and `'00'`) |
| c | doesn't "upgrade" `array_unique()` over product codes to `unique()`/`uniqueStrict()` |
| d | keeps `scopeAccepted()`, because a resource reads `accepted` on rows that didn't select it |
| e | doesn't report an unregistered `TrimStrings` stub, whose `$except` equals the framework default, as a behaviour bug |
| f1–f3 | flags the keyed accumulate-in-foreach, the private list property built in a loop (without a `@var` temp), and `$request->all()` into `create()` |

Verdicts are regex checks on the findings, so read `scores.json` before trusting a FAIL on e.

## Skill triggering

`trigger.py` checks whether a fresh session loads the skill without being told to. It needs only the Claude Code CLI, not PHP.

```bash
python3 eval/trigger.py --model haiku --runs 3                    # the repo as the plugin
python3 eval/trigger.py --model sonnet --plugin-dir /path/to/other/checkout --json trigger.json
python3 eval/test_trigger.py                                      # checks the parsing and prompt set, no claude
```

It runs the 30 prompts in `trigger/prompts.json` `--runs` times each. Nineteen should load the skill: review a model, review a Filament resource, plan a refactor, refactor a widget, build a feature, a performance question, write tests, assess codebase health, set up a scheduled task, write an Artisan command, a queue worker question, an API that returns 500 to guests, a foreign key on a big table, file uploads, a hanging HTTP call, a race between two users, and three of those again in a monorepo. Eleven must not: a plain PHP controller, a Symfony Console command, a TypeScript file (on its own and in the monorepo), cron for a Node app, a Celery question, Django migrations, an Express upload, a curl option, an HTTP 429 question, a shell one-liner. A run counts as a trigger when the model calls the `Skill` tool for `laravel-idioms` or `laravel-idioms:laravel-idioms`. The table shows, per prompt, how many runs triggered.

| Flag | Meaning |
|---|---|
| `--plugin-dir DIR` | The plugin to test. Default: this repo. To compare versions, point it at a checkout of each. To test the description without the SessionStart hook, point it at a copy with `hooks/` deleted. |
| `--model` | Default `sonnet`. |
| `--runs` | Runs per prompt. Default 3. |
| `--parallel` | Sessions at once. Default 4. |
| `--isolated` | Adds `--setting-sources project,local`, so your user-level settings and plugins stay out. MCP servers are always left out (`--strict-mcp-config`). |
| `--app DIR` | A Laravel app for the Laravel prompts. Default: the tiny app in `trigger/fixture/laravel-app`. The prompts name `Ticket`, `TicketResource`, `TicketStatsWidget` and `Customer`, so an app without them still runs, but the agent has less to read. |
| `--only ID,ID` | Run only these prompt ids. |
| `--json FILE`, `--logs DIR` | Write per-run results as JSON, or keep each session's raw stream-json. |

Sessions are read-only. `--tools Read,Grep,Glob,Skill` limits them to those four tools, and each run stops after 6 turns, so a skill loaded later is not counted. The PHP and TypeScript prompts run in `trigger/fixture/php-app` and `trigger/fixture/ts-app`. The `mono-*` prompts run in a repository whose root holds the Laravel app as `backend/` and the TypeScript app as `frontend/`; an `--app` is linked in as `backend/`. Every other prompt, including the three general ones (HTTP 429, the shell one-liner, Celery), runs in the Laravel app. The script copies the committed fixtures out of the repo first, so the session's project root is the fixture and not this checkout. An `--app` is used in place; the sessions only read it.

A run that crashes, hits an API error or never loads the plugin is listed as an error and left out of the fractions. The script then exits with 1. A wrong `--plugin-dir` shows up as errors, not as a row of zeros.

**Isolation changes the result.** The numbers in [docs/evaluation.md](../docs/evaluation.md) were measured without `--isolated`, in a full user environment with about 15 other plugins installed. Whether the model picks this skill depends on what else is in the skill list, so a crowded environment and an isolated one give different rates. Compare runs only against others made the same way.

Cost: a 36-run round (12 prompts, 3 runs) cost about $0.50 with Haiku 5.5 and $6–8 with Sonnet 5.5 in a crowded environment. With `--tools` and `--strict-mcp-config` the sessions are smaller and cheaper.

## Cost

At API prices, builds cost about $2–3.30 each with Sonnet. A 4-impl judgement with Opus cost about $1 in a test run. A 6-task, 4-arm comparison with 2 judges per task is 24 builds and 12 judgements, roughly $70–80, and takes about an hour at the default concurrency. The queue tasks are smaller: about $0.40 and 2 minutes per build, and about $0.70 per 3-impl Opus judgement, so judging one replicate of all 15 tasks with 2 judges costs about $22.

## Published results

`results/boost-comparison/`, `results/augment-comparison/` and `results/queues-round/` (see its README for which arm is which build) hold the judgements, impl-to-arm mappings and per-build stats (cost, time, skills fired, MCP tools used) behind the published tables. `tables.md` in each is the expected `aggregate.py` output; CI fails if the committed data no longer produces it. To re-derive a table:

```bash
python3 eval/aggregate.py \
  --judgements eval/results/augment-comparison/judgements.json \
  --mapping eval/results/augment-comparison/mapping.json \
  --builds eval/results/augment-comparison/builds.json
```

`workflows/` holds the Claude Code workflow scripts that researched, wrote and A/B-tested the skill in rounds 1–3; see its README.
