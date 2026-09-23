# Workflow scripts (rounds 1–3)

These are the [Claude Code workflow](https://docs.claude.com/en/docs/claude-code) scripts that produced the skill. Each one reads real source code, writes or extends the skill, then A/B-tests it: Sonnet sub-agents build tasks with and without the skill, and blind judges compare the results. If a round doesn't clear the bar, the script revises the skill and runs another round.

| Script | Produced |
|---|---|
| `build-and-ab-test.workflow.js` | v1–v2: research over the Laravel, laravel.io and Spatie sources, first skill, rounds 1–2, audit-mode check |
| `extend-and-ab-test.workflow.js` | v4: Pest, Blade, Filament 5, caching and queues/workers research, round 3 (new tasks + regression check) |

They are kept as a record of how the skill was made. They expect this layout under the `root` passed as the workflow's `args` (`{"root": "/path/to/dir"}`):

```
sources/                 shallow clones: laravel_framework, laravel_docs, laravel_laravel, laravelio_laravel.io,
                         spatie_* (guidelines.spatie.be, laravel-data, -query-builder, -permission, -medialibrary,
                         -activitylog, -package-tools, -settings), pestphp_pest, pestphp_pest-plugin-laravel,
                         pestphp_docs, filamentphp_filament (5.x branch), filamentphp_demo, laravel_horizon,
                         laravel_livewire-starter-kit
eval/base, base-pest, base-filament   the base apps
skill/laravel-idioms     the skill being written and revised
```

To create the base apps at those paths, run `WORK=<root>/eval eval/setup.sh`. `setup.sh` writes `base`, `base-pest` and `base-filament` directly under `$WORK`. It also writes an `arms/` directory there, which these scripts don't use.

Both scripts write builds to `<root>/eval/runs/r<round>/`. `extend-and-ab-test` starts at round 3, which is safe after `build-and-ab-test` has used at most two rounds, as it did here. The first script can run a third round if round 2 doesn't clear its bar, so use a fresh `root` for the second script if that happens: a builder skips copying into a directory that already exists. The scripts are left unchanged, as the record of what ran.

Unlike `../build.py`, these builders were workflow sub-agents told to read `SKILL.md`, not headless sessions that discover the skill themselves. The Boost comparisons used the headless harness instead.
