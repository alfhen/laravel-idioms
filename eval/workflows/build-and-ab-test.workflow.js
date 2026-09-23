export const meta = {
  name: 'laravel-idioms-skill',
  description: 'Research Laravel + Spatie source for idioms, write a skill, A/B-evaluate it with Sonnet builders and blind judges, iterate, and test its audit/report mode',
  phases: [
    { title: 'Research', detail: '6 parallel readers over framework, docs, laravel.io and Spatie sources' },
    { title: 'Synthesize', detail: 'write SKILL.md + references + audit report template' },
    { title: 'Evaluate', detail: 'Sonnet builds each task with/without skill; blind judge per task' },
    { title: 'Revise', detail: 'improve skill from judge critiques if the round was not convincing' },
    { title: 'Audit test', detail: 'Sonnet runs the skill audit mode on a baseline app; checker verifies the report' },
  ],
}

const W = args.root
const SRC = `${W}/sources`
const SKILL = `${W}/skill/laravel-idioms`
const BASE = `${W}/eval/base`

const IDIOMS = {
  type: 'object',
  properties: {
    idioms: { type: 'array', items: { type: 'object', properties: {
      category: { type: 'string' },
      rule: { type: 'string', description: 'imperative, one sentence' },
      bad: { type: 'string', description: 'short non-idiomatic PHP snippet' },
      good: { type: 'string', description: 'short idiomatic PHP snippet' },
      evidence: { type: 'array', items: { type: 'string' }, description: 'repo-relative file:line where this is used/defined' },
      importance: { type: 'string', enum: ['core', 'strong', 'nice'] },
    }, required: ['category', 'rule', 'good', 'evidence', 'importance'] } },
    pitfalls: { type: 'array', items: { type: 'string' }, description: 'things LLMs commonly get wrong or outdated in this area (e.g. removed APIs, old skeleton structure)' },
  },
  required: ['idioms', 'pitfalls'],
}

