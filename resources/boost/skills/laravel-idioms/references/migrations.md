# Migrations and schema

## Foreign keys

```php
// bad: no foreign key is created and the column is a signed INT; nothing errors
$table->integer('customer_id')->foreignId('customers')->constrained();
// bad: nullable() lands on the FK definition; the column stays NOT NULL
$table->foreignId('customer_id')->constrained()->nullable();
// good
$table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
```
- Column and FK chains are not type-checked. `Fluent` turns any unknown or misordered method into a silent attribute. Put every column modifier (`nullable`, `default`, `after`, `unsigned`) before `constrained()`, because `constrained()` returns the FK definition, not the column.
- Start an FK column from `foreignId()`, `foreignUuid()`, `foreignUlid()` or `foreignIdFor()`. Never chain `foreignId()` or `constrained()` onto `integer()` or `unsignedBigInteger()`. A plain `unsignedBigInteger()` plus an explicit `$table->foreign()->references()->on()` is valid for an odd column name.
- Match the parent's key type. `foreignId()` is `UNSIGNED BIGINT`. For a UUID or ULID parent use `foreignUuid()` / `foreignUlid()`. `foreignIdFor(Customer::class)` (and, from Laravel 13, `foreignUuidFor` and `foreignUlidFor`; on 11 and 12 use `foreignUuid('customer_id')->constrained()`) read the model's key type, table and key name, so a bare `->constrained()` works even for a column named `created_by`.
- With `foreignId('owner_id')` the table is guessed from the name (`owners`). Pass the table when the name does not follow `{singular}_id`: `->constrained('users')`.
- After writing the migration, run `php artisan migrate --pretend` and check the `foreign key` clause and `null` / `not null` in the SQL.

### ON DELETE

Choose it on purpose. A bare `constrained()` means restrict (no action), and agents then "fix" the integrity error with manual child deletes in controllers.

| Relation | Use |
|---|---|
| Owned children (line items, tokens, pivots) | `cascadeOnDelete()` |
| Reference that must outlive the parent | `nullable()` + `nullOnDelete()` (the column must be nullable) |
| History, ledger or lookup rows many records point at | `restrictOnDelete()` (write it even when the parent is never deleted, so the intent shows) |

- `cascadeOnDelete()` is a database delete. It fires no Eloquent events or observers, and it never runs for a `SoftDeletes` parent, because a soft delete is an `UPDATE`. Do not rely on it for cache busting or audit rows.
- Adding an FK to a populated column checks every row. Null or clean the orphans first.
- To remove an FK column use `dropConstrainedForeignId('customer_id')`. `dropForeignIdFor()` drops only the column, which fails on MySQL while the constraint exists.

## `->change()` restates everything

```php
// bad: the column becomes NOT NULL again, and the default and length are lost
$table->string('note')->change();
// good: every attribute you keep is written again
$table->string('note', 500)->nullable()->default('')->change();
```
- `change()` redefines the whole column. Omitting `nullable()` makes it NOT NULL, omitting `default()` drops the default, and `bigInteger('x')->nullable()->change()` on an `UNSIGNED` column drops `UNSIGNED`. (`foreignId()` and `unsignedBigInteger()` already carry it.)
- Read the current definition first (`Schema::getColumns()` or the schema dump) and copy it.
- `change()` does not touch indexes or FKs. Add them in the same call chain only if you mean to.
- In `down()` restate the original attributes. Flipping only `nullable(false)` loses the rest.
- On MySQL `change()` compiles to `modify`, and a type change can rebuild the table. Skip cosmetic DDL on big tables, such as reordering columns with `after()`.

## Changing a table that has rows and running code

Applies when the table holds production rows and old code keeps running during the deploy. A new table, a column that never shipped, a pre-launch app, or a single instance with a maintenance window needs none of this.
```php
// release 1, migration A: add nullable (or with a constant default)
$table->string('currency', 3)->nullable()->after('price');
// release 1, command: backfill (below), app writes both columns
// release 2, migration B: tighten, restating everything
$table->string('currency', 3)->default('USD')->change();
```
- Adding a required column in one step is engine dependent: Postgres and SQLite fail on populated rows, MySQL silently fills `''` or `0`. Old code that inserts without the column also hits the NOT NULL. Add nullable or with a default, backfill, then tighten.
- Small or pre-launch tables may keep all three steps in one migration. Hot tables ship them as separate deploys.
- Rename: add the new column, write both, backfill, read the new one, drop the old one in a later release. Never rename in the same deploy as the code that starts using the new name.
- Drop: release 1 removes every read and write (and `$fillable`, casts, resources, queued job payloads). Release 2 drops the column. `dropColumn()` and `renameColumn()` run at once and break `select old_name` the moment they land.
- A default in the migration is also the safety net for old code that still inserts without the column.

## Backfills

