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

### Claude Code plugin

For Claude Code without Boost, install the repo as a plugin:

```
/plugin install laravel-idioms --marketplace alfhen/laravel-idioms
```

Claude Code asks you to confirm the new marketplace, then opens the plugin's details so you can choose a scope.

On older versions of Claude Code, add the marketplace first and then install:

```
/plugin marketplace add alfhen/laravel-idioms
/plugin install laravel-idioms@alfhen
```

The skill loads as `laravel-idioms:laravel-idioms`. If you copied the folder into `~/.claude/skills/laravel-idioms` before, remove that copy. Otherwise both load.

The plugin also adds a `SessionStart` hook. When a session starts in a Laravel app, it adds a short note that says to use this skill when you review, plan, build, refactor, debug or test the code, even if your request never says "Laravel". Without the note, the agent often skips the skill unless you ask for a review or a refactor ([measurements](docs/evaluation.md#skill-triggering)). The hook adds the note only if the project has an `artisan` file, `laravel/framework` in `composer.json` and no `boost.json`. Anywhere else it prints nothing. On Windows the hook needs Git Bash. Without it, the hook does not run, and the skill still works. To turn the hook off, disable the plugin or set `"disableAllHooks": true` in your settings. That setting turns off every hook, not only this one. A change takes effect in a new session or after `/reload-plugins`.

Auto-update is off by default for third-party marketplaces. A new version is published when a release bumps `version` in `.claude-plugin/plugin.json` on `main`; a tag alone publishes nothing. To update, run these in a terminal, then restart Claude Code or run `/reload-plugins`:

```bash
claude plugin marketplace update alfhen
claude plugin update laravel-idioms@alfhen
```

Projects that use Boost should keep the Composer route above. The plugin installs the skill but not the guideline in `resources/boost/guidelines/core.md`, and Boost already copies the skill into `.claude/skills/`, so adding the plugin would load it twice.

### Copy the folder (Claude Code)

If you can't use plugins, copy `resources/boost/skills/laravel-idioms` into your project's `.claude/skills/` directory, or into `~/.claude/skills/` to use it in every project.

## What's inside

```
.claude-plugin/
├── plugin.json                         # Claude Code plugin manifest
└── marketplace.json                    # makes this repo its own marketplace
hooks/
├── hooks.json                          # plugin SessionStart hook
└── laravel-context.sh                  # adds the skill pointer in Laravel apps without Boost
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
