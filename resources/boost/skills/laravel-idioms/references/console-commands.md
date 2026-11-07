# Console commands

## Anatomy (Laravel 13)

```php
use Illuminate\Console\Attributes\{Description, Signature};
use Illuminate\Console\Command;

#[Signature('stock:sync {supplier : Supplier slug} {--limit=500} {--dry-run}')]
#[Description('Pull stock levels from a supplier feed')]
class SyncStock extends Command
{
    public function handle(SyncSupplierStock $sync): int
    {
        $result = $sync($this->argument('supplier'), (int) $this->option('limit'), dryRun: $this->option('dry-run'));

        $this->components->info("{$result->updated} updated, {$result->failed} failed.");

        return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```
- Attributes from `Illuminate\Console\Attributes`: `#[Signature]` (takes `aliases:` too), `#[Description]`, `#[Aliases([...])]`, `#[Help]`, `#[Usage]` (repeatable examples), `#[Hidden]`. Never an attribute plus the matching `$signature`/`$description` property. Pre-13 or property-style codebases keep properties.
- `make:command` emits both attributes and an untyped `handle()`: add `: int`.
- `app/Console/Commands` is auto-discovered. Other paths go in `->withCommands([...])` in `bootstrap/app.php`; don't also register discovered commands.
- App commands are constructed on every `artisan` call (only `#[AsCommand]` framework commands load lazily). The constructor does nothing: no `config()`, no queries, no injected services. Inject into `handle()`.

## Closure or class

