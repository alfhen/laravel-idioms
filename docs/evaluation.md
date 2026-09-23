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

## Audit mode

Audit mode was checked against lists of problems that independent reviewers had already found in baseline apps:
- **Recall:** 6 of 11 on a queue-heavy app, including 3 real high-severity bugs the auditor reproduced; 7 of 12 on a CSV-import app.
- **Accuracy:** no false positives across any run.
- **Known gap:** test updates that a finding requires are sometimes described in prose instead of included as code.

## Reproduce

The harness is in [`eval/`](../eval): setup, headless builds, anonymisation, judging and aggregation. The raw judgements and per-build stats behind the tables above are in [`eval/results/`](../eval/results). `eval/aggregate.py` re-derives the tables from them.

## Caveats

- Samples are small: one build per setup per task.
- The rubric's idiom dimensions encode the same values as the skill. Correctness and bug counts are the more neutral signals.
- The results cover Claude models only.
- In the published runs, judges worked next to the impl-to-arm mapping and the arm-named build directories, so they could have unblinded themselves. The judge transcripts show none did: none of the 24 judgements read the mapping, the session logs, the unblinded builds or a non-plain arm. The harness now stages judges in a separate tree that contains none of those.
- The published judging ran as Claude Code workflow agents. `eval/judge.py` sends the same prompt through headless `claude -p` sessions.
