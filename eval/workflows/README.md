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
eval/base, base-pest, base-filament   the base apps built by ../setup.sh
skill/laravel-idioms     the skill being written and revised
```

Unlike `../build.py`, these builders were workflow sub-agents told to read `SKILL.md`, not headless sessions that discover the skill themselves. The Boost comparisons used the headless harness instead.
