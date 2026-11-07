# Races, API auth, files, schema, HTTP client and events round

Raw data behind the "Races, API auth, files, schema, HTTP client and events" section of [docs/evaluation.md](../../../docs/evaluation.md).

| Arm | Skill text |
|---|---|
| `plain` | none |
| `prev` | `main` before this change (the queue round's skill) |
| `draft` | first draft of the change (round 1) |
| `mid` | after the round-1 revisions (round 2) |
| `skill` | after the round-2 revisions: the text in this PR (round 3) |

Every arm built the same 23 tasks: the 10 tasks with `round: races-api-files` in `eval/tasks.json` and 13 earlier tasks as the regression set. Target and holdout tasks have three builds per skill arm, the probe and the regression tasks two, and `plain` one build of each new task.

Judging rounds:
- `round1/rep1`: `prev`, `draft` and `plain`, replicate 1.
- `round2/rep1`, `round2/rep2`: `prev`, `mid` (and `plain` in replicate 1).
- `round3/rep1`, `round3/rep2`: `prev`, `skill` (and `plain` in replicate 1).

`hidden.json` holds the hidden behavioural test results per build, `builds.json` the per-build cost, time, skills fired and reference files read, and `static.json` the `static.py` counts. `trigger/` holds two full Haiku triggering rounds each for `main` and this branch, and `audit-scores.json` the audit trap runs (`main`, `head` = the draft, `final` = this PR). Each `tables.md` is the expected `aggregate.py` output for its replicate, and CI checks it.
