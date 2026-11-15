# Out-of-sample replicate

A third, independent set of builds on the eight tasks behind the pre-registered misses and the new invoice checks, made after the round was merged. It answers two review points: `main`'s miss count in the round was in-sample (the misses were read from its own pilot builds), and the review fixes made after the judged text were never rebuilt.

| Arm | Skill text |
|---|---|
| `prev` | v0.3.0, the release before this round |
| `skill` | commit `30c9be2`: the merged round plus its review fixes |

Three builds per task and arm. No judges ran on this replicate.

- `misses.md` and `misses.json`: `misses.py` at `30c9be2`, with the tightened detectors. The round's own counts came from the earlier script.
- `hidden.json`: the hidden tests at this commit, including the new invoice-totals and webhook checks. One case first added here was removed after it rejected correct builds: a price with a trailing `\n` is trimmed by the global `TrimStrings` middleware, so a build that accepts it is right.
- `builds.json`: per-build cost, time, skills fired and reference files read.
