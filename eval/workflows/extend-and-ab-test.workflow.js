export const meta = {
  name: 'laravel-idioms-v4-extend-eval',
  description: 'Extend laravel-idioms with Pest, Blade, Filament, caching and queues/workers from source, then A/B-evaluate new + original tasks with Sonnet builders and blind judges, iterating if needed',
  phases: [
    { title: 'Research', detail: '5 readers: Pest, Blade, Filament 5, caching, queues/workers' },
    { title: 'Extend skill' },
    { title: 'Evaluate', detail: 'new tasks with/without; original tasks with-skill vs r1 baselines; blind judges' },
    { title: 'Revise' },
    { title: 'Audit spot-check' },
  ],
}
const W = args.root
const SRC = `${W}/sources`
const SKILL = `${W}/skill/laravel-idioms`
const E = `${W}/eval`

const IDIOMS = { type: 'object', properties: {
  idioms: { type: 'array', items: { type: 'object', properties: {
    category: { type: 'string' }, rule: { type: 'string' }, bad: { type: 'string' }, good: { type: 'string' },
    evidence: { type: 'array', items: { type: 'string' } }, importance: { type: 'string', enum: ['core', 'strong', 'nice'] } },
    required: ['category', 'rule', 'good', 'evidence', 'importance'] } },
  pitfalls: { type: 'array', items: { type: 'string' } } }, required: ['idioms', 'pitfalls'] }

