# Redis and Valkey connections

Applies when the cache, sessions, queue, locks or rate limiter use `redis`. Valkey speaks the same protocol, so "Redis" below covers both. Versions are Laravel 11.x, 12.x and 13.x unless a rule says otherwise.

## Client choice

phpredis is the skeleton default (`REDIS_CLIENT=phpredis`) and needs the extension in the image (`pecl install redis`, then enable it). Predis is pure PHP (`composer require predis/predis`: `^2.3` on 11.x, `^2.3|^3.0` on 12.x and 13.x). The Laravel docs recommend phpredis for heavy use. Key names differ, and a key meant for the other client is ignored without an error:

| Setting | phpredis 6.x | predis 2.x / 3.x |
|---|---|---|
| connect timeout | `timeout` (unset: `0.0`) | `timeout` on the connection array (Predis default 5 s) |
| read timeout | `read_timeout` | `read_write_timeout` |
| TLS options | `context` (`['stream' => [...]]`) | `ssl` |
| persistent | `persistent` + `persistent_id` | `persistent` + `conn_uid`, both per connection |
| retries | `max_retries`, `backoff_*` (ms) | `retry` (predis 3.4+; microseconds) |

- Under predis the skeleton's `backoff_*` keys do nothing, and `max_retries` does nothing unless `retry` is set (13.19+ builds predis's `Retry` from a `retry` array plus `max_retries`, default 0), and neither does `options.persistent` (`REDIS_PERSISTENT`): predis reads `persistent` from the connection array. Laravel hands the whole connection array to predis as its parameters.
- `prefix` is a predis client option: put it in `options` (or the connection's `prefix`), not in `options.parameters`.

## Connection config

```php
// bad: REDIS_SCHEME is never read, so this connects in plain text. Timeouts unset, so a dead node holds the request for 60 s
'default' => ['host' => env('REDIS_HOST'), 'port' => env('REDIS_PORT', '6379'), 'password' => env('REDIS_PASSWORD'), 'database' => '0'],
// good (phpredis): config/database.php
$shared = [
    'scheme' => env('REDIS_SCHEME') ?: 'tcp',              // 'tls'. ?: so an empty value isn't kept. The skeleton has no scheme key: add it to EVERY connection
    'host' => env('REDIS_HOST', '127.0.0.1'),              // the name in the server certificate, not an IP or a CNAME
    'port' => env('REDIS_PORT', '6379'),
    'username' => env('REDIS_USERNAME'),                   // ACL user; AUTH is sent only when the password is not empty
    'password' => env('REDIS_PASSWORD'),
    'timeout' => (float) (env('REDIS_TIMEOUT') ?: 2.0),            // ?: not a default arg: an empty env value must not become 0
    'read_timeout' => (float) (env('REDIS_READ_TIMEOUT') ?: 2.0),
    'url' => env('REDIS_URL'),                             // keep the skeleton's url and backoff keys, or they silently stop working
    'max_retries' => (int) env('REDIS_MAX_RETRIES', env('REDIS_PERSISTENT', false) ? 0 : 3),   // 0 only with persistent connections (below)
    'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
    'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
    'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
];

'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'options' => ['cluster' => env('REDIS_CLUSTER', 'redis'), 'prefix' => env('REDIS_PREFIX', 'app:'), 'persistent' => env('REDIS_PERSISTENT', false)],
    'default' => [...$shared, 'database' => env('REDIS_DB', '0')],
    'cache' => [...$shared, 'database' => env('REDIS_CACHE_DB', '1')],
],
```
Predis: `read_write_timeout` for `read_timeout`, `ssl => ['peer_name' => ...]` for `context`, `persistent => true` plus a distinct `conn_uid` per connection, `retry` (with `max_retries`, 13.19+ and predis 3.4+) instead of `backoff_*`, `timeout` on the connection array. Keep `'tcp'` as the `scheme` fallback: predis rejects a null or empty scheme (`Unknown connection scheme: ''`, predis 2.4.1 and 3.8.0), and an empty `scheme` makes phpredis connect to `://host`.

- **`REDIS_SCHEME=tls` does nothing until `scheme` is a key on the connection** (11.x to 13.x; only phpredis and predis read it from there). A host written `tls://name` works too (phpredis natively, predis since 11.39), but then leave `scheme` unset: 11.x and 12.x build `tcp://tls://name`, and 13.12+ throws when the two disagree. So with a `'tcp'` fallback, `REDIS_HOST` is the bare name and `REDIS_SCHEME=tls` turns TLS on. A `scheme` line that already works stays as it is.
- `url` overrides `scheme`, `host`, `port`, `username`, `password` and `database` from its parts. `redis://` means tcp even over `'scheme' => 'tls'`; `rediss://` means tls (`valkeys://` too from 13.33). The skeleton hands one `REDIS_URL` to `default` and `cache`, so a `/db` path puts both in one database, and `cache:clear` then runs FLUSHDB on the queue's database.
- TLS checks the certificate against the host string, so an IP or a CNAME missing from the certificate fails. Connect with a name in the certificate, or set `peer_name` (phpredis `'context' => ['stream' => ['peer_name' => ...]]`; 13.5+ also takes a flat array or an `ssl` key there). A private CA needs `cafile`. **Never set `verify_peer_name => false`.** `verify_peer => false` alone doesn't skip the name check.
- `username` is in the 11.x to 13.x skeleton. A username with no password sends no AUTH under either client.
- `database`: phpredis SELECTs whenever it is set, `0` included. Cluster mode (ElastiCache cluster mode enabled or Serverless, Azure clustered Premium) allows only db 0 on Redis 8 and Valkey 8: `SELECT 1` fails, and the skeleton's `cache` connection is db 1. Set `REDIS_CACHE_DB=0` and separate by prefix (flush then hits everything). Valkey 9 allows more with `cluster-databases`.
- Keys on the server are connection prefix (`REDIS_PREFIX`, an `options` key) + store prefix (`cache.prefix`) + key. Locks use the store's `lock_connection` (skeleton: `default`). Sessions use `default` unless `SESSION_CONNECTION`. Horizon copies its connection and swaps in `HORIZON_PREFIX`. An ACL key pattern must match the connection prefix, and Horizon's own keys too.
- Each named connection is its own socket, AUTH and SELECT. With the skeleton's split (cache on `cache`, locks on `default`) a request that reads and locks connects twice, and pays two connect timeouts when the node is down: budget for both. In a live app don't move locks to another connection, database or prefix as a side effect: old and new hosts must agree on the lock key during a deploy.
- Timeouts: phpredis gets `0.0` when `timeout` or `read_timeout` is unset, and reads it as `default_socket_timeout`, 60 s by default, not infinite. Predis with `read_write_timeout` unset also uses `default_socket_timeout`, but an explicit `0` or less there means wait forever. Either way a hung node holds every request for a minute or more.
- The read timeout must exceed a queue connection's `block_for` (BLPOP waits that long). Give the worker's connection (`REDIS_QUEUE_CONNECTION`) its own entry with `read_timeout` above `block_for`. A connection that runs `Redis::subscribe()` or `psubscribe()` needs `read_timeout => -1` (predis `read_write_timeout => 0`): an idle subscriber otherwise dies with `read error on connection` after the timeout.
- On 13.26+ a command to a node that stopped answering costs `read_timeout`, then a rebuild (up to `timeout`), then the retried read: about three times one timeout (measured: 2 s timeouts gave 6.0 s, 1 s gave 3.0 s, Laravel 13.44.1, phpredis 6.3). Size the timeouts to the request's budget with that in mind.

## ACL users

```
on >secret ~app:* -@all +@read +@write +@scripting +@transaction +select +echo +ping -@dangerous
```
Add `+client|setname` when a connection sets `name` (without it every command on that connection fails with NOPERM), and `+@pubsub &app:*` for broadcasting or `Redis::subscribe()` (channels default to none, and `publish` is NOPERM without both). Tested on Valkey 8.
- Laravel needs EVAL (`Cache::add`, lock release, rate limiter, every queue push and pop: `+@scripting`), MULTI (`putMany`, queue bulk: `+@transaction`) and SELECT (connect). `+echo` is for phpredis' persistent-pool liveness check (ECHO is `@connection`), `+ping` for `Redis::ping()` and health checks. Scripts are checked per inner command and key, so every key they touch must match `~app:*`.
- `-@dangerous` makes these fail by design: `cache:clear` and `Cache::flush()` (FLUSHDB), `KEYS` (13.x `allQueueNames()`, `Redis::keys()`) and `INFO`. A key pattern does not limit FLUSHDB or FLUSHALL: `~app:*` with `+@write` alone still wipes the whole database. `-@dangerous` (or `-flushdb -flushall`) is what blocks them, so never drop it.

## Persistent connections

- They pay off under PHP-FPM and mod_php, where each request opens a socket and a TLS handshake. One production move cut new connections from about 1,000 to 70 per 5 minutes. AWS measured `connect()` at 2.82 ms and pooled `pconnect()` at 0.21 ms. They do nothing in `queue:work`, Horizon or Octane: those keep the connection for the process lifetime.
- phpredis pools persistent streams by default (`redis.pconnect.pooling_enabled=1`). With the default `redis.pconnect.pool_pattern=""` the pool key is `host:port`, so **`persistent_id` is ignored** and `default` and `cache` share sockets in a process. The pattern takes `i` (id), `u` (user), `p` and `a` (password hashes): `pool_pattern=i` isolates by `persistent_id`. `redis.pconnect.connection_limit` (default 0, no cap) bounds the pool. That is safe only because Laravel SELECTs on every connect: never set `database` to `null`.
- phpredis retries a dead socket itself (`max_retries`: phpredis default 10, skeleton 3 from 12.4) and re-SELECTs only a non-zero db. A db 0 connection can then pop a pooled stream left on db 1, and a lock or queue job lands in db 1 (reproduced on 6.3.0 and 6.4.0RC1; phpredis issue #1920, open). **With `persistent` set `max_retries => 0`:** the command throws "Connection lost" and Laravel's own rebuild, which SELECTs, takes over. The cost: after a socket drops mid-request, the next non-idempotent command on it throws instead of being retried (a lock `SET NX`, a release `EVAL`, which then holds the lock until its seconds run out, `INCR`, `SETEX` with options); 13.26+ retries only idempotent ones. The alternative that keeps phpredis's retry: `redis.pconnect.pool_pattern=i` (php.ini, or `ini_set()`, it is `PHP_INI_ALL`) and a distinct `persistent_id` per connection, so each pool only holds one database (reproduced on 6.3.0). It depends on an INI value outside the app, so prefer `max_retries => 0` unless those throws matter.
- Laravel sends AUTH only when the password is not empty. A connection configured without one reuses a pooled stream that another user authenticated and runs with that user's rights (reproduced on 6.3.0 and 6.4.0RC1). Give every connection a password, or add `u` or `a` to `pool_pattern`.
- predis: `persistent => true` on each connection and a distinct `conn_uid`, or `default` and `cache` share one socket and the last SELECT wins. predis re-sends AUTH and SELECT on reuse.
- Count sockets before sizing: FPM children (or Apache workers) x Redis connections used per request x web hosts, plus workers, Horizon and the scheduler. Alarm on the maximum fleet, not today's. ElastiCache allows 65,000 clients (20,000 on `t3.micro` and `t4g.micro`); Laravel Cloud's Valkey 10,000.

## Failover and READONLY

- **11.x, 12.0 to 12.37:** a lost connection (`went away`, `socket`, `read error on connection`, `Connection lost`) builds a new client, without closing the old one and without retrying, and rethrows. READONLY is not in the list, so it never reconnects. 12.28.1 adds `Error while reading`.
- **12.38 to 13.25:** also rebuilds on READONLY, still without closing, still no retry.
- **13.26 to 13.35:** idempotent commands (GET, MGET, DEL, HGET, SET without options, ...) are retried once after a rebuild that still doesn't close the old client; `command_retries` widens the retry. It isn't in the skeleton: add it per connection, default 0.
- **13.36+** closes the old client first.
- A READONLY reply throws but leaves the phpredis stream open. Where the old client isn't closed and `pconnect` is on, its stream goes back to the pool and the next request in that worker gets it again, still talking to the demoted primary (reproduced: a Valkey node demoted with `REPLICAOF`). ElastiCache's `close-on-replica-write` (default yes) closes the connection on the first write to a replica, so there it costs one failed write. Elsewhere, close before reconnecting. For 12.x and 13.0 to 13.25 (run on 12.74, and on 12.37's `command()`; not run on 11.x):
```php
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RedisException;
use Throwable;

class ClosingPhpRedisConnection extends PhpRedisConnection
{
    public static function from(PhpRedisConnection $connection): static
    {
        return new static($connection->client, $connection->connector, $connection->config);
    }

    public function command($method, array $parameters = [])
    {
        $old = $this->client;

        try {
            return parent::command($method, $parameters);
        } catch (RedisException $e) {
            if (Str::contains($e->getMessage(), ['went away', 'socket', 'Error while reading', 'read error on connection', 'READONLY', 'Connection lost'])) {
                if ($this->client === $old && $this->connector) {   // 11.x, 12.0-12.37: the parent skips READONLY
                    $this->client = call_user_func($this->connector);
                }

                if ($this->client !== $old) {   // rebuilt, by the parent or just now
                    try { $old->close(); } catch (Throwable) {}
                }
            }

            throw $e;
        }
    }
}

class ClosingPhpRedisConnector extends PhpRedisConnector
{
    public function connect(array $config, array $options)
    {
        return ClosingPhpRedisConnection::from(parent::connect($config, $options));
    }
}
// AppServiceProvider::register()
Redis::extend('phpredis', fn () => new ClosingPhpRedisConnector);
```
It covers `command()` only. On 13.35 override `protected function rebuildClient()` instead (call `parent::rebuildClient()`, then close the old client; run on 13.35's file). On 13.26 to 13.34 the rebuild is inline: upgrade to 13.36+.
- Don't raise `command_retries` for non-idempotent writes (INCR, RPUSH, EVAL, ZADD): a retry after the first reached the server can run twice.
- Use the primary endpoint's DNS name, not a node endpoint. predis: a READONLY reply is a `ServerException` with no retry on a single node; native cluster handles it from predis 3.5.0.

## Outages

`Cache::get/put`, locks, `RateLimiter`, `throttle` and session start don't catch Redis errors (a down session store fails every `web` request). The queue worker catches a failed pop, reports it and retries after 1 s. `remember()` throws from `get()` before the callback and from `put()` after it. Decide per call site.
```php
// fail open: one class for every read that only saves time; callers keep the remember() shape
final class SpeedUpCache
{
    public function remember(string $key, int $seconds, Closure $source): mixed
    {
        try {
            $value = Cache::get($key);
        } catch (Throwable $e) {
            report($e);          // throttle it: an outage reports on every request
            return $source();    // no put: it would wait for another timeout
        }

        if ($value === null) {
            $value = $source();
            rescue(fn () => Cache::put($key, $value, $seconds));
        }

        return $value;
    }
}
```
- Add this only when the task asks for behaviour during a Redis outage. Route the speed-up reads through the one class; **don't wrap every `Cache` call** or hand-roll try/catch in each caller. Throttle the report (`$exceptions->throttle()`).
- Keep timeouts a task or ops states. Fail-open bounds a request by the timeouts, so the budget sets them, not the other way round.
- **Fail closed** for locks, rate limits, `Cache::add` once-only guards and anything guarding money. Let them throw.
- The `failover` store (12.35+, 13.x; not 11.x) tries each store in order on every call, catches any `Throwable` and keeps no state between calls: a dead primary costs a connect timeout per call, so keep timeouts short. `CacheFailedOver` fires once per `FailoverStore` instance while a store keeps failing (12.47+): once per request under FPM, once per process in a worker. It is a read fallback. Writes made during the outage are invisible once Redis returns.
- Locks: `FailoverStore::lock()` falls back only when the connect fails inside `lock()`. With an already-resolved connection `->get()` throws, no fallback. When it does fall back, that lock is not seen by servers that still reach Redis. It is no lock or limiter guarantee.

## The `database` store

- Expired rows are deleted only when the same key is read again (`DatabaseStore::many`, 11.x to 13.x). No command prunes them. Only `cache_locks` has a prune lottery (`lock_lottery`, default `[2, 100]`). One app's `cache` table reached 750k rows and 1 GB. Schedule a delete, or move hot caches to Redis:
```php
Schedule::call(fn () => DB::connection(config('cache.stores.database.connection'))
    ->table(config('cache.stores.database.table', 'cache'))->where('expiration', '<=', now()->timestamp)->delete())
    ->name('cache:prune-expired')->hourly()->onOneServer()->withoutOverlapping(30);
```
On a table that already holds hundreds of thousands of rows, delete the first batches by hand.
- A task killed mid-run keeps its `withoutOverlapping()` mutex for `expiresAt` minutes, 1440 (24 h) by default, in whatever store holds it: give every call an expiry (`references/scheduling.md`).

## Switching the cache store (rolling deploy)

While old and new hosts run together they use different stores. `Cache::lock`, `Cache::add` guards, `withoutOverlapping()` and `onOneServer()` mutexes, `ShouldBeUnique` locks and rate limiters then split: two hosts both think they hold the lock.
- Pause queue workers and `schedule:run` (or drain to one version) while web rolls, then start both on the new code. Web requests that take `Cache::lock` or `Cache::add` guards split too: drain old web hosts before new ones take traffic for those routes, or keep the lock store (`lock_connection`, the limiter store) on the old store until every host runs the new release.
- Expect a cold cache and reset counters. Changing `SESSION_DRIVER` logs everyone out.
- Smoke-test the new node from the new release: old code without the `scheme` key can't reach a TLS-only node. Rolling back to the `database` store serves whatever it held before the switch, so clear it (or change `cache.prefix`) before switching back.
- Every `ShouldBeUnique` job has a unique-for time (`#[UniqueFor]` on 13.x; `$uniqueFor` or `uniqueFor()` on 11.x and 12.x): a Redis lock created with 0 seconds never expires, a database lock lasts 24 h.
- **Never `cache:clear` or `optimize:clear` (it clears the default store) when the cache connection's database is shared with queues or sessions.** Redis `flush()` is FLUSHDB of that database and ignores the prefix. The skeleton keeps the cache in db 1, but `REDIS_URL` with a `/db` path, `REDIS_CACHE_DB=0` and cluster mode (db 0 only, see Connection config) all collapse it. Under an ACL on a node shared with other apps it fails by design. 13.24+ adds `cache:clear --locks`.

## Eviction

TTL keys: cache values, sessions, rate-limit counters, locks with seconds. No TTL: queue lists and sorted sets, tag sets, `forever()` values, locks created with 0 seconds.
- A store that holds locks, queues or sessions runs `maxmemory-policy noeviction` with a memory alarm (`DatabaseMemoryUsagePercentage`). A full node then rejects writes, cache `put()` included.
- ElastiCache defaults to `volatile-lru` (changeable only in a custom parameter group, not on Serverless). It can evict a held lock, because locks carry a TTL. `allkeys-lru` (Laravel Cloud's suggestion) can drop queued, delayed and reserved jobs.
- A pure cache that should evict gets its own node. Valkey's docs advise two instances over mixing.

## Testing

CI has no Redis: the app's tests use the array store. Test connection config in-process: set the env (`putenv`, `$_ENV`, `$_SERVER`), call `$this->refreshApplication()`, and assert `config('database.redis.cache')` (scheme, timeouts, prefix, `max_retries` with and without `REDIS_PERSISTENT`). Restore the env in `tearDown`. No `artisan tinker` subprocesses. Behaviour against a real node (TLS, ACL, timeouts, a dropped socket) needs a real Valkey or Redis, for example in Docker; say what you could not test.

## Cluster mode

Multi-key commands and scripts need one slot (`CROSSSLOT` otherwise). Queue names need a hash tag: 13.5+ adds `queues:{name}` itself for real cluster clients (`clusters` config); on 11.x and 12.x set `REDIS_QUEUE={default}`. Moving a real cluster from before 13.5 to 13.5+ renames the queue keys (check this, and drain the queues first). Horizon takes only the first node of `clusters.<name>`. Before 13.26, `cache:prune-stale-tags` scans only the first master.

Evidence: laravel/framework `v11.57.2`, `v12.73.0`/`v12.74.0`, `v13.44.1`: `Redis/Connectors/{PhpRedisConnector,PredisConnector}.php`, `Redis/RedisManager.php` (`extend`, `parseConnectionConfiguration`), `Support/ConfigurationUrlParser.php`, `Redis/Connections/{PhpRedisConnection,PredisConnection,Connection}.php` (`command()`, `rebuildClient()`, `RETRYABLE_COMMANDS`), `Cache/{RedisStore,RedisLock,FailoverStore,DatabaseStore,DatabaseLock,CacheManager}.php`, `Console/Scheduling/CacheEventMutex.php`, `Session/SessionManager.php`, `Queue/RedisQueue.php`; laravel/laravel `config/database.php` and `config/cache.php` on 11.x, 12.x, 13.x; laravel/horizon `src/Horizon.php`. phpredis 6.3.0 and 6.4.0RC1: `library.c` (pool key 899-949, `redis_check_eof` 351-423, pool mechanics 3362-3500, liveness 3241-3322), `redis.c` INI defaults, README, issue #1920, and runs against Valkey 8. predis 2.4.1 and 3.8.0: `Connection/Resource/StreamFactory.php`, `Connection/Factory.php`, `Configuration/Options.php`. Runs on the eval Valkey 8 stack (TLS, ACL users) with phpredis 6.3 and Laravel 12.74, 13.35 and 13.44.1: ACL strings, `REPLICAOF` demote and promote, subscribe timeouts, timeout cost. redis and valkey `src/commands/*.json` (categories, ECHO), `src/db.c` (SELECT in cluster mode), `src/script.c`. Docs: redis.io ACL page, laravel.com `redis`, `cache`, `queues`, `scheduling` and Cloud caches pages, AWS ElastiCache `Endpoints`, `AutoFailover`, `ParameterGroups.Engine`, `in-transit-encryption`, `SupportedCommands` and `BestPractices.Clients.Redis.Connections`, valkey.io `lru-cache` and `cluster-spec`.