```php
// bad: models in a migration break when the model later changes, fire events, touch updated_at
Customer::query()->each(fn (Customer $c) => $c->update(['currency' => 'USD']));
// good: set-based, updated_at untouched
DB::table('customers')->whereNull('currency')->update(['currency' => 'USD']);
// good: row-dependent values, paged by key (safe while updating the filtered rows)
DB::table('customers')->whereNull('slug')->chunkById(1000, function ($rows) {
    foreach ($rows as $row) {
        DB::table('customers')->where('id', $row->id)->whereNull('slug')->update(['slug' => Str::slug($row->name)]);   // keep a slug the app wrote meanwhile
    }
});
```
- A migration is frozen history and a model is living code. Renames, global scopes, `SoftDeletes`, encrypted or enum casts, observers and `$fillable` all change what a model does. Use `DB::table()`.
- Prefer one `UPDATE` (or `UPDATE ... SELECT`) when it can say it. Use `chunkById()`, not `chunk()`, when the loop changes the column it filters on.
- A big table gets the backfill in a re-runnable Artisan command (select `whereNull(...)`, so a second run does nothing). Keep it out of the migration, and out of the DDL's migration file.
- Raw `DB::table()->update()` leaves `updated_at` alone. Set it only when downstream sync or caches key on it.
- Seeding a few lookup rows with `insertOrIgnore()` keyed on a natural key is fine. Hard-coded ids and one-day data fixes belong in a reviewed one-off command.

## Never rewrite history

- Never edit a migration that ran anywhere shared. The migrator skips any name already in the `migrations` table, so the edit changes nothing on staging or production, while fresh test databases get the new shape. Add a new dated migration instead.
- Editing is fine for a migration that exists only on your unmerged branch (`migrate:fresh` locally).
- No `truncate()`, `delete()` or hard-coded ids to make a change pass. Replace them with a transform that keeps data: dedupe with a keep rule, backfill, map old to new.

## Unique indexes and NOT NULL keys

```php
$table->unique(['coupon_id', 'user_id']);   // columns NOT NULL
Redemption::upsert($rows, uniqueBy: ['coupon_id', 'user_id'], update: ['redeemed_at']);
```
- Every "one X per Y" rule is a unique index. `Rule::unique` and `exists()` are only a friendly pre-check. Let the writer lean on the index (`createOrFirst`, `upsert`, `insertOrIgnore`, or catch `UniqueConstraintViolationException`).
- Every column in that index is NOT NULL. MySQL, Postgres and SQLite allow repeated NULLs in a unique index, so a nullable key column stops constraining those rows.
- On a `SoftDeletes` table, `unique(['email', 'deleted_at'])` does not make live rows unique (all live rows have `deleted_at` NULL). A plain `unique('email')` counts soft-deleted rows. For "unique among live rows" use a partial unique index (Postgres, SQLite raw SQL) or a helper column that is NULL when deleted.
- On a populated table, dedupe with an explicit keep rule (`ROW_NUMBER()`, keep the latest) before adding the index. Never truncate.
- `upsert()` needs that unique or primary index on every database except SQL Server.

## Index design

```php
// where customer_id = ? and status = ? order by created_at
$table->index(['customer_id', 'status', 'created_at']);
```
- Index what a real query filters, joins and sorts on. Add the index in the migration that introduces the query (list filter, job claim `where status = ? and run_at <= ?`).
- Equality columns first, then the range or sort column. A composite index serves its leftmost prefixes, so drop a lone index on its first column.
- MySQL creates an index for each FK automatically. Postgres and SQLite do not, so a hot FK there needs its own `index()`. Do not add an index that duplicates the leftmost prefix of one you have.
- Do not index every column, a boolean alone, or a write-heavy table speculatively.
- `morphs()` already adds the `(type, id)` index. `softDeletes()` adds none: if lists always filter `deleted_at is null`, put it last in the composite you already need.
- The default name is `{table}_{cols}_{type}`. A composite on a long table name can pass MySQL's 64-character limit, so pass a name as the second argument.

## Not transactional on MySQL and SQLite

- Only the Postgres and SQL Server grammars wrap `up()` in a transaction. On MySQL, MariaDB and SQLite a failure halfway leaves earlier statements applied and the migration unrecorded. A rerun then fails with "column already exists".
- Keep one DDL concern per migration file on MySQL. Wrap data changes you need atomic in `DB::transaction()` yourself.
- On Postgres, set `public $withinTransaction = false;` for long data jobs and for `->online()` index builds.
- Do not "fix" a half-applied file by sprinkling guards. Repair the state, or split the migration.
- On a big table, one DDL step per migration (add the column; add the index; add the foreign key), so a failure leaves a state the next run can continue from. Create the composite index that leads with the foreign key column before the foreign key: MySQL then reuses it instead of building a second single-column index over every row. `down()` drops every index and key the `up()` created, in reverse order.

## Online DDL on hot MySQL tables