const AREAS = [
  { key: 'pest', prompt: `Pest 5 testing in Laravel apps. Read ${SRC}/pestphp_docs (expectations, datasets, hooks, higher-order-testing, arch-testing, grouping, mocking, exceptions, custom-expectations, migrating-from-phpunit-guide, pest5-now-available, configuring-tests), ${SRC}/pestphp_pest-plugin-laravel/src (function helpers like actingAs/get/postJson), ${SRC}/pestphp_pest/src (Expectation methods; verify every expectation you cite exists), and real suites in ${SRC}/laravel_livewire-starter-kit/tests and ${SRC}/filamentphp_demo/tests. Cover: tests/Pest.php configuration (pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')), it()/test()/describe(), beforeEach, expect() chains and ->and(), ->each, ->sequence, toBe/toEqual/toHaveCount/toMatchArray/toBeInstanceOf/toThrow, datasets (named, bound closures for models), higher-order tests, arch() presets (php, laravel, security) and custom arch rules, ->throws(), skip/todo, fakes, Laravel response assertions inside Pest, when NOT to convert a project's PHPUnit suite. Pitfalls: PHPUnit-style classes in a Pest project, $this in arrow fns, datasets creating models at definition time, etc.` },
  { key: 'blade', prompt: `Blade and server-rendered Laravel UIs. Read ${SRC}/laravel_docs (blade.md, views.md, validation.md sections on displaying errors/old input, csrf.md, pagination.md, precognition.md, urls.md, session.md flash data, vite.md), the Blade compiler in ${SRC}/laravel_framework/src/Illuminate/View (Compilers/Concerns — list the directives that exist: @class, @style, @checked, @selected, @disabled, @readonly, @required, @error, @session, @use, @props, @aware, @fragment, @once, @push, @env, @production, @csrf, @method, @forelse, $loop, @can, @auth etc.; ComponentAttributeBag methods merge/class/only/except/whereStartsWith), and real views in ${SRC}/laravel_livewire-starter-kit/resources/views and ${SRC}/laravelio_laravel.io/resources/views. Cover: layouts via components vs @extends/@section, anonymous components with @props, slots/named slots, $attributes->merge/class, class components when logic is needed, keeping logic out of views (no queries in Blade, eager load in controller, view models/presenters only when earned), route() and named routes in views, old() with defaults, @error, flash via session, forms with @csrf/@method, pagination links()->withQueryString(), escaping {{ }} vs {!! !!}, form controller patterns (redirect()->route()->with('status'), to_route, back()->withInput()), withoutVite() in tests.` },
  { key: 'filament', prompt: `Filament 5 admin panels. Read ${SRC}/filamentphp_filament/docs (getting-started, resources/*, panel-configuration, users (authorization), testing/*, upgrade-guide) and the packages docs under docs/04-_PACKAGES or packages/*/docs (forms, tables, actions, schemas, infolists, widgets, notifications), and the real app ${SRC}/filamentphp_demo/app/Filament (resources, relation managers, widgets, custom pages) plus its tests. Verify class names/namespaces exist in ${SRC}/filamentphp_filament/packages (e.g. Filament\\Schemas\\Schema, Filament\\Actions\\*, Filament\\Tables\\*). Cover: generating resources with artisan (make:filament-resource --generate/--view), v4/v5 resource directory structure (Schemas/…Form, Tables/…Table classes, Pages), form(Schema $schema) with components, table columns (TextColumn badge/color/searchable/sortable, enums implementing HasLabel/HasColor/HasIcon), filters (SelectFilter relationship, TernaryFilter, Filter with query), record actions/bulk actions/header actions, relation managers, widgets (StatsOverviewWidget, ChartWidget), authorization via model policies, getEloquentQuery/modifyQueryUsing and eager loading, notifications, and testing with livewire(ListX::class)->assertCanSeeTableRecords / fillForm / callAction / assertHasNoFormErrors etc. Pitfalls: Filament v2/v3 APIs (Form $form, Tables\\Actions\\ namespaces, Filament\\Forms\\Components\\Section vs Schemas) that are wrong in v5.` },
  { key: 'caching', prompt: `Caching in Laravel. Read ${SRC}/laravel_framework/src/Illuminate/Cache (Repository, CacheManager, Lock, RateLimiter, MemoizedStore / memo if present, TaggedCache, flexible()), ${SRC}/laravel_framework/src/Illuminate/Support/helpers.php (cache(), once()), and ${SRC}/laravel_docs (cache.md, rate-limiting.md, deployment.md for config/route/view/event caching, octane.md only if relevant, http-client.md). Cover: Cache::remember/rememberForever/flexible (stale-while-revalidate) and when each fits, key naming and versioning, per-tenant/team keys, invalidation from model events/observers vs short TTLs, Cache::lock()->block() to prevent stampedes, atomic add/increment, tags only on supporting stores (not file/database), Cache::memo() if it exists, once() for per-request memoization vs cache, caching Eloquent results (cache ids/arrays not huge model graphs), fallback to last known value on upstream failure, Http client retry/timeout, RateLimiter for, deploy-time optimize/config:cache (env() only in config), testing caches (array driver, Cache::spy? verify what exists, travel()). Verify every API in source.` },
  { key: 'queues', prompt: `Queues, jobs and workers in production Laravel. Read ${SRC}/laravel_framework/src/Illuminate/Queue (Worker, WorkerOptions, Middleware/* — RateLimited, WithoutOverlapping, ThrottlesExceptions, Skip; Attributes/* like #[Tries] #[Backoff] #[Timeout] #[MaxExceptions] #[DeleteWhenMissingModels] — verify which exist), Illuminate/Bus (Batch, Batchable, chains, UniqueLock), Illuminate/Contracts/Queue (ShouldBeUnique, ShouldBeUniqueUntilProcessing, ShouldBeEncrypted, ShouldQueueAfterCommit), ${SRC}/laravel_docs (queues.md, horizon.md, scheduling.md, events.md queued listeners, notifications.md queued notifications, deployment.md), and ${SRC}/laravel_horizon (config/horizon.php, supervisors, balancing). Cover: job design (small payloads, models serialized by id, idempotency, uniqueness and uniqueId), retries (tries, backoff arrays, retryUntil, maxExceptions, release vs fail), timeouts vs retry_after, rate-limited external APIs (RateLimiter::for + RateLimited middleware), WithoutOverlapping per key, ThrottlesExceptions for flaky upstreams, failed() and failed_jobs, batches/chains, afterCommit, queued listeners/notifications/mail, queue names and priorities, worker deployment (queue:work flags --tries --max-time --memory, supervisor config, queue:restart on deploy, Horizon config and horizon:terminate), pruning (model:prune, queue:prune-failed, queue:prune-batches) and scheduling, testing (Queue::fake, Bus::fake, assertPushedOn, assertChained, Bus::assertBatched, job->withFakeQueueInteractions()->handle()->assertReleased). Verify every API in source.` },
]

