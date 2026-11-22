# Installed packages and trust boundaries round (v0.5.0)

Raw data behind the "Installed packages and trust boundaries" section of [docs/evaluation.md](../../../docs/evaluation.md).

| Arm | Skill text |
|---|---|
| `plain` | none |
| `prev` | v0.4.0 |
| `draft` | first draft of the change, written from the research before the pilot (commit `25e4c03`) |
| `rev1` | after revision 1 (commit `a774c3d`); kept only for the three tasks rebuilt with revision 2 |
| `skill` | after revision 2: the text in this PR. payment-states, block-user and password-reset-links were built with it; the other 19 tasks were built with revision 1, which differs only in one sentence on mail inside a transaction |

Every arm built the same 22 tasks: the 10 tasks with `round: packages-trust` in `eval/tasks.json` and 12 earlier tasks as the regression set. Target and holdout tasks have three builds per skill arm, the probe and the regression tasks two, and `plain` one build of each new task.

Judging rounds:
- `round1/rep1`, `round1/rep2`: `prev`, `draft` (and `plain` in replicate 1).
- `round2/rep1`, `round2/rep2`: `prev`, `skill` (and `plain` in replicate 1). The three tasks rebuilt with revision 2 were judged again in both replicates.

`hidden.json` holds the hidden behavioural test results per build, `builds.json` the per-build cost, time, skills fired and reference files read, `static.json` the `static.py` counts, and `misses.json` and `misses.md` the `misses.py` output (round-8 misses were pre-registered from the `prev` and `plain` pilot builds before any skill build was read). `audit-scores.json` holds the audit trap runs (`v040` and `head`). Each replicate's `judgements.json` holds its 44 judgements, and its `tables.md` is the expected `aggregate.py` output, which CI checks.

Six builds were killed by the session (exit 143) and rebuilt once: notes-search-api draft build 2, and notes-search-api build 1 and sales-report, blog-crud, blade-catalogue and filament-admin build 2 of the `skill` arm. Their tasks were judged again in the affected replicate. Every build in `builds.json` is the completed one.