const AREAS = [
  { key: 'collections-helpers', prompt: `Collections and support helpers. Read ${SRC}/laravel_framework/src/Illuminate/Collections (Collection, LazyCollection, Enumerable, helpers), src/Illuminate/Support (helpers.php, Str, Stringable, Arr, Number, Fluent, Once, Benchmark, Sleep, Pipeline), and grep how the framework itself uses them internally instead of foreach/array_* loops. Cover: map/filter/reject/reduce/each/pluck/keyBy/groupBy/mapWithKeys/partition/sum/avg/sortBy/firstWhere/contains/when/unless/pipe/tap/sole/ensure/chunk/lazy, higher-order messages ($users->each->notify()), Str::of fluent strings, Arr::get/only/except/wrap, data_get, collect(), tap(), value(), rescue(), retry(), throw_if/abort_if/abort_unless, blank()/filled(), optional/nullsafe, when() on builders, once(), Number::currency etc. Also note when a plain foreach is still more idiomatic (side effects over huge sets etc.).` },
  { key: 'http-layer', prompt: `The HTTP layer. Read the framework sources for FormRequest (src/Illuminate/Foundation/Http/FormRequest.php), validation (Rule, rule objects, Rule::enum, Password), routing (resource/apiResource, scoped bindings, route model binding, missing(), enum binding), controllers & middleware (HasMiddleware), JsonResource/ResourceCollection, response helpers (to_route, back()->with, response()->noContent), authorization (Policies, Gate, authorize via FormRequest, can middleware, #[UsePolicy] if present). Then study how the real app ${SRC}/laravelio_laravel.io structures its controllers, form requests, policies, jobs. Cover thin controllers, $request->validated()/safe()->only(), prepareForValidation, after() hooks, invokable controllers, route naming, 'store/update' conventions, redirects with flash, resource responses.` },
  { key: 'eloquent', prompt: `Eloquent and the query builder. Read ${SRC}/laravel_framework/src/Illuminate/Database/Eloquent (Model, Builder, Concerns/HasAttributes, casts, Attributes/*, Relations, Factories, SoftDeletes, Prunable) and the matching docs in ${SRC}/laravel_docs (eloquent*.md, queries.md). Cover: casts() method, Attribute::make accessors/mutators, enum casts, AsCollection/AsArrayObject, local scopes including any #[Scope] attribute, whereBelongsTo, withCount/withSum/withExists, eager loading and preventLazyLoading, firstOrCreate/updateOrCreate/upsert, chunkById/lazyById, when() on queries, relationships create via relation ($user->posts()->create()), model events vs observers (#[ObservedBy]), factories with states/relationships, mass assignment conventions, pluck/value/exists, Model::shouldBeStrict. Verify every attribute class you mention actually exists in the source (list the Attributes directory).` },
  { key: 'architecture', prompt: `Application architecture beyond controllers. Read ${SRC}/laravel_docs (queues.md, events.md, notifications.md, mail.md, container.md, providers.md, structure.md, testing.md, http-tests.md, database-testing.md, mocking.md, context.md, concurrency.md, helpers.md), ${SRC}/laravel_laravel (the current skeleton: bootstrap/app.php, routes, config shape), and ${SRC}/laravelio_laravel.io/app (Jobs, Events, Listeners, Notifications, Policies, Rules, Concerns). Also ${SRC}/spatie_laravel-data for DTO style. Cover: when to use jobs vs actions vs services, ShouldQueue, dispatch helpers, Bus::batch/chain, events+listeners and auto-discovery, notifications, backed enums with methods, config() vs env() rule, constructor injection & contextual attributes (#[Config], #[CurrentUser] etc. — verify in src/Illuminate/Container/Attributes), bootstrap/app.php middleware/exception config (no Http Kernel in modern skeleton), testing style (RefreshDatabase, factories, actingAs, assertJson fluent, Queue::fake/Notification::fake/Event::fake, assertDatabaseHas). Flag when patterns (actions, DTOs, repositories) are over-engineering for simple CRUD.` },
  { key: 'spatie-style', prompt: `Spatie's conventions. Read ${SRC}/spatie_guidelines.spatie.be (find the Laravel & PHP guideline markdown) in full, then sample code in ${SRC}/spatie_laravel-query-builder, spatie_laravel-permission, spatie_laravel-medialibrary, spatie_laravel-activitylog, spatie_laravel-settings, spatie_laravel-package-tools, spatie_laravel-data to see how they apply them: naming (controllers plural resource, route names, config keys, commands, views), typed properties & return types over docblocks, early returns / happy path last, avoiding else, string interpolation, fluent APIs, small classes, enum naming, test naming, constructor property promotion, readonly, named arguments, match. Record the rules with evidence.` },
  { key: 'modern-laravel', prompt: `What is NEW or CHANGED in Laravel 11, 12 and 13 that models trained on older code get wrong. Read ${SRC}/laravel_docs (upgrade.md, releases.md, structure.md, and grep for 'Laravel 11', '12', '13'), ${SRC}/laravel_laravel skeleton, and verify against ${SRC}/laravel_framework/src (e.g. list src/Illuminate/*/Attributes directories, check for defer(), Concurrency, Context, once(), casts() method, #[Scope], #[ObservedBy], #[UsePolicy], #[Bind]/#[Singleton], health route, schedule in routes/console.php, bootstrap/app.php withRouting/withMiddleware/withExceptions, per-second rate limiting, Rule::enum, fluent validation additions, new Str/Arr/Collection methods). The installed framework version is 13.x — only report features that exist in this source. Pitfalls should include outdated patterns (app/Http/Kernel.php, $casts property when casts() preferred, RouteServiceProvider, Console Kernel schedule, $dates, etc.).` },
]

