# Queues, scheduler and commands round

Raw data behind the "Queues, scheduler and commands" section of [docs/evaluation.md](../../../docs/evaluation.md).

| Arm | Skill text |
|---|---|
| `plain` | none |
| `prev` | `main` before this change |
| `draft` | first draft of the change (round 1) |
| `mid` | after the round-1 revisions (round 2) |
| `skill` | after the round-2 revisions (round 3); for blog-crud, export-purge-command and inactive-count the round-3 wording (round 4); for scheduled-reports the final wording |
| `late` | round-3 builds of the four tasks rebuilt in round 4 |
| `four` | round-4 builds of scheduled-reports |

Judging rounds:
- `round1/`: `prev`, `draft` (named `skill` in that mapping) and `plain`, replicate 1.
- `round2/`: `prev`, `mid` (named `skill` in that mapping), `draft` and `plain`, replicate 1.
- `round3/rep1`, `round3/rep2`: `prev`, `mid` and the round-3 skill over all 15 tasks, replicates 1 and 2. In these mappings `skill` means the round-3 builds, which `builds.json` calls `late` for the four tasks rebuilt later.
- `round4/rep1`, `round4/rep2`: `prev`, `late` and `skill` on the four tasks the round-3 revisions touched.

`hidden.json` holds the hidden behavioural test results per build, `builds.json` the per-build cost, time, skills fired and reference files read, and `static.json` the `static.py` counts from round 2. `trigger/` holds the Haiku triggering runs for `main` and this branch, and `audit-*` the audit trap runs. Each `tables.md` is the expected `aggregate.py` output for its replicate, and CI checks it.
