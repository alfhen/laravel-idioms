# Redis and Valkey connections round (round 9)

Raw data behind the "Redis and Valkey connections" section of [docs/evaluation.md](../../../docs/evaluation.md).

| Arm | Skill text |
|---|---|
| `plain` | none |
| `prev` | v0.5.1 |
| `draft` | first draft, written from the research before any build and corrected after an Opus accuracy review |
| `rev1` | after revision 1; kept only for the three target tasks rebuilt with revision 2 |
| `skill` | after revision 2: the text in this PR. The three targets (valkey-cache, persistent-valkey, valkey-outage) were built with it; the holdout, probe and regression builds were built with revision 1, which differs only in Redis-connection text those tasks don't touch |

Every arm built the 12 tasks: the five with `round: redis` in `eval/tasks.json` and seven earlier tasks as the regression set. Targets and the holdout have three builds per skill arm, the probe and the regression tasks two, and `plain` one build of each new task.

Judging rounds (two blind Opus judges per task):
- `round1/rep1`: `prev`, `draft` and `plain`.
- `round2/rep1`, `round2/rep2`: `prev`, `rev1` (and `plain` in replicate 1).
- `round3/rep1`, `round3/rep2`: `prev`, `skill` (and `plain` in replicate 1). Only the three targets were judged again; the other tasks reuse round 2's judgements of the same builds.

`hidden.json` holds the hidden behavioural test results per build (the Valkey tasks ran against the stack in [eval/valkey/](../../valkey)), `builds.json` the per-build cost, time, skills fired and reference files read, and `static.json` the `static.py` counts for the final builds. Each replicate's `tables.md` is the expected `aggregate.py` output, which CI checks.

Four run-3 builds of the new tasks (valkey-cache, persistent-valkey, valkey-outage, valkey-queue, revision 1) were killed by the session (exit 143) and rebuilt once. Run 3 is not judged.