const TASKS = [
  { key: 'sales-report', text: `Add a JSON sales reporting endpoint. Create Customer (name, email, country), Product (name, sku, price in cents, category), Order (customer, status: pending/paid/refunded, placed_at) and OrderItem (order, product, quantity, unit_price in cents) with migrations and factories. Add GET /api/reports/sales that accepts optional query params from, to (dates), country and status (default paid) and returns: total revenue, order count, average order value, revenue per country, top 5 products by revenue (name, sku, units, revenue) and the top 3 customers by spend. Money should be returned both as integer cents and as a formatted string. Invalid params must return 422.` },
  { key: 'blog-crud', text: `Add a blog. Authenticated users can create, update, delete and list their own posts via a JSON API under /api/posts. A post has title, slug (generated from the title, unique), body, status (draft/published), published_at and belongs to a user; posts can have many tags (tags are created on the fly from an array of names). Only the author may update or delete a post (403 otherwise). Publishing a post (status -> published) sets published_at if missing. The index endpoint lists the current user's posts, newest first, filterable by status and tag, paginated, and includes tag names. Use Sanctum-style auth if available, otherwise the default auth guard with actingAs in tests.` },
  { key: 'csv-import', text: `Add a product CSV import. POST /api/imports accepts an uploaded CSV file (max 5MB) with columns sku,name,price,stock. Store the file, create an Import record (user, status queued/processing/completed/failed, totals) and process it in the background: skip and record rows with invalid data (missing sku, non-numeric price, negative stock) with row number and reason, insert or update products by sku, then mark the import completed with counts (created, updated, failed) and email the user a summary. GET /api/imports/{import} returns status, counts and the list of row errors; only the owner may view it.` },
  { key: 'cart-discounts', text: `Add cart pricing with discount codes. Model a Cart with CartItems (product name, unit price in cents, quantity, category) and DiscountCode (code, type: percentage/fixed/free_shipping, value, min_subtotal, applies_to_category nullable, expires_at, max_uses, times_used). POST /api/carts/{cart}/apply-discount with {code} validates the code (exists, not expired, under max uses, cart meets min subtotal) and returns a price breakdown: subtotal, discount, shipping (flat 4900 cents, free over 50000 cents subtotal or with free_shipping code), total, and per-line discounted prices. Category-restricted codes only discount matching lines. Return clear 422 errors for each failure reason.` },
]

const BUILD_RESULT = { type: 'object', properties: {
  tests_passed: { type: 'boolean' }, test_summary: { type: 'string' }, files: { type: 'array', items: { type: 'string' } }, notes: { type: 'string' },
}, required: ['tests_passed', 'test_summary', 'files'] }

const DIM = ['collections_helpers', 'thin_controllers', 'validation_form_requests', 'eloquent_idioms', 'architecture_fit', 'modern_laravel', 'tests', 'correctness', 'simplicity']
const scoreProps = Object.fromEntries(DIM.map(d => [d, { type: 'number', minimum: 1, maximum: 10 }]))
const SIDE = { type: 'object', properties: {
  scores: { type: 'object', properties: scoreProps, required: DIM },
  overall: { type: 'number', minimum: 1, maximum: 10 },
  tests_pass: { type: 'boolean' },
  antipatterns: { type: 'array', items: { type: 'string' }, description: 'concrete non-idiomatic spots with file:line' },
  strengths: { type: 'array', items: { type: 'string' } },
}, required: ['scores', 'overall', 'tests_pass', 'antipatterns', 'strengths'] }
const VERDICT = { type: 'object', properties: {
  A: SIDE, B: SIDE, winner: { type: 'string', enum: ['A', 'B', 'tie'] }, rationale: { type: 'string' },
}, required: ['A', 'B', 'winner', 'rationale'] }

function buildPrompt(task, dir, withSkill) {
  const skillLine = withSkill
    ? `\nBefore writing any code, read the Laravel skill at ${SKILL}/SKILL.md and follow it, loading the reference files it points to when relevant.\n`
    : ''
  return `You are working in a Laravel application. Setup: run \`cp -R ${BASE} ${dir}\` (skip if ${dir} already exists), then work ONLY inside ${dir}. Do not read or modify anything else under ${W}.
${skillLine}
Task:
${task.text}

Write feature tests (PHPUnit, as the skeleton uses) covering the behaviour and make \`php artisan test\` pass inside ${dir}. The test DB is SQLite in-memory. Install extra composer packages only if genuinely needed. When finished, return whether tests pass, the test summary line, and the list of files you created/changed (relative to ${dir}).`
}

