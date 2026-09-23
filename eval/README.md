# Evaluation harness

This harness reproduces the blind comparisons in [docs/evaluation.md](../docs/evaluation.md). Claude Sonnet builds the same features in fresh Laravel apps under different setups ("arms"), and Claude Opus judges score the anonymised results.

| Arm | What the building agent gets |
|---|---|
| `plain` | Nothing but the Laravel skeleton |
| `skill` | This skill in the project's `.claude/skills/` |
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

- **`setup.sh`** creates `base` (PHPUnit), `base-pest` (Pest) and `base-filament` (Pest + Filament) from the latest `laravel/laravel` 13.x, then builds every arm on each base. It removes the skeleton's stock `CLAUDE.md`/`AGENTS.md`, because those tell agents to install Boost and would turn every arm into a Boost arm.
- **`build.py`** runs one headless session per task and arm. `--setting-sources project,local` keeps your user-level skills and settings out. `--strict-mcp-config` keeps your MCP servers out, so only Boost arms get Boost's server. The prompt is the same for every arm and never mentions a skill or Boost. Session logs, including which skills fired, go to `$WORK/runs/_logs/`.
- **`anonymise.py`** stages the judges' tree at `$JUDGE`, which defaults to a sibling of `$WORK`:
  - `tasks/<task>/impl-N` holds each build with every file that would reveal the arm stripped, and Boost and this package uninstalled;
  - `bases/<base>` holds the plain base apps to diff against.

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
- **`aggregate.py`** prints the per-arm and per-task tables.

`tasks.json` holds the eight task briefs. The Boost comparisons used `blade-catalogue`, `filament-admin`, `caching`, `webhook-worker`, `sales-report` and `blog-crud`.

## Cost

At API prices, builds cost about $2–3.30 each with Sonnet. A 4-impl judgement with Opus cost about $1 in a test run. A 6-task, 4-arm comparison with 2 judges per task is 24 builds and 12 judgements, roughly $70–80, and takes about an hour at the default concurrency.

## Published results

`results/boost-comparison/` and `results/augment-comparison/` hold the judgements, impl-to-arm mappings and per-build stats (cost, time, skills fired, MCP tools used) behind the published tables. `tables.md` in each is the expected `aggregate.py` output; CI fails if the committed data no longer produces it. To re-derive a table:

```bash
python3 eval/aggregate.py \
  --judgements eval/results/augment-comparison/judgements.json \
  --mapping eval/results/augment-comparison/mapping.json \
  --builds eval/results/augment-comparison/builds.json
```

`workflows/` holds the Claude Code workflow scripts that researched, wrote and A/B-tested the skill in rounds 1–3; see its README.
