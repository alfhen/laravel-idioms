# Webhooks, security, Livewire, Inertia, errors and logging, dates and money round

Raw data behind the "Webhooks, security, Livewire, Inertia, errors and logging, dates and money" section of [docs/evaluation.md](../../../docs/evaluation.md).

| Arm | Skill text |
|---|---|
| `plain` | none |
| `prev` | `main` before this change (v0.3.0) |
| `skill` | first draft of the change (round 1) |
| `rev` | after the round-1 revisions: the text in this PR (round 2) |

Every arm built the same 22 tasks: the 10 tasks with `round: webhooks-security` in `eval/tasks.json` and 12 earlier tasks as the regression set. Target and holdout tasks have three builds per skill arm, the probe and the regression tasks two, and `plain` one build of each new task.

Judging rounds:
- `round1/rep1`, `round1/rep2`: `prev`, `skill` (and `plain` in replicate 1).
- `round2/rep1`, `round2/rep2`: `prev`, `rev` (and `plain` in replicate 1).

`hidden.json` holds the hidden behavioural test results per build, `builds.json` the per-build cost, time, skills fired and reference files read, `static.json` the `static.py` counts, and `misses.json` and `misses.md` the `misses.py` output (the misses were pre-registered from the pilot builds before any skill text was written). `trigger/` holds two full Haiku triggering rounds each for `main` and this branch, and `audit-scores.json` the audit trap runs (`main`, `head` = the draft, `final` = this PR). Each replicate's `judgements.json` holds its 44 judgements, and its `tables.md` is the expected `aggregate.py` output for its replicate, and CI checks it.
