# laravel-idioms

An agent skill that makes AI coding agents write Laravel the way the framework, its docs and well-maintained packages do. It covers thin controllers, Form Requests for every input, policies, collections instead of accumulator loops, Eloquent `casts()`/scopes/attributes, N+1 prevention, queues and workers, caching, Blade components, Filament 5, Pest 5, and the current Laravel 11–13 app structure. It ends every change with a mandatory self-check list.

It also has an **audit mode**. Ask the agent to "audit this Laravel codebase" and it maps the app and hunts for real bugs as well as idiom problems. It verifies its suggested fixes by applying them to a scratch copy and running the test suite, and then writes a self-contained HTML refactor report.

It works on its own, and it works alongside [Laravel Boost](https://github.com/laravel/boost). With Boost, the package adds a guideline so the agent loads this skill together with Boost's `laravel-best-practices`.

## Install

### With Laravel Boost (recommended)

```bash
composer require alfhen/laravel-idioms --dev
php artisan boost:install
```

Boost's third-party guidelines are opt-in. When `boost:install` asks *"Which third-party AI guidelines/skills would you like to install?"*, select `alfhen/laravel-idioms`. For non-interactive installs, add the package to `boost.json` first:

```json
{
    "packages": ["alfhen/laravel-idioms"],
    "skills": ["laravel-idioms", "...your other skills"]
}
```

This installs the skill for every agent Boost manages. It also merges a short guideline into Boost's generated `CLAUDE.md`/`AGENTS.md` that tells the agent to activate `laravel-idioms` alongside `laravel-best-practices`. Without that guideline, the agent usually picks Boost's skill and ignores this one.

### Skill only, via Boost

```bash
php artisan boost:add-skill alfhen/laravel-idioms
```

This installs the skill but not the guideline, so in a Boost project the agent may not activate it reliably. Prefer the Composer route.

### Without Boost (Claude Code)

Copy `resources/boost/skills/laravel-idioms` into your project's `.claude/skills/` directory, or into `~/.claude/skills/` to use it in every project.

## What's inside

```
resources/boost/
├── guidelines/core.md                  # merged into Boost's CLAUDE.md/AGENTS.md
└── skills/laravel-idioms/
    ├── SKILL.md                        # core rules + "Before you finish" checklist
    ├── references/                     # loaded on demand
    │   ├── http-layer.md  eloquent.md  collections-and-helpers.md
    │   ├── architecture-and-testing.md  queues-and-workers.md  caching.md
    │   ├── blade.md  filament.md  pest.md  modern-laravel.md  style.md
    │   └── audit-mode.md
    └── assets/audit-report-template.html
```

The skill targets **Laravel 11–13, Pest 5 and Filament 5**. It checks the installed versions in `composer.lock` and follows the project's existing conventions before applying its own defaults.

## Does it help?

It was measured with blind A/B tests. Claude Sonnet built the same features in fresh Laravel 13 apps with and without the skill, and Claude Opus judges scored the anonymised results. Every build passed its own tests.

| Setup | Mean judge score (1–10) |
|---|---|
| Laravel Boost only | 5.82 |
| This skill only | 7.10 |
| Boost + this package (guideline + skill) | **7.27** |

This is a small sample: 6 tasks, one build per setup, two judges each. The rubric also reflects the conventions the skill teaches. See [docs/evaluation.md](docs/evaluation.md) for the method, all rounds and the caveats. The harness and raw results are in [`eval/`](eval), so you can rerun it against your own tasks.

## Credits

The rules were distilled from reading the source and documentation of [laravel/framework](https://github.com/laravel/framework), [laravel/docs](https://github.com/laravel/docs), [laravel.io](https://github.com/laravelio/laravel.io), [Spatie's Laravel & PHP guidelines](https://spatie.be/guidelines/laravel-php) and packages, [Pest](https://github.com/pestphp/pest), [Filament](https://github.com/filamentphp/filament) and [Horizon](https://github.com/laravel/horizon). The rules are paraphrased; evidence paths in the reference files point to those repositories. Not affiliated with Laravel or Spatie.

## License

MIT