- Closure in `routes/console.php` (`Artisan::command('sig', fn () => ...)->purpose('...')`) for a few lines with no options to branch on and nothing destructive.
- A class as soon as it has options, injected services, a failure path worth testing, or needs `ConfirmableTrait`/`Isolatable` (closures can't use traits or interfaces).
- Closure parameters match argument and option names exactly: `{user}` fills `$user`, but `{--dry-run}` never fills `$dryRun`. Read options with `$this->option('dry-run')`.

## Signature syntax

| Syntax | Meaning |
|---|---|
| `{user}` / `{user?}` / `{user=all}` | required / optional / optional with default |
| `{ids*}` / `{ids?*}` | required array / optional array |
| `{--force}` | boolean flag (no `=`) |
| `{--days=}` / `{--days=30}` | value option / value with default |
| `{--Q\|queue=}` | shortcut `-Q` |
| `{--id=*}` | repeatable `--id=1 --id=2` |
| `{user : The user id}` | help text after ` : ` |

- `{--force=false}` is a value option whose default is the **string** `'false'`, which is truthy. Booleans have no `=`.
- Option and argument values are strings or null: cast (`(int) $this->option('days')`) and validate before use. Bad input → `return self::INVALID` with a message.

## Thin command

- The command parses input, calls one action or dispatches jobs, reports, and returns an exit code. Logic that a controller, job or another command also needs lives in an action (`SyncSupplierStock`); a one-query command needs no action (no ceremony, SKILL §12).
- Read config inside `handle()`. Check required config first and `$this->fail('Set services.fathom.token.')`.
- laravel.io's `UpdateArticleViewCounts` is a collections example, not a command exemplar: config in the constructor, `void` return after an error, `->get()` over all articles.

## Exit codes

```php
public function handle(InvoiceGateway $gateway): int
{
    $failed = 0;

    Invoice::query()->unsent()->lazyById(200)->each(function (Invoice $invoice) use ($gateway, &$failed) {
        try {
            $gateway->send($invoice);
        } catch (Throwable $e) {
            report($e);
            $failed++;
        }
    });

    return $failed > 0 ? self::FAILURE : self::SUCCESS;
}
```
- `self::SUCCESS` (0), `self::FAILURE` (1), `self::INVALID` (2, bad input). The return value is cast with `(int)`: `return true` exits **1**, `return false` and a bare `return;` exit **0**.
- `$this->fail('message')` from any depth prints the error and exits 1. `$this->fail($e)` rethrows a `Throwable`; an uncaught exception is reported and exits 1.
- Per-row work that should continue past one bad row: `report($e)` each, count, return FAILURE at the end. A row that must stop the run: let it throw.
- `$this->error()` only prints; it never changes the exit code.
- `handle()` with no failure path may stay `void`; anything that can fail returns `int`.

## Output

- By meaning: `$this->components->info()`, `->warn()`, `->error()`, `->success()`, `->twoColumnDetail()`, `->bulletList()`; `$this->table($headers, $rows)` for rows.
- `$this->components->task('Rebuilding index', fn () => $indexer->rebuild())` prints FAIL only when the closure throws. A `false` return still prints DONE, so throw on failure.
- Diagnostics behind verbosity: `$this->line("Synced {$id}", verbosity: 'vv')`, `$this->info($msg, 'v')`, `if ($this->output->isVerbose())`. Default output stays short enough to read in cron mail and logs.

## Prompts and non-interactive runs

- New interactive input uses Laravel Prompts (`text()`, `select()`, `search()`, `confirm()`).
- Defaults differ: Prompts' `confirm()` defaults to **true**; `$this->confirm()` and `$this->components->confirm()` default to **false**. Before anything destructive, pass `default: false` to `confirm()`.
- Cron, `--no-interaction` and no TTY are non-interactive: every prompt returns its default without asking, and a required prompt with no valid default throws `NonInteractiveValidationException`. Every prompted value is also an argument or option:
```php
$tenant = $this->option('tenant') ?? ($this->input->isInteractive()
    ? select('Which tenant?', Tenant::pluck('name', 'id')->all())
    : $this->fail('Pass --tenant when running non-interactively.'));
```
- Missing required arguments → `implements PromptsForMissingInput` (with `promptForMissingArgumentsUsing()` for custom prompts), not hand-written `ask()`. It prompts only when interactive.

## Destructive and bulk-mutating commands

```php
#[Signature('tenants:purge {--days=90} {--dry-run} {--force : Skip the production confirmation}')]
#[Description('Delete tenants cancelled more than --days ago')]
class PurgeCancelledTenants extends Command
{
    use ConfirmableTrait, Prohibitable;

    public function handle(): int
    {
        if ($this->isProhibited() || ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $query = Tenant::query()->cancelledBefore(now()->subDays((int) $this->option('days')));

        if ($this->option('dry-run')) {
            $this->components->info("{$query->count()} tenants would be deleted.");

            return self::SUCCESS;
        }

        $query->lazyById()->each(fn (Tenant $tenant) => $tenant->delete());

        return self::SUCCESS;
    }
}
```
- `confirmToProceed()` asks only when the environment is `production`; elsewhere it returns true. `--force` skips it only if the signature declares `{--force}`. Always-ask: `$this->confirmToProceed('Deletes every tenant', fn () => true)`.
- A non-interactive production run without `--force` is cancelled: return FAILURE so cron alerts. A command that is also run by hand but scheduled passes `--force` from the schedule on purpose. A cleanup that only the scheduler ever runs gets no confirmation, `--force` or `--dry-run` at all.
- `Prohibitable`: `PurgeCancelledTenants::prohibit($this->app->isProduction())` in `AppServiceProvider::boot()`; the flag is static per class. `DB::prohibitDestructiveCommands($this->app->isProduction())` covers only `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` and `db:wipe`.
- `--dry-run` reports what would change (counts, ids, a sample) and writes nothing, including no claim stamps or queued jobs.
- Not for read-only commands, reports, scheduler-only cleanups, or idempotent syncs that only upsert: no `--force`, no confirmation, no `--dry-run`.

## Big tables

```php
// bad: loads every row; offset paging skips rows once status changes
User::where('status', 'pending')->get()->each->activate();
User::where('status', 'pending')->chunk(500, fn ($users) => $users->each->activate());
// good
User::where('status', 'pending')->lazyById(500)->each(fn (User $user) => $user->activate());
User::where('status', 'pending')->update(['status' => 'active']);   // no per-row logic → one statement
```
- `lazyById()`, `chunkById()` or `eachById()` whenever the loop changes a column the query filters on. `chunk()` and `each()` (pages of 1000) page by offset and skip rows that leave the filter.
- Progress bars take an int: `$bar = $this->output->createProgressBar($query->count())`, then `$bar->advance()` in the loop and `$bar->finish()`. Passing a `lazyById()` collection to `withProgressBar()` counts it by iterating, which runs the whole query an extra time.
- Select only the columns you use and drop default eager loads (`->without('author')`); never collect models into an array across chunks.
- A command that drains a work table each run caps it: `->limit(300)`, a deadline (`if (now()->gt($deadline)) return false;` inside `each`), and a claim step (see `references/scheduling.md`).

## Long-running and exclusive commands

```php
class ConsumeFeed extends Command implements Isolatable
{
    private bool $shouldKeepRunning = true;

    public function isolationLockExpiresAt(): DateTimeInterface|DateInterval
    {
        return now()->addMinutes(30);
    }

    public function handle(): int
    {
        $this->trap([SIGTERM, SIGINT], fn () => $this->shouldKeepRunning = false);

        while ($this->shouldKeepRunning && ($batch = $this->nextBatch())) {
            $this->process($batch);
        }

        return self::SUCCESS;
    }
}
```
- A trapped signal no longer stops the process: the loop must check the flag and exit between units of work. Commands that finish in seconds need no trap.
- `Isolatable` adds `--isolated`; only runs started with it take the lock (`Schedule::command('feed:consume --isolated')`). Without `isolationLockExpiresAt()` a killed run holds it for 1 hour. `isolatableId()` scopes it per argument. The lock uses the default cache store, so several servers need a shared one.
- A run skipped because the lock is held exits 0. Set `protected $isolatedExitCode = self::FAILURE` (or pass `--isolated=1`) only when a skip means something is wrong, not for a consumer whose next tick is expected to find the lock held.
- Only commands whose overlapping runs would corrupt data get `Isolatable`; a scheduled command can use `withoutOverlapping($minutes)` instead.

## Calling commands from code

- `Artisan::call('carts:prune', ['--days' => 30, '--force' => true])` runs synchronously in the caller's process and returns the exit code: check it. From a controller, call the action or dispatch a job instead.
- `Artisan::queue('carts:prune', [...])->onQueue('maintenance')` runs it on a worker. Inside a command use `$this->call()` / `$this->callSilently()`.

## Testing

```php
it('deletes old cancelled tenants and keeps recent ones', function () {
    $old = Tenant::factory()->cancelled(now()->subDays(100))->create();
    $recent = Tenant::factory()->cancelled(now()->subDays(10))->create();

    $this->artisan('tenants:purge')->assertSuccessful();

    expect(Tenant::find($old->id))->toBeNull()
        ->and(Tenant::find($recent->id))->not->toBeNull();
});

it('changes nothing on a dry run', function () {
    Tenant::factory()->cancelled(now()->subDays(100))->create();

    $this->artisan('tenants:purge --dry-run')->expectsOutputToContain('1 tenants would be deleted')->assertSuccessful();

    expect(Tenant::count())->toBe(1);
});

it('asks for confirmation in production', function () {
    app()->detectEnvironment(fn () => 'production');
    Tenant::factory()->cancelled(now()->subDays(100))->create();

    $this->artisan('tenants:purge')
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertFailed();

    expect(Tenant::count())->toBe(1);
});
```
- Every `$this->artisan()` chain ends in `assertSuccessful()`, `assertFailed()` or `assertExitCode(n)`; without one the exit code is never checked and a failing command passes. Then assert the side effect.
- Interaction: `expectsQuestion($q, $answer)`, `expectsConfirmation($q, 'yes')` (answer defaults to `'no'`), `expectsChoice($q, $answer, $options)`, `expectsSearch($q, $answer, $search, $answers)`. Prompts run interactively under test, so these work for Laravel Prompts too.
- Output: `expectsOutput()` (exact line), `expectsOutputToContain()`, `doesntExpectOutput()`, `doesntExpectOutputToContain()`, `expectsTable()`. Laravel Prompts' `info()`/`warning()`/`error()`/`alert()`/`intro()`/`outro()`/`table()` need `expectsPromptsInfo()` … `expectsPromptsTable()`; `expectsOutput()` doesn't match them.
- Cover each branch: success, `--dry-run` (database unchanged), `--force` in production, cancelled confirmation, invalid input (`assertExitCode(Command::INVALID)`), and a partial failure (one row throws → `assertFailed()`, other rows still processed). Batch commands get more rows than the chunk size.
- In Pest, `$this->artisan()` inside the test closure; no PHPUnit class.

Evidence: `Illuminate/Console/{Command,Parser,ConfirmableTrait,Prohibitable,CacheCommandMutex,Application}.php`, `Console/Attributes/*`, `Console/Concerns/{InteractsWithIO,InteractsWithSignals,PromptsForMissingInput,ConfiguresPrompts,CallsCommands}.php`, `Console/View/Components/{Task,Confirm,Factory}.php`, `Contracts/Console/{Isolatable,PromptsForMissingInput}.php`, `Foundation/Console/{ClosureCommand,Kernel}.php`, `Foundation/Console/stubs/console.stub`, `Foundation/Configuration/ApplicationBuilder.php`, `Database/Concerns/BuildsQueries.php`, `Support/Facades/DB.php`, `Testing/PendingCommand.php`; laravel/prompts `src/helpers.php`, `Prompt.php`, `Concerns/Interactivity.php`; `laravel/docs/artisan.md`, `console-tests.md`, `prompts.md`; laravel/laravel `routes/console.php`; laravel.io `app/Console/Commands/UpdateArticleViewCounts.php`.