const DIM = ['collections_helpers', 'thin_controllers', 'validation_form_requests', 'eloquent_idioms', 'architecture_fit', 'modern_laravel', 'focus_area', 'tests', 'correctness', 'simplicity']
const scoreProps = Object.fromEntries(DIM.map(d => [d, { type: 'number', minimum: 1, maximum: 10 }]))
const SIDE = { type: 'object', properties: {
  scores: { type: 'object', properties: scoreProps, required: DIM }, overall: { type: 'number', minimum: 1, maximum: 10 },
  tests_pass: { type: 'boolean' }, antipatterns: { type: 'array', items: { type: 'string' } }, strengths: { type: 'array', items: { type: 'string' } } },
  required: ['scores', 'overall', 'tests_pass', 'antipatterns', 'strengths'] }
const VERDICT = { type: 'object', properties: { A: SIDE, B: SIDE, winner: { type: 'string', enum: ['A', 'B', 'tie'] }, rationale: { type: 'string' } }, required: ['A', 'B', 'winner', 'rationale'] }
const BUILD = { type: 'object', properties: { tests_passed: { type: 'boolean' }, test_summary: { type: 'string' }, files: { type: 'array', items: { type: 'string' } }, notes: { type: 'string' } }, required: ['tests_passed', 'test_summary', 'files'] }

const OLD_TEXT = {
  'sales-report': `Add a JSON sales reporting endpoint. Create Customer (name, email, country), Product (name, sku, price in cents, category), Order (customer, status: pending/paid/refunded, placed_at) and OrderItem (order, product, quantity, unit_price in cents) with migrations and factories. Add GET /api/reports/sales that accepts optional query params from, to (dates), country and status (default paid) and returns: total revenue, order count, average order value, revenue per country, top 5 products by revenue (name, sku, units, revenue) and the top 3 customers by spend. Money should be returned both as integer cents and as a formatted string. Invalid params must return 422.`,
  'blog-crud': `Add a blog. Authenticated users can create, update, delete and list their own posts via a JSON API under /api/posts. A post has title, slug (generated from the title, unique), body, status (draft/published), published_at and belongs to a user; posts can have many tags (tags are created on the fly from an array of names). Only the author may update or delete a post (403 otherwise). Publishing a post (status -> published) sets published_at if missing. The index endpoint lists the current user's posts, newest first, filterable by status and tag, paginated, and includes tag names. Use Sanctum-style auth if available, otherwise the default auth guard with actingAs in tests.`,
  'csv-import': `Add a product CSV import. POST /api/imports accepts an uploaded CSV file (max 5MB) with columns sku,name,price,stock. Store the file, create an Import record (user, status queued/processing/completed/failed, totals) and process it in the background: skip and record rows with invalid data (missing sku, non-numeric price, negative stock) with row number and reason, insert or update products by sku, then mark the import completed with counts (created, updated, failed) and email the user a summary. GET /api/imports/{import} returns status, counts and the list of row errors; only the owner may view it.`,
  'cart-discounts': `Add cart pricing with discount codes. Model a Cart with CartItems (product name, unit price in cents, quantity, category) and DiscountCode (code, type: percentage/fixed/free_shipping, value, min_subtotal, applies_to_category nullable, expires_at, max_uses, times_used). POST /api/carts/{cart}/apply-discount with {code} validates the code (exists, not expired, under max uses, cart meets min subtotal) and returns a price breakdown: subtotal, discount, shipping (flat 4900 cents, free over 50000 cents subtotal or with free_shipping code), total, and per-line discounted prices. Category-restricted codes only discount matching lines. Return clear 422 errors for each failure reason.`,
}