function judgePrompt(task, dirA, dirB) {
  return `You are a senior Laravel reviewer judging two independent implementations of the same task, blind. Implementation A is in ${dirA}, implementation B is in ${dirB}. Both started from the same fresh Laravel 13 skeleton (${BASE}); diff against it to see what each added (e.g. \`diff -rq ${BASE} ${dirA} -x vendor -x node_modules -x storage -x '*.sqlite'\`).

Task they were given:
${task.text}

Run \`php artisan test\` in each directory yourself. Then read every added/changed file and score each implementation 1-10 on:
- collections_helpers: collections/higher-order messages/Str/Arr/Number/helpers used where they read better than foreach, manual arrays, sprintf, loops
- thin_controllers: controllers only orchestrate; logic lives in the right place
- validation_form_requests: Form Requests, rule objects, Rule::enum, validated()/safe(), authorization in the right place
- eloquent_idioms: relationships, scopes, casts(), eager loading, aggregates in SQL where sensible, route model binding
- architecture_fit: jobs/events/notifications/policies/enums/resources used where they fit
- modern_laravel: current Laravel 11-13 conventions, no outdated patterns
- tests: idiomatic, meaningful Laravel tests (factories, fakes, fluent JSON assertions)
- correctness: does it actually satisfy the task and pass tests
- simplicity: PENALISE over-engineering (needless interfaces, repositories, DTO/action layers for trivial code, ceremony) — idiomatic Laravel is pragmatic
Give an overall score (your holistic judgement, not an average), concrete antipatterns with file:line, strengths, and the winner. Do not guess which was produced how; judge the code only.`
}

phase('Research')
const research = (await parallel(AREAS.map(a => () =>
  agent(`Study real source code to extract idiomatic Laravel practice for a Claude skill. Focus area: ${a.prompt}\n\nGround everything in the source: every idiom needs evidence paths (repo-relative to ${SRC}). Prefer idioms that change how code gets written day to day. Aim for 15-30 idioms with short bad/good PHP snippets.`,
    { label: `research:${a.key}`, phase: 'Research', schema: IDIOMS }).then(r => r && { area: a.key, ...r })))).filter(Boolean)
log(`Research: ${research.length}/${AREAS.length} areas, ${research.reduce((n, r) => n + r.idioms.length, 0)} idioms`)

phase('Synthesize')
await agent(`Write a Claude Code skill named "laravel-idioms" into the directory ${SKILL} (create it). Research findings from reading the Laravel framework, docs, laravel.io and Spatie sources (JSON):

${JSON.stringify(research)}

Structure:
- ${SKILL}/SKILL.md: YAML frontmatter (name: laravel-idioms; description that triggers whenever writing, editing, reviewing or refactoring PHP in a Laravel app, and when asked to audit a Laravel codebase / suggest refactors). Body: a TIGHT core ruleset (under ~200 lines) — the highest-leverage rules as short imperatives with compact bad→good snippets for the most common mistakes (foreach accumulation vs collections, validation in controllers, fat controllers, $casts property vs casts(), manual auth checks vs policies, N+1, env() outside config, outdated skeleton patterns). A short "pragmatism" section: don't add repositories/interfaces/DTO/action layers unless the code earns it. A brief "before you finish" self-check list. Pointers saying which reference file to load for what.
- ${SKILL}/references/collections-and-helpers.md, http-layer.md, eloquent.md, architecture-and-testing.md, modern-laravel.md (Laravel 11-13 changes and outdated patterns to avoid), style.md (Spatie-derived conventions). Each focused, example-driven, with evidence paths trimmed to the most useful.
- ${SKILL}/references/audit-mode.md: how to audit an existing Laravel codebase and produce refactor suggestions. Steps: detect Laravel version from composer.lock; map the app (routes, controllers, requests, models, jobs, tests); fan out parallel sub-agents per area (http layer, eloquent/queries, collections/helpers, architecture, tests, outdated patterns) when the Agent tool is available; each finding needs file:line, category, severity (high/medium/low), effort, the current code excerpt, the suggested idiomatic rewrite, and why; verify each finding by re-reading the code (drop false positives); prioritise by impact/effort. Output: a single SELF-CONTAINED HTML report (all CSS/JS inline, no CDN, no external fonts — system font stack, <!DOCTYPE html>, <meta charset="utf-8">, viewport meta, works offline from disk, light+dark via prefers-color-scheme, readable on phone widths) built from the template, written to the repo as laravel-refactor-report.html (or a path the user gives); then if an Artifact publishing tool is available, publish it and give the link. Include summary stats, a severity/category breakdown, a "quick wins" list, and per-finding before/after code blocks with filter buttons by category/severity.
- ${SKILL}/assets/audit-report-template.html: the self-contained template with clear placeholders/a JS data array for findings, rendering filterable cards. Keep it clean and professional.

Rules: only include APIs that the research verified exist in the source (Laravel 13). No filler, no history/narration in the skill text. Keep examples short. Return a list of files written and line counts.`, { label: 'synthesize-skill', phase: 'Synthesize' })

