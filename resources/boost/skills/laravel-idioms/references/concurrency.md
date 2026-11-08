# Concurrency: races and atomic writes

Applies to contended counters, caps, "one per X" rows, and state changes with side effects. A plain CRUD write that only one user touches needs none of it.

## Claim first, then act

```php
// bad: two requests both pass the check, both write, both send
if (! $invoice->bookings()->whereBelongsTo($user)->exists()) {
    $invoice->bookings()->create([...]);
    Mail::to($user)->send(new BookingConfirmed);
}

// good: the database picks one winner; only the winner has side effects
$booking = $invoice->bookings()->createOrFirst(['user_id' => $user->id], ['note' => $note]);
if ($booking->wasRecentlyCreated) {
    BookingConfirmed::dispatch($booking);   // the repeat request gets the stored booking, no second mail
}
```
- The claim is one of: `createOrFirst()` + `wasRecentlyCreated`, `insertOrIgnore() === 1`, or `where('status', 'pending')->update([...]) === 1`. Charge, send or dispatch only after it succeeded.
- A queued dispatch after the claim can still be lost if the process dies between them. If that is unacceptable, use an outbox row written in the claim's transaction, or claim and enqueue in one `DB::transaction()` on a `database` queue that uses the same connection as the claim and inserts the job before the commit (no `after_commit` on that connection or the dispatch). With Redis, `->afterCommit()` only stops the job being queued for a rolled-back claim: the dispatch can still be lost after the commit, and a queued job can run more than once (a worker crash, a retry), so the job's effects must be idempotent too.

## Unique index as the guard

- Every "one X per Y" rule is a unique index. Validation is a pre-check for a friendly message, nothing more.
- `firstOrCreate()` is `first()` then `createOrFirst()`. `createOrFirst()` only turns a `UniqueConstraintViolationException` into a re-read. With no unique index there is no exception, so it is plain check-then-insert. Add the index in the migration that introduces the lookup.
- Pass only the unique-key columns as the first argument. The re-read uses the same array, so extra columns there make it find nothing and rethrow.
```php
// bad: name is not in the unique key
WishlistItem::createOrFirst(['user_id' => $u, 'sku' => $sku, 'name' => $name]);
// good: unique(user_id, sku)
WishlistItem::createOrFirst(['user_id' => $u, 'sku' => $sku], ['name' => $name]);
```
- NULLs never collide in a unique index (MySQL, Postgres, SQLite; SQL Server differs). A key column that can be NULL means parallel inserts all succeed. Make every key column `NOT NULL`. Use a sentinel (`->default(0)`) only when no foreign key forbids it. On Postgres 15+, `->unique([...])->nullsNotDistinct()`.
- On Postgres a failed statement aborts the whole open transaction. `createOrFirst()` wraps the insert in a savepoint when a transaction is open. If you hand-roll create-then-catch inside a transaction, wrap the create in its own `DB::transaction()` first. A `try/catch` around `createOrFirst()` is only for mapping a conflict on another index to a 422. It adds no race safety.
- Inside a MySQL `REPEATABLE READ` transaction that already read the table, the fallback re-read may not see the other writer's row. Claim at the start of the transaction, or outside it.
- `updateOrCreate()` is for setting absolute values. Its update branch is a plain `fill()->save()` on a row read earlier, so accumulators lose or double-apply. Use `incrementOrCreate(['day' => $day], 'count')` (unique index required) or a conditional update.
- `upsert()` and `insertOrIgnore()` fire no model events. `upsert()` on MySQL counts 1 per insert, 2 per update, 0 per unchanged row, so the count is not "rows written". An empty `uniqueBy` throws on every driver. MySQL ignores `uniqueBy` and uses whichever unique index conflicts. `insertOrIgnore()` is `INSERT IGNORE` on MySQL and also downgrades other errors (bad data, truncation) to warnings.

## Guarded UPDATEs

```php
// bad: read, compare in PHP, write a computed value
$p = Product::findOrFail($id);
abort_if($p->stock < $qty, 409);
$p->update(['stock' => $p->stock - $qty]);

// good: the limit is in the WHERE; the row count is the decision
$won = Product::whereKey($id)->where('stock', '>=', $qty)->decrement('stock', $qty);
abort_if($won === 0, 409, 'Insufficient stock');
```
- Put the expected old state in the `WHERE` so 0 always means "someone else got there first". To tell "missing" from "already done", look the row up separately afterwards.
- Do not read the count as "the row exists". MySQL counts only rows whose values changed (Laravel sets no found-rows flag). SQLite and Postgres count matched rows. An Eloquent builder `update()` also adds `updated_at`, so on MySQL a repeat update often reports 1 anyway. Depend on neither.
- `$model->increment('attempts')` runs a relative `UPDATE`, but the model then holds the loaded value plus n, not the database value. Never branch on `$model->attempts` afterwards.
```php
// bad: stale in-memory count decides the lockout
$user->increment('failed_logins');
if ($user->failed_logins >= 10) { $user->update(['locked_until' => now()->addMinutes(30)]); }
// good: the second statement tests the database value
User::whereKey($user->id)->increment('failed_logins');
User::whereKey($user->id)->where('failed_logins', '>=', 10)->update(['locked_until' => now()->addMinutes(30)]);
```
- `save()` writes only dirty columns. Assigning a value equal to a stale in-memory value (`update(['attempts' => 0])` on an instance loaded at 0) writes nothing.
- Denormalized counters change in the same transaction as the row, and only when the row change happened. The return value of `delete()`, `detach()` or `insertOrIgnore()` gates the counter:
```php
DB::transaction(function () use ($item) {
    if (Item::whereKey($item->id)->delete() === 1) {
        Folder::whereKey($item->folder_id)->decrement('item_count');
    }
});
```