const TASKS = [
  { key: 'blade-catalogue', base: `${E}/base-pest`, pest: true, focus: 'Blade: layouts/components, slots, @props/$attributes, directives (@error, @class, @selected...), old input, flash, pagination with query string, no logic/queries in views, redirect patterns',
    text: `Add a server-rendered product catalogue using Blade (no SPA, no Livewire). Category (name, slug) and Product (category, name, slug, description, price in cents, stock, is_active). Public pages: /products lists active products with a text search, a category filter and a sort (newest, price asc/desc), paginated 12 per page with filters preserved across pages; /products/{slug} shows a product with an "Out of stock" badge when stock is 0 and a list of 4 related products from the same category. Admins (users with an is_admin boolean) get pages under /admin/products to create, edit and delete products using one shared form; validation errors appear next to each field, previously entered input is kept after a failed submit, and a success message is shown after saving. Use a shared layout and reusable view components. There is no frontend build available, so pages must render without compiled assets.` },
  { key: 'filament-admin', base: `${E}/base-filament`, pest: true, focus: 'Filament 5: resource structure (Schemas/Tables classes), form/table/filter/action APIs, enums with HasLabel/HasColor, relation managers, widgets, policies, eager loading, Filament testing helpers',
    text: `The app has a Filament admin panel at /admin. Build a support-ticket admin in it. Customer (name, email, company), Ticket (customer, subject, body, status: open/pending/resolved, priority: low/normal/high/urgent, assigned agent = a User, nullable; resolved_at), TicketReply (ticket, author user, body, is_internal). Ticket resource: a table with searchable subject and customer name, sortable created date, status and priority shown as coloured badges, filters for status, priority, assignee and "unassigned only"; a create/edit form; a "Resolve" row action (sets status resolved and resolved_at) and a bulk "Assign to me" action; a replies relation manager on the ticket page. Add a Customer resource and a dashboard stats widget showing open tickets, urgent open tickets and the average resolution time in hours over the last 30 days. Only users with is_admin may delete tickets; any logged-in user may access the panel. Write Pest tests using Filament's testing helpers.` },
  { key: 'caching', base: `${E}/base-pest`, pest: true, focus: 'Caching: remember/flexible choice, key design, invalidation via model events, locks/stampede protection, stale fallback, Http client retries/timeouts, testing with Http::fake/travel',
    text: `Teams have Projects, and Projects have Tasks (title, status: open/done, completed_at, assignee user). Users belong to one team. Add GET /api/dashboard returning, for the current user's team: member count, project count, open task count, tasks completed in the last 7 days, and the top 5 projects by open tasks. It is expensive and hit constantly, so cache it per team; changes to that team's projects or tasks must be reflected within seconds, while serving slightly stale data for up to 5 minutes is acceptable if recomputing is slow. Also add GET /api/exchange-rates/{currency} (3-letter ISO code, validated) that returns rates from an external API at https://rates.example.test/latest?base={currency}. Cache the rates for one hour. When many requests miss at once, only one request may call the API. If the API is down or slow (over 3 seconds), return the last known rates with "stale": true, or 503 if there are none.` },
  { key: 'webhook-worker', base: `${E}/base-pest`, pest: true, focus: 'Queues/workers: idempotency, uniqueness/WithoutOverlapping, retries/backoff/ThrottlesExceptions, RateLimited middleware, failed(), afterCommit, queued notifications, pruning/scheduling, worker deployment config, Queue/Bus fakes',
    text: `Ingest payment-provider webhooks. POST /webhooks/payments receives JSON events {id, type, data} with an X-Signature header: HMAC-SHA256 of the raw body using a secret from configuration; invalid or missing signatures get 401. Store every event exactly once by its id (a duplicate delivery returns 200 and is not processed again) and respond quickly; process events in the background. Types: payment.succeeded (mark the Order paid and send the customer a receipt notification), payment.failed (mark the order payment_failed), refund.created (create a Refund and mark the order refunded). While processing, fetch payment details from the provider API GET https://api.payments.test/payments/{payment_id}; that API allows at most 10 requests per second and sometimes returns 5xx errors, so processing must retry with increasing delays, respect the limit, and never process the same event twice at the same time. Events that permanently fail must be marked failed with the error and be visible via GET /api/webhook-events?status=failed (admin only). Add a daily cleanup that deletes processed events older than 30 days. Document in the README how the queue workers must be run in production.` },
  ...Object.keys(OLD_TEXT).map(k => ({ key: k, base: `${E}/base`, pest: false, old: true, focus: 'general Laravel idioms (this is a regression check)', text: OLD_TEXT[k] })),
]