Applies to large tables written all day. Skip it for small ones.
```php
Schema::table('events', function (Blueprint $table) {
    $table->string('source', 40)->nullable()->instant();   // appended column, no rebuild
    $table->index(['account_id', 'created_at'])->inplace()->lock('none');
});
// Postgres: $table->index('email')->online();   with $withinTransaction = false
```
- `->instant()` (MySQL) adds or changes a column without a rebuild. It appends to the end, so it cannot combine with `after()` or `first()`. `->lock('none')` allows concurrent reads and writes. `->inplace()` goes on index and FK operations, and for an FK the foreign key checks must be off.
- If MySQL cannot honour the algorithm or lock, it raises an error instead of blocking. That failure is the point: fall back to a planned window or a tool such as gh-ost.
- `->online()` works on Postgres (`CONCURRENTLY`) and SQL Server.

## `down()`

```php
public function down(): void
{
    Schema::table('comments', fn (Blueprint $table) => $table->dropConstrainedForeignId('post_id'));
}
// irreversible data step
public function down(): void
{
    throw new RuntimeException('Irreversible: rows were deduplicated.');
}
```
- The migrator runs `down()` only if the method exists, then deletes the record anyway. An empty `//` body (the stub's default) reports success and leaves the schema changed.
- Write the real inverse in reverse order, restoring what `change()` altered. Otherwise throw. Do not build data-restoring `down()` methods for backfills.
- A team that only rolls forward may keep `down()` minimal, but never empty.

## Enum columns, defaults, timestamps

- Prefer `string('status', 20)` cast to a backed enum over `$table->enum()`. `enum()` is a native ENUM only on MySQL. Postgres and SQLite make `varchar` plus a `CHECK`, so a new case means altering a constraint, and the SQLite test database behaves unlike production. Validate with `Rule::enum` either way.
```php
$table->string('status', 20)->default(OrderStatus::Pending);   // ->default(OrderStatus::Pending->value) is the same
```
- If the set is closed, tiny and you want the database to enforce it, `enum('status', OrderStatus::cases())` is fine, but each new case is a new migration that restates the whole list with `change()`.
- `Order::create([...])` returns the attribute as null for a database-only default until `refresh()`. Mirror the default in the model's `$attributes` or the factory.
- `timestamps()` and `softDeletes()` are nullable. Rows from `DB::table()->insert()`, `insertOrIgnore()`, raw SQL and migration seeding get NULL `created_at`, and `whereBetween('created_at')` then drops them. Pass `now()` in raw inserts, or use `->useCurrent()` on a table mostly written outside Eloquent. Eloquent `upsert()` sets timestamps; the query builder's does not.

## Guards and renames

- No `Schema::hasColumn()`, `hasTable()` or `hasIndex()` scattered through app migrations to make them "idempotent", and never branch on `App::runningUnitTests()`. A migration runs once per environment.
- Guards are right in a package's published migration (unknown host app) and in a recovery migration for known drift, with a comment naming the drift. Branch on the driver only to skip raw SQL SQLite cannot run.
- Renaming a table does not rename its indexes or FKs. Their stored names still embed the old table, and a later `dropIndex(['col'])` computes the new name and throws. `renameIndex()` them in `up()` and `down()`, or give indexes explicit names from the start.

## Schema dumps and deploys

- `php artisan schema:dump` writes `database/schema/{connection}-schema.sql`. `migrate` loads it only when no migration has ever run on that database, then runs the rest. Commit it, and regenerate it from the engine your tests use (`schema:dump --database=testing`). Do not hand-edit it, and do not dump from a database with unmerged migrations applied.
- `--prune` deletes the whole `database/migrations` directory. Use it only when asked.
- Two servers deploying at once: `php artisan migrate --force --isolated`, run from one place in the pipeline. It takes an atomic cache lock, so the cache store must be shared. A second run exits 0 without migrating, so a failed first run can look green: check the first run's result.
- `migrate --pretend` prints the SQL for a risky change. `migrate:fresh`, `refresh`, `reset`, `rollback`, `db:wipe` and `schema:dump` are blocked in production only if the app calls `DB::prohibitDestructiveCommands(app()->isProduction())`. Do not assume it does, and never propose them against production.

Evidence: `Illuminate/Database/Schema/{Blueprint,ColumnDefinition,ForeignIdColumnDefinition,ForeignKeyDefinition,IndexDefinition,Builder}.php`, `Schema/Grammars/{Grammar,MySqlGrammar,PostgresGrammar,SQLiteGrammar,SqlServerGrammar}.php`, `Migrations/{Migrator,Migration}.php` and `stubs/migration.stub`, `Console/Migrations/MigrateCommand.php`, `Console/{DumpCommand,Prohibitable}.php`, `Concerns/BuildsQueries.php` (`chunkById`, `lazyById`), `Eloquent/Builder.php` (`createOrFirst`, `upsert`), `Support/Fluent.php`; `laravel/docs/migrations.md`, `eloquent.md`; laravel/laravel `database/migrations/0001_01_01_000000_create_users_table.php`.