## Row locks

- `lockForUpdate()` and `sharedLock()` hold only while a transaction is open. Outside one, the lock ends with the statement. Re-read the row with the lock inside the transaction and decide from that instance, never from a model loaded earlier.
```php
DB::transaction(function () use ($id, $amount) {
    $account = Account::lockForUpdate()->findOrFail($id);
    throw_if($account->balance < $amount, new InsufficientFunds);
    $account->decrement('balance', $amount);
});
```
- A cap on a parent that also inserts a child row (seats and bookings, a coupon and its redemptions): `lockForUpdate()` the parent first, then check, insert and increment, inside `DB::transaction($fn, 3)`. Inserting the child first takes a shared foreign-key lock on the parent on InnoDB, so two requests that then `UPDATE` the parent deadlock.
- Use locks for invariants one conditional `UPDATE` cannot express (transfers, "last admin", allocation across rows). Lock several rows in a stable order (`orderBy('id')`) to avoid deadlocks.
- On MySQL `REPEATABLE READ`, do not mix an earlier plain read of a row with a later `FOR UPDATE` read in the same transaction.
- Locking a row that does not exist yet does not reliably serialize creators (on InnoDB it takes gap locks that tend to deadlock). Create it first with a unique index and `insertOrIgnore()`, then lock it, or lock the existing parent row.
```php
DB::table('counters')->insertOrIgnore(['org_id' => $id, 'last_sequence' => 0]);   // org_id is the primary key
$c = DB::table('counters')->where('org_id', $id)->lockForUpdate()->first();
```

## Transactions

- Use the closure form `DB::transaction(fn () => ...)`. After `DB::beginTransaction()`, an early return, `abort()` or exception leaves it open. Under FPM the connection closes and the work rolls back. In a queue worker or Octane it leaks into later writes.
- `DB::transaction($fn, 3)` retries only on concurrency errors (deadlock, SQLSTATE 40001, lock wait timeout, SQLite "database is locked", a few more) and re-runs the whole closure. Nothing else is retried. Set attempts on the outermost transaction that takes locks. A nested transaction with attempts does not retry: it throws `DeadlockException` to the outer one.
- So the closure holds only database work: no HTTP, mail, dispatch, cache or file writes. Run those after it returns, or with `DB::afterCommit(fn () => ...)` (runs at once outside a transaction, discarded on rollback). Observer methods `creating`, `updating`, `saving`, `deleting` are not deferred by `ShouldHandleEventsAfterCommit` from Laravel 12.54; before that they are.
```php
$order = DB::transaction(function () use ($ids) {
    $rows = Stock::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    return Order::create([...]);
}, attempts: 3);
OrderPlaced::dispatch($order);
```
- A transaction that touches one row with no lock rarely needs attempts.
- A `throw` inside the closure rolls everything back. This is how you undo a won claim when a later step fails (`WorkshopFull`).

## Unique validation and write-time conflicts

- `unique:` and `Rule::unique()` are pre-checks. Keep the unique index and map the violation at the write:
```php
try {
    $list = $user->wishlists()->create($request->validated());
} catch (UniqueConstraintViolationException) {
    throw ValidationException::withMessages(['name' => __('validation.unique', ['attribute' => 'name'])]);
}
```
- Do not use the rule as double-submit protection.

## Idempotency keys for writes

- For a retried or double-submitted write, store a key with a unique index (`unique(user_id, idempotency_key)`), claim it with `createOrFirst()` or `insertOrIgnore()`, and return the stored result to the replay.
- Never `Cache::has()` then `Cache::put()`, and never mark the key "seen" before the work succeeded.
- Skip it for naturally idempotent writes (a PUT of full state, an upsert on a natural key) and for reads.
- An outbound POST that creates something is retried only with a key the partner honours.

## Cache races