function buildPrompt(task, dir, withSkill) {
  const skillLine = withSkill ? `\nBefore writing any code, read the Laravel skill at ${SKILL}/SKILL.md and follow it, loading the reference files it points to when relevant.\n` : ''
  const testLine = task.pest
    ? `The project uses Pest. Write Pest feature tests covering the behaviour and make \`php artisan test\` pass inside ${dir}.`
    : `Write feature tests (PHPUnit, as the skeleton uses) covering the behaviour and make \`php artisan test\` pass inside ${dir}.`
  return `You are working in a Laravel application. Setup: run \`cp -R ${task.base} ${dir}\` (skip if ${dir} already exists), then work ONLY inside ${dir}. Do not read or modify anything else under ${W}.
${skillLine}
Task:
${task.text}

${testLine} The test DB is SQLite in-memory. Do not make real network calls. Install extra composer packages only if genuinely needed. When finished, return whether tests pass, the test summary line, and the files you created/changed (relative to ${dir}).`
}

function judgePrompt(task, dA, dB) {
  return `You are a senior Laravel reviewer judging two independent implementations of the same task, blind. A is in ${dA}, B is in ${dB}. Both started from the same base app ${task.base}; diff against it to see what each added (\`diff -rq ${task.base} ${dA} -x vendor -x node_modules -x storage -x '*.sqlite'\`).

Task:
${task.text}

Run \`php artisan test\` in each yourself. Read every added/changed file. Score each 1-10 on:
- collections_helpers, thin_controllers, validation_form_requests, eloquent_idioms, architecture_fit, modern_laravel (current Laravel 11-13 conventions)
- focus_area: idiomatic use of this task's focus — ${task.focus}. Check APIs against the installed vendor/ versions.
- tests: idiomatic, meaningful tests${task.pest ? ' in Pest style (expect() chains, datasets, beforeEach, Pest.php config) — PHPUnit-style classes in a Pest project count against it' : ''}; coverage of failure paths and edge cases
- correctness: does it satisfy every requirement and pass tests (probe edge cases yourself where cheap)
- simplicity: PENALISE over-engineering and ceremony; idiomatic Laravel is pragmatic
Give an overall score (holistic), concrete antipatterns with file:line, strengths, and the winner. Judge the code only.`
}

phase('Research')
const research = (await parallel(AREAS.map(a => () => agent(`Study real source code to extract idiomatic practice for a Claude skill about writing Laravel code. Focus area: ${a.prompt}\n\nEvery idiom needs evidence paths (relative to ${SRC}). Prefer idioms that change how code gets written day to day and things models get wrong. Aim for 15-30 idioms with short bad/good snippets.`,
  { label: `research:${a.key}`, phase: 'Research', schema: IDIOMS }).then(r => r && { area: a.key, ...r })))).filter(Boolean)
log(`Research: ${research.length}/5 areas, ${research.reduce((n, r) => n + r.idioms.length, 0)} idioms`)

phase('Extend skill')
await agent(`Extend the existing Claude skill at ${SKILL} (read SKILL.md and every file under references/ first). First snapshot: \`cp -R ${SKILL} ${W}/skill/history/v3-final\` if that path doesn't exist. Add coverage for Pest, Blade, Filament 5, caching, and queues/workers, from these source-grounded research findings (JSON):

${JSON.stringify(research)}

Do:
- New reference files: references/pest.md, references/blade.md, references/filament.md, references/caching.md, references/queues-and-workers.md. Move job/queue content out of architecture-and-testing.md into queues-and-workers.md so nothing is duplicated; keep architecture-and-testing.md's general testing guidance but make it framework-neutral where Pest vs PHPUnit differ (point to pest.md).
- SKILL.md: add compact high-leverage rules for each new area, in the same style (short imperatives + at most one small bad→good snippet each where it prevents the most common mistake). Testing rule: match the project's framework (Pest if tests/Pest.php or pestphp/pest exists — then write Pest style, never PHPUnit classes). Update the frontmatter description to mention Pest, Blade, Filament, caching and queues/workers. Extend the "Before you finish" checklist with one line per new area. Update the references table. Keep SKILL.md under ~240 lines total — tighten existing text if needed rather than dropping proven rules (thin controllers, FormRequests for GET filters, one query-rule source, enums, factories ->for(), failure-path tests, the checklist itself).
- references/audit-mode.md: add "Look for" rows (or extend rows) for Blade, Filament, caching, queues/workers, Pest.
- Only include APIs the research verified in source (Laravel 13, Pest 5, Filament 5). No narration/history in the text.
Return a changelog and final line counts.`, { label: 'extend-skill', phase: 'Extend skill' })