async function runRound(round, baselineDirs) {
  phase('Evaluate')
  return pipeline(TASKS,
    async (task, _t, i) => {
      const withDir = `${W}/eval/runs/r${round}/${task.key}-with`
      const withoutDir = baselineDirs ? baselineDirs[i] : `${W}/eval/runs/r1/${task.key}-without`
      const [w, wo] = await parallel([
        () => agent(buildPrompt(task, withDir, true), { label: `r${round}:${task.key}:with`, phase: 'Evaluate', model: 'sonnet', schema: BUILD_RESULT }),
        () => baselineDirs ? Promise.resolve({ reused: true }) : agent(buildPrompt(task, withoutDir, false), { label: `r${round}:${task.key}:without`, phase: 'Evaluate', model: 'sonnet', schema: BUILD_RESULT }),
      ])
      return { task, withDir, withoutDir, w, wo }
    },
    async (b, task, i) => {
      const swap = (i + round) % 2 === 1
      const [dA, dB] = swap ? [b.withoutDir, b.withDir] : [b.withDir, b.withoutDir]
      const v = await agent(judgePrompt(task, dA, dB), { label: `r${round}:${task.key}:judge`, phase: 'Evaluate', schema: VERDICT })
      if (!v) return null
      const withSide = swap ? v.B : v.A, withoutSide = swap ? v.A : v.B
      const winner = v.winner === 'tie' ? 'tie' : ((v.winner === 'A') !== swap ? 'with' : 'without')
      return { task: task.key, winner, with: withSide, without: withoutSide, rationale: v.rationale, withDir: b.withDir, withoutDir: b.withoutDir }
    })
}

function summarize(results) {
  const r = results.filter(Boolean)
  const mean = (xs) => xs.reduce((a, b) => a + b, 0) / Math.max(xs.length, 1)
  const dims = Object.fromEntries(DIM.map(d => [d, { with: +mean(r.map(x => x.with.scores[d])).toFixed(2), without: +mean(r.map(x => x.without.scores[d])).toFixed(2) }]))
  return {
    n: r.length,
    wins: r.filter(x => x.winner === 'with').length,
    losses: r.filter(x => x.winner === 'without').length,
    ties: r.filter(x => x.winner === 'tie').length,
    overall_with: +mean(r.map(x => x.with.overall)).toFixed(2),
    overall_without: +mean(r.map(x => x.without.overall)).toFixed(2),
    tests_with: r.filter(x => x.with.tests_pass).length,
    tests_without: r.filter(x => x.without.tests_pass).length,
    dims,
  }
}

const rounds = []
let results = await runRound(1, null)
let s = summarize(results)
rounds.push({ round: 1, summary: s, results })
log(`Round 1: with-skill ${s.wins}W/${s.losses}L/${s.ties}T, overall ${s.overall_with} vs ${s.overall_without}`)
const baselineDirs = TASKS.map(t => `${W}/eval/runs/r1/${t.key}-without`)
const fruitful = (s) => s.wins >= 3 && s.overall_with - s.overall_without >= 1.5 && s.tests_with >= s.tests_without && s.dims.simplicity.with >= s.dims.simplicity.without - 0.5