- `Cache::add($key, $value, $ttl)` is atomic only with a TTL. With a null TTL the repository does `get()` then `put()`. Include the "create the counter" call: `Cache::add($key, 0, now()->addHour())`, then `Cache::increment($key)`. Increments are atomic on redis, memcached and database stores, not on `file` or `array`.
- `forget()` does not stop an in-flight recompute. A reader that queried before your commit can `put()` the old value after your `forget()`, and it stays for the whole TTL (forever with `rememberForever`). `remember()` and `flexible()` have no compare-and-set. For exact values (counts, totals), use a short TTL, or bump a version key that is part of the cache key:
```php
$v = Cache::get("user:{$id}:posts:v", 1);
Cache::remember("user:{$id}:posts:count:{$v}", 600, fn () => $posts->count());
// observer: Cache::add("user:{$id}:posts:v", 1, 86400); Cache::increment("user:{$id}:posts:v");
```
  Versions must never repeat: `increment()` doesn't refresh the key's TTL, so once it expires, `add()` restarts at 1 and old values cached under that number come back. Keep the version durable (`Cache::forever()`, or a `version` column on the parent row), or use a non-repeating id (`Str::ulid()`) per write. Seconds of staleness with a short TTL needs none of this.
- Take `Cache::lock()` before the transaction and release it after the commit. Inside the transaction, a database-store lock row is rolled back with it and blocks other acquirers until the commit.
```php
Cache::lock("orders:{$id}:ship", 30)->block(5, fn () => DB::transaction(fn () => Order::findOrFail($id)->ship()));
```
- The lock's seconds must exceed the worst run of the guarded work. On expiry another process gets the lock while you still work, and your `release()` is owner-checked, so it stays silent. For long work call `$lock->refresh()` or split the work. `block()` throws `LockTimeoutException` (catch it and return 409 or 429). `get()` returns `false` when busy.
- `RateLimiter::attempt()` is not atomic. It runs `tooManyAttempts()`, your callback, then `hit()`, so N concurrent requests all pass before the first hit lands. Comparing the value `RateLimiter::increment($key, $decay)` returns, on a store with atomic increment, closes most of that gap. Its fallback `put()` after a key expires mid-call can still overwrite a newer count, so a cap that must never be exceeded (money, stock) is a guarded `UPDATE` instead:
```php
abort_if(RateLimiter::increment("reset:{$user->id}", 3600) > 3, 429);
```

## Optimistic concurrency

Use it when last-write-wins would hide a conflict (a shared document, a price, a config). Skip it for append-only or single-owner data.
```php
$ok = Doc::whereKey($id)->where('version', $request->integer('version'))
    ->update(['body' => $body, 'version' => DB::raw('version + 1')]);
abort_if($ok === 0, 409, 'Changed by someone else');
```
- Send the version to the form and back. Eloquent has no built-in version check: two stale `save()` calls on different columns both succeed, and on the same column the last one wins.

## Testing races

- SQLite (the skeleton's test database) compiles `lockForUpdate()` and `sharedLock()` to nothing. A passing SQLite test proves nothing about a lock. Unique indexes and conditional updates are enforced there, so test those by calling the operation twice in a row, and assert one row, one dispatch, final state.
- For code that reads the model outside its own transaction, simulate the interleaving without threads or `sleep()`: a one-shot hook writes the competing change between your read and your write. Guard it, and clear it in `tearDown`:
```php
it('does not oversell when a competing order lands between read and write', function () {
    $product = Product::factory()->create(['stock' => 5]);
    $fired = false;
    $base = DB::transactionLevel();   // RefreshDatabase already holds one level
    Product::retrieved(function () use (&$fired, $base) {
        if (! $fired && DB::transactionLevel() === $base) {   // fire once, outside the code's own transaction
            $fired = true;
            DB::table('products')->update(['stock' => 1]);
        }
    });

    expect(fn () => app(Checkout::class)->buy($product->id, 3))->toThrow(OutOfStock::class);
    expect($product->fresh()->stock)->toBe(1);
})->afterEach(fn () => Product::flushEventListeners());
```
- `DB::listen()` works the same way for code that never loads a model: match the SQL, run the competing write once, then assert. A hook that never fires is fine when the code is one conditional `UPDATE`. Assert only the final state.
- For counter bugs, load two stale instances before either writes, then run both and assert the total.
- A test of code whose correctness is the lock itself runs on a real MySQL or Postgres service in CI. Never report "lock tested" from SQLite.
- A file SQLite database in production on PHP 8.4+: set `transaction_mode` to `IMMEDIATE` and a `busy_timeout`, or a read-then-write transaction can fail with "database is locked".

Evidence: `Illuminate/Database/Eloquent/Builder.php` (`firstOrCreate`, `createOrFirst`, `updateOrCreate`, `incrementOrCreate`, `update`, `withSavepointIfNeeded`), `Eloquent/Model.php` (`incrementOrDecrement`), `Eloquent/Concerns/HasEvents.php`, `Eloquent/Relations/HasOneOrMany.php`, `Database/Concerns/ManagesTransactions.php`, `Database/{ConcurrencyErrorDetector,DeadlockException,UniqueConstraintViolationException,SQLiteConnection}.php`, `Database/Query/Builder.php` (`insertOrIgnore`, `upsert`, `lockForUpdate`, `sharedLock`), `Database/Schema/{IndexDefinition,Grammars/PostgresGrammar}.php`, `Cache/{Repository,Lock,DatabaseLock,RateLimiter}.php`; `laravel/docs/{database,queries,cache,eloquent,testing,database-testing}.md`.