const baseDir = (t) => t.old ? `${E}/runs/r1/${t.key}-without` : `${E}/runs/r3/${t.key}-without`

async function runRound(round, reuseBaselines) {
  phase('Evaluate')
  return pipeline(TASKS,
    async (task) => {
      const withDir = `${E}/runs/r${round}/${task.key}-with`
      const needBaseline = !task.old && !reuseBaselines
      const [w] = await parallel([
        () => agent(buildPrompt(task, withDir, true), { label: `r${round}:${task.key}:with`, phase: 'Evaluate', model: 'sonnet', schema: BUILD }),
        () => needBaseline ? agent(buildPrompt(task, baseDir(task), false), { label: `r${round}:${task.key}:without`, phase: 'Evaluate', model: 'sonnet', schema: BUILD }) : Promise.resolve(null),
      ])
      return { withDir, withoutDir: baseDir(task), w }
    },
    async (b, task, i) => {
      const swap = (i + round) % 2 === 1
      const [dA, dB] = swap ? [b.withoutDir, b.withDir] : [b.withDir, b.withoutDir]
      const v = await agent(judgePrompt(task, dA, dB), { label: `r${round}:${task.key}:judge`, phase: 'Evaluate', schema: VERDICT })
      if (!v) return null
      const withSide = swap ? v.B : v.A, withoutSide = swap ? v.A : v.B
      const winner = v.winner === 'tie' ? 'tie' : ((v.winner === 'A') !== swap ? 'with' : 'without')
      return { task: task.key, old: !!task.old, winner, with: withSide, without: withoutSide, rationale: v.rationale, withDir: b.withDir, withoutDir: b.withoutDir }
    })
}

const mean = (xs) => xs.reduce((a, b) => a + b, 0) / Math.max(xs.length, 1)
function summarize(rs) {
  const r = rs.filter(Boolean)
  return { n: r.length, wins: r.filter(x => x.winner === 'with').length, losses: r.filter(x => x.winner === 'without').length, ties: r.filter(x => x.winner === 'tie').length,
    overall_with: +mean(r.map(x => x.with.overall)).toFixed(2), overall_without: +mean(r.map(x => x.without.overall)).toFixed(2),
    tests_with: r.filter(x => x.with.tests_pass).length, tests_without: r.filter(x => x.without.tests_pass).length,
    dims: Object.fromEntries(DIM.map(d => [d, { with: +mean(r.map(x => x.with.scores[d])).toFixed(2), without: +mean(r.map(x => x.without.scores[d])).toFixed(2) }])) }
}
const ok = (s) => s.wins >= 3 && s.overall_with - s.overall_without >= 1.5 && s.tests_with >= s.tests_without && s.dims.simplicity.with >= s.dims.simplicity.without - 0.5
const okOld = (s) => s.wins >= 3 && s.overall_with >= 7.6 && s.tests_with >= s.tests_without

const rounds = []
let round = 3
let res = await runRound(round, false)
let sNew = summarize(res.filter(x => x && !x.old)), sOld = summarize(res.filter(x => x && x.old))
rounds.push({ round, new: sNew, old: sOld, results: res })
log(`Round ${round}: NEW ${sNew.wins}W/${sNew.losses}L ${sNew.overall_with} vs ${sNew.overall_without} | OLD ${sOld.wins}W/${sOld.losses}L ${sOld.overall_with} vs ${sOld.overall_without}`)

