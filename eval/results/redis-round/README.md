# Redis and Valkey connections round (round 9)

Raw data behind the "Redis and Valkey connections" section of [docs/evaluation.md](../../../docs/evaluation.md).

| Arm | Skill text |
|---|---|
| `plain` | none |
| `prev` | v0.5.1 |
| `draft` | first draft, written from the research before any build and corrected after an Opus accuracy review |
| `rev1` | after revision 1; kept for the three targets and the holdout, which were rebuilt with the final text |
| `skill` | the text in this PR. The targets were built with revision 2, and the holdout with revision 2 plus the post-review corrections listed in docs/evaluation.md. The probe and regression builds were built with revision 1; none of them read `references/redis.md`, so they differ only in the four Redis-scoped `SKILL.md` bullets |

Every arm built the 12 tasks: the five with `round: redis` in `eval/tasks.json` and seven earlier tasks as the regression set. Targets and the holdout have three builds per skill arm, the probe and the regression tasks two, and `plain` one build of each new task.

Judging rounds (two blind Opus judges per task):
- `round1/rep1`: `prev`, `draft` and `plain`.
- `round2/rep1`, `round2/rep2`: `prev`, `rev1` (and `plain` in replicate 1).
- `round3/rep1`, `round3/rep2`: `prev`, `skill` (and `plain` in replicate 1). The targets and the holdout were judged again; the probe and regression tasks reuse round 2's judgements of the same builds.

`hidden.json` holds the hidden behavioural test results per build (the Valkey tasks ran against the stack in [eval/valkey/](../../valkey)), `builds.json` the per-build cost, time, skills fired and reference files read, and `static.json` the `static.py` counts for the final builds. Each replicate's `tables.md` is the expected `aggregate.py` output, which CI checks.

Four run-3 builds of the new tasks (valkey-cache, persistent-valkey, valkey-outage, valkey-queue, revision 1) were killed by the session (exit 143) and rebuilt once. Run 3 is not judged.