let round = 1
while (!fruitful(s) && round < 3) {
  round++
  phase('Revise')
  await agent(`The "laravel-idioms" skill at ${SKILL} was A/B-tested: Sonnet built 4 Laravel tasks with and without it, blind-judged. It did not clear the bar (≥3/4 wins, overall margin ≥1.5, no test regressions, no simplicity loss). Round ${round - 1} results (unblinded; "with" = used the skill):

${JSON.stringify(rounds[rounds.length - 1])}

First snapshot the current version: \`mkdir -p ${W}/skill/history && cp -R ${SKILL} ${W}/skill/history/v${round - 1}\`. Then inspect the with-skill code in the listed withDir directories to find WHY it fell short: rules the builder ignored (probably buried or too abstract), missing rules for antipatterns the judge flagged, rules causing over-engineering or test failures, and strengths of the baseline worth encoding. Revise the skill: sharpen and promote the high-impact rules into SKILL.md with concrete bad→good snippets, add a mandatory pre-finish self-review checklist if weak, cut anything that caused ceremony. Keep SKILL.md under ~220 lines; move detail to references. Do not tailor rules to these specific task domains — rules must generalise. Return a concise changelog.`, { label: `revise:v${round}`, phase: 'Revise' })
  results = await runRound(round, baselineDirs)
  s = summarize(results)
  rounds.push({ round, summary: s, results })
  log(`Round ${round}: with-skill ${s.wins}W/${s.losses}L/${s.ties}T, overall ${s.overall_with} vs ${s.overall_without}`)
}

phase('Audit test')
const auditTarget = `${W}/eval/runs/r1/blog-crud-without`
const auditOut = `${W}/eval/audit/laravel-refactor-report.html`
const known = (rounds[0].results.filter(Boolean).find(x => x.task === 'blog-crud') || {}).without
const auditRun = await agent(`Read the Laravel skill at ${SKILL}/SKILL.md and its references/audit-mode.md, then run its audit mode on the Laravel codebase at ${auditTarget} (read-only; do not modify it). Follow the audit-mode instructions, but write the HTML report to ${auditOut} (mkdir -p its directory) and do not publish it anywhere. Return a short summary: number of findings by severity and the report path.`, { label: 'audit:run', phase: 'Audit test', model: 'sonnet' })
const auditCheck = await agent(`Evaluate an auto-generated Laravel refactor report at ${auditOut} for the codebase ${auditTarget} (fresh Laravel skeleton at ${BASE} for diffing).
Check: (1) self-contained — grep for any http(s):// src/href to scripts, stylesheets, fonts, @import; must render offline; has doctype, charset, viewport; (2) accuracy — for at least 8 findings, open the cited file:line and confirm the issue is real and the suggested rewrite is valid Laravel 13 code; (3) recall — an independent reviewer flagged these antipatterns in the same code: ${JSON.stringify(known ? known.antipatterns : [])}; how many did the report catch; (4) usefulness — prioritisation, before/after clarity, filters working (inspect the JS). Return JSON-ish text with: self_contained (bool + offenders), findings_checked, false_positives (list), recall (caught/total), usefulness score 1-10, and concrete improvements for the audit-mode instructions/template.`, { label: 'audit:check', phase: 'Audit test' })

return { skill: SKILL, rounds: rounds.map(r => ({ round: r.round, summary: r.summary, perTask: r.results.filter(Boolean).map(x => ({ task: x.task, winner: x.winner, with: x.with.overall, without: x.without.overall, with_tests: x.with.tests_pass, without_tests: x.without.tests_pass, with_antipatterns: x.with.antipatterns, without_antipatterns: x.without.antipatterns, rationale: x.rationale })) })), fruitful: fruitful(s), auditRun, auditCheck }