while (!(ok(sNew) && okOld(sOld)) && round < 5) {
  round++
  phase('Revise')
  await agent(`The Claude skill at ${SKILL} was A/B-tested (Sonnet builders, blind judges). New-area tasks must clear: ≥3/4 wins, overall margin ≥1.5, no test regressions, no simplicity loss. Original tasks (regression check vs fixed baselines) must keep ≥3/4 wins and overall ≥7.6. Latest round (unblinded; "with" = used the skill):

${JSON.stringify(rounds[rounds.length - 1])}

Snapshot first: \`cp -R ${SKILL} ${W}/skill/history/v${round}-pre\`. Inspect the with-skill code in the withDir directories to find WHY it fell short: rules ignored (buried/abstract), missing rules for flagged antipatterns, rules causing ceremony or failures, baseline strengths worth encoding, and wrong/outdated API claims (verify against the vendor/ directories). Revise: promote high-impact rules into SKILL.md with concrete bad→good snippets, sharpen the checklist, cut what caused ceremony. SKILL.md stays under ~240 lines; detail goes to references. Rules must generalise beyond these task domains. Return a concise changelog.`, { label: `revise:r${round}`, phase: 'Revise' })
  res = await runRound(round, true)
  sNew = summarize(res.filter(x => x && !x.old)); sOld = summarize(res.filter(x => x && x.old))
  rounds.push({ round, new: sNew, old: sOld, results: res })
  log(`Round ${round}: NEW ${sNew.wins}W/${sNew.losses}L ${sNew.overall_with} vs ${sNew.overall_without} | OLD ${sOld.wins}W/${sOld.losses}L ${sOld.overall_with} vs ${sOld.overall_without}`)
}

phase('Audit spot-check')
const target = `${E}/runs/r3/webhook-worker-without`
const out = `${E}/audit/v4-webhook.html`
const known = (rounds[0].results.filter(Boolean).find(x => x.task === 'webhook-worker') || {}).without
const auditRun = await agent(`Read the Laravel skill at ${SKILL}/SKILL.md and references/audit-mode.md, then run its audit mode on ${target} (read-only; use the scratch-copy verification step). Write the report to ${out} (mkdir -p) and do not publish it. Return finding counts by severity and whether apply-order verification passed.`, { label: 'audit:webhook', phase: 'Audit spot-check', model: 'sonnet' })
const auditCheck = await agent(`Evaluate the Laravel refactor report at ${out} for ${target}. Check: self-contained/offline; every cited file:line real and correct; apply all suggested fixes literally in the report's apply order to a scratch copy (cp -R to mktemp -d) and run php artisan test; recall against this independent reviewer list: ${JSON.stringify(known ? known.antipatterns : [])}. Be strict.`, { label: 'audit:check', phase: 'Audit spot-check', schema: { type: 'object', properties: {
  self_contained: { type: 'boolean' }, findings: { type: 'number' }, false_positives: { type: 'array', items: { type: 'string' } }, accuracy_issues: { type: 'array', items: { type: 'string' } },
  literal_apply_suite_passes: { type: 'boolean' }, apply_notes: { type: 'string' }, recall_caught: { type: 'number' }, recall_total: { type: 'number' }, missed: { type: 'array', items: { type: 'string' } }, usefulness: { type: 'number' } },
  required: ['self_contained', 'findings', 'false_positives', 'accuracy_issues', 'literal_apply_suite_passes', 'apply_notes', 'recall_caught', 'recall_total', 'missed', 'usefulness'] } })

return { rounds: rounds.map(r => ({ round: r.round, new: r.new, old: r.old, perTask: r.results.filter(Boolean).map(x => ({ task: x.task, old: x.old, winner: x.winner, with: x.with.overall, without: x.without.overall, with_focus: x.with.scores.focus_area, without_focus: x.without.scores.focus_area, with_tests: x.with.tests_pass, without_tests: x.without.tests_pass, with_antipatterns: x.with.antipatterns, rationale: x.rationale })) })), passed: ok(sNew) && okOld(sOld), auditRun, auditCheck }
