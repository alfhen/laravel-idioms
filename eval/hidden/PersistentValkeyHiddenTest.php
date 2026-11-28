<?php

namespace Tests\Hidden;

use App\Models\Merchant;
use App\Models\Payout;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;
use Throwable;


/*
 * Paste this trait into a hidden test file, between the `use` imports and the test class, then
 * `use ValkeyEnv;` inside the class. It needs the ext-redis extension and the Valkey stack in eval/valkey/.
 * Everything is fully qualified, so it adds no imports. Keep this file the single source: re-paste after edits.
 *
 * Env caveats (checked against Illuminate\Support\Env in Laravel 13):
 *  - Env::getRepository() is a static, but it caches only the adapter list, not values. Every env() call
 *    reads $_ENV, then $_SERVER, then getenv() (first hit wins). So a value must be set in all three and
 *    cleared from all three. A var left in only one of them still shadows the default.
 *  - phpunit.xml sets CACHE_STORE=array, DB_DATABASE=:memory: and others in all three. Clearing a var must
 *    restore that value, not unset it. bootWithEnv() does this.
 *  - config() is read once per application. Env changes only reach config after $this->refreshApplication(),
 *    which bootWithEnv() calls. Do not call config() before it for values you want from env.
 *  - refreshApplication() builds a new app, so an in-memory SQLite database from RefreshDatabase is gone.
 *    Call bootWithEnv() before touching the database, or run migrations again afterwards.
 *  - There is no .env file: run-hidden.sh deletes it. The app runs from config defaults plus these vars.
 *  - tearDownValkeyEnv() runs through Laravel's trait teardown hook and restores the original env.
 */
trait ValkeyEnv
{
    /** Production-like env for the restricted `shop` user. Merge your own vars over it. */
    public const SHOP_ENV = [
        'CACHE_STORE' => 'redis',
        'REDIS_HOST' => 'valkey.test',
        'REDIS_PORT' => '6379',
        'REDIS_USERNAME' => 'shop',
        'REDIS_PASSWORD' => 'shoppass',
        'REDIS_SCHEME' => 'tls',
    ];

    /** @var array<string, array{env: mixed, server: mixed, put: string|false, had_env: bool, had_server: bool}> */
    private array $valkeyEnvBackup = [];

    /** @var list<\Redis> */
    private array $valkeyAdmins = [];

    /** Sets env vars (null unsets), undoes the previous call's changes, then rebuilds the application. */
    protected function bootWithEnv(array $env): void
    {
        $this->restoreValkeyEnv();

        foreach ($env as $name => $value) {
            $name = (string) $name;
            $this->valkeyEnvBackup[$name] = [
                'env' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
                'put' => getenv($name),
                'had_env' => array_key_exists($name, $_ENV),
                'had_server' => array_key_exists($name, $_SERVER),
            ];

            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $_SERVER[$name] = (string) $value;
                putenv($name.'='.$value);
            }
        }

        $this->refreshApplication();
    }

    private function restoreValkeyEnv(): void
    {
        foreach ($this->valkeyEnvBackup as $name => $old) {
            if ($old['had_env']) {
                $_ENV[$name] = $old['env'];
            } else {
                unset($_ENV[$name]);
            }
            if ($old['had_server']) {
                $_SERVER[$name] = $old['server'];
            } else {
                unset($_SERVER[$name]);
            }
            $old['put'] === false ? putenv($name) : putenv($name.'='.$old['put']);
        }

        $this->valkeyEnvBackup = [];
    }

    protected function tearDownValkeyEnv(): void
    {
        $this->restoreValkeyEnv();

        foreach ($this->valkeyAdmins as $admin) {
            try {
                $admin->close();
            } catch (\Throwable) {
            }
        }
        $this->valkeyAdmins = [];
    }

    /** A phpredis client on valkey.test:6379 over TLS as admin, with $db selected. The CA comes from openssl.cafile. */
    protected function admin(int $db = 0): \Redis
    {
        $redis = new \Redis;
        $redis->connect('tls://valkey.test', 6379, 5.0);
        $redis->auth(['admin', 'adminpass']);
        $redis->select($db);

        return $this->valkeyAdmins[] = $redis;
    }

    /** Empties every database. The harness flushes once per run, so call this in setUp() for a clean slate per test. */
    protected function flushValkey(): void
    {
        $this->admin()->flushAll();
    }

    /** Every key in a database, sorted, found with SCAN as admin. Keys are raw: no client prefix is applied. */
    protected function keysIn(int $db): array
    {
        $redis = $this->admin($db);
        $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        $keys = [];
        $cursor = null;
        while (($batch = $redis->scan($cursor, '*', 100)) !== false) {
            array_push($keys, ...$batch);
            if ($cursor === 0) {
                break;
            }
        }
        sort($keys);

        return $keys;
    }

    /** Drops every connection of an ACL user, as a failover or an idle reaper would. Returns how many were killed. */
    protected function killClientsOf(string $user): int
    {
        return (int) $this->admin()->rawCommand('CLIENT', 'KILL', 'USER', $user);
    }

    private ?\Throwable $valkeyLastError = null;

    /** Seconds the callable takes. A Throwable is caught, not rethrown: read it with lastError(). */
    protected function elapsed(callable $fn): float
    {
        $this->valkeyLastError = null;
        $start = hrtime(true);
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->valkeyLastError = $e;
        }

        return (hrtime(true) - $start) / 1e9;
    }

    /** The Throwable the last elapsed() call caught, or null. */
    protected function lastError(): ?\Throwable
    {
        return $this->valkeyLastError;
    }
}


/*
 * Production runs Apache with mod_php: every request is a fresh PHP request in a long-lived child. A request is
 * simulated here by building a new application (bootWithEnv) and ending it (endRequest) inside this one PHP
 * process, which keeps phpredis's persistent sockets alive between "requests" like a child does. Every test runs in
 * its own PHP process, so the socket pool of one test never leaks into another and the order does not matter.
 */
#[RunTestsInSeparateProcesses]
class PersistentValkeyHiddenTest extends TestCase
{
    use ValkeyEnv;

    private string $sqlite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sqlite = tempnam(sys_get_temp_dir(), 'shop-').'.sqlite';
        touch($this->sqlite);
        $admin = $this->admin();
        $admin->rawCommand('REPLICAOF', 'NO', 'ONE');
        $admin->flushAll();
        $this->killClientsOf('shop');
    }

    protected function tearDown(): void
    {
        try {
            $this->admin()->rawCommand('REPLICAOF', 'NO', 'ONE');
        } catch (Throwable) {
        }
        $this->tearDownValkeyEnv();
        @unlink($this->sqlite);
        parent::tearDown();
    }

    private function prod(array $extra = []): array
    {
        return array_merge(self::SHOP_ENV, ['DB_DATABASE' => $this->sqlite, 'REDIS_PERSISTENT' => 'true'], $extra);
    }

    /** One request: a new application, the callback, then the end of the request (objects destroyed, sockets released). */
    private function request(callable $fn, ?array $env = null): mixed
    {
        $this->bootWithEnv($env ?? $this->prod());
        try {
            return $fn();
        } finally {
            $this->endRequest();
        }
    }

    private function endRequest(): void
    {
        if ($this->app !== null) {
            $this->app->flush();
            $this->app = null;
        }
        Facade::clearResolvedInstances();
        gc_collect_cycles();
    }

    private function migrate(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    /** @return list<array<string, string>> */
    private function shopClients(?\Redis $admin = null): array
    {
        $rows = [];
        foreach (explode("\n", trim((string) ($admin ?? $this->admin())->rawCommand('CLIENT', 'LIST'))) as $line) {
            $fields = [];
            foreach (explode(' ', trim($line)) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $fields[$k] = $v;
            }
            if (($fields['user'] ?? '') === 'shop') {
                $rows[] = $fields;
            }
        }

        return $rows;
    }

    private function killShopSocketsOnDb(int $db): int
    {
        $killed = 0;
        foreach ($this->shopClients() as $client) {
            if ((int) $client['db'] === $db) {
                $killed += (int) $this->admin()->rawCommand('CLIENT', 'KILL', 'ID', $client['id']);
            }
        }

        return $killed;
    }

    /** The database numbers the app's own config gives to its lock connection and its cache connection. */
    private function dbs(): array
    {
        $store = config('cache.stores.redis');
        $cache = $store['connection'] ?? 'default';
        $lock = $store['lock_connection'] ?? $cache;

        return [
            'lock' => (int) config("database.redis.$lock.database", 0),
            'cache' => (int) config("database.redis.$cache.database", 0),
            'lockConnection' => $lock,
            'cacheConnection' => $cache,
        ];
    }

    /** @return array<int, list<string>> raw keys per database, as admin */
    private function allKeys(): array
    {
        $all = [];
        for ($db = 0; $db < 16; $db++) {
            $keys = $this->keysIn($db);
            if ($keys) {
                $all[$db] = $keys;
            }
        }

        return $all;
    }

    private function isLockKey(string $key): bool
    {
        return str_contains($key, 'leaktest-lock') || str_contains($key, 'payouts:run');
    }

    private function isCacheKey(string $key): bool
    {
        return str_contains($key, 'leaktest-cache');
    }

    /** Fails when a lock key sits outside the lock database or a cache key outside the cache database. */
    private function assertPlaced(array $dbs, string $context, ?array $keys = null): void
    {
        foreach ($keys ?? $this->allKeys() as $db => $list) {
            foreach ($list as $key) {
                if ($this->isLockKey($key)) {
                    $this->assertSame($dbs['lock'], $db, "$context: lock key $key is in db $db, the lock connection uses db {$dbs['lock']}");
                }
                if ($this->isCacheKey($key)) {
                    $this->assertSame($dbs['cache'], $db, "$context: cache key $key is in db $db, the cache connection uses db {$dbs['cache']}");
                }
            }
        }
    }

    private function hasKey(array $keys, int $db, string $needle): bool
    {
        foreach ($keys[$db] ?? [] as $key) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** The first lock call after a socket drop must acquire the lock or throw: a silent false means nothing was locked. */
    private function acquireOrThrow(string $name, int $seconds): void
    {
        try {
            $got = Cache::lock($name, $seconds)->get();
        } catch (Throwable) {
            return; // failing loudly is allowed
        }
        $this->assertTrue($got, "the lock `$name` was not acquired after the socket dropped, and nothing was thrown");
    }

    /** Uses the cache and takes then releases a lock, the way a normal request does. */
    private function warm(string $name, bool $lockFirst = true): void
    {
        $cache = fn () => Cache::put("leaktest-cache-$name", 'v', 300);
        $lock = function () use ($name) {
            $lock = Cache::lock("leaktest-lock-warm-$name", 60);
            $lock->get();
            $lock->release();
        };
        $lockFirst ? [$lock(), $cache()] : [$cache(), $lock()];
    }

    public function test_persistent_connections_are_reused_across_requests(): void
    {
        // One admin connection, opened before request 1 and reused, so its own connection is never in the delta.
        $admin = $this->admin();
        $received = fn () => (int) ($admin->info('stats')['total_connections_received'] ?? -1);

        $counts = [];
        $afterFirst = null;
        for ($i = 1; $i <= 5; $i++) {
            $this->request(function () use ($i) {
                Cache::put("leaktest-cache-$i", 'v', 300);
                $lock = Cache::lock("leaktest-lock-$i", 60);
                $this->assertTrue($lock->get());
                $lock->release();
                $this->assertSame('v', Cache::get("leaktest-cache-$i"));
            });
            if ($i === 1) {
                $afterFirst = $received();
            }
            // The server notices the end of a request a moment later: poll up to 1.5 s for the count to settle.
            $deadline = microtime(true) + 1.5;
            do {
                $counts[$i] = count($this->shopClients($admin));
                if ($i === 1 ? $counts[1] >= 1 : $counts[$i] === $counts[1]) {
                    break;
                }
                usleep(100_000);
            } while (microtime(true) < $deadline);
        }
        $afterFifth = $received();

        $this->assertGreaterThanOrEqual(1, $counts[1], 'no shop connection stays open after a request: nothing is persistent');
        $this->assertSame(array_fill(1, 5, $counts[1]), $counts, 'the number of shop connections grew across requests: '.json_encode($counts));
        $this->assertGreaterThan(0, $afterFirst, 'could not read total_connections_received from INFO stats');
        $this->assertSame(0, $afterFifth - $afterFirst, sprintf(
            'requests 2 to 5 opened %d new connections to Valkey (cache and lock together): every connection they use must be reused',
            $afterFifth - $afterFirst
        ));
    }

    public function test_the_app_works_without_the_persistent_switch(): void
    {
        $env = $this->prod(['REDIS_PERSISTENT' => null]);
        for ($i = 1; $i <= 2; $i++) {
            $this->request(function () use ($i) {
                Cache::put("leaktest-cache-$i", 'v', 300);
                $lock = Cache::lock("leaktest-lock-$i", 60);
                $this->assertTrue($lock->get());
                $this->assertSame('v', Cache::get("leaktest-cache-$i"));
                $lock->release();
            }, $env);
        }
        $this->assertSame(0, count($this->shopClients()), 'without REDIS_PERSISTENT connections must close with the request');
    }

    /** @return array<string, array{string, bool}> */
    public static function dropped_sockets(): array
    {
        return [
            'all sockets dropped, lock first' => ['all', true],
            'all sockets dropped, cache first' => ['all', false],
            'only the lock socket dropped, lock first' => ['lock', true],
            'only the lock socket dropped, cache first' => ['lock', false],
        ];
    }

    #[DataProvider('dropped_sockets')]
    public function test_a_lock_taken_after_a_drop_between_requests_stays_in_the_lock_database(string $which, bool $lockFirst): void
    {
        $dbs = $this->request(function () use ($lockFirst) {
            $this->warm('one', $lockFirst);

            return $this->dbs();
        });

        $which === 'all' ? $this->killClientsOf('shop') : $this->killShopSocketsOnDb($dbs['lock']);

        $this->request(function () {
            $this->assertTrue(Cache::lock('leaktest-lock-held', 60)->get());
            Cache::put('leaktest-cache-two', 'v', 300);
        });

        $this->assertPlaced($dbs, "$which dropped");
        $this->assertTrue($this->hasKey($this->allKeys(), $dbs['lock'], 'leaktest-lock-held'), 'the held lock is not in the lock database');

        // A later request, on whatever sockets it gets, must see that lock as held.
        $this->request(fn () => $this->assertFalse(Cache::lock('leaktest-lock-held', 60)->get(), 'a held lock is invisible to the next request'));
    }

    #[DataProvider('dropped_sockets')]
    public function test_a_lock_taken_after_a_drop_in_the_middle_of_a_request_stays_in_the_lock_database(string $which, bool $lockFirst): void
    {
        $dbs = $this->request(function () use ($lockFirst) {
            $this->warm('one', $lockFirst);

            return $this->dbs();
        });

        if ($which === 'all') {
            $this->killClientsOf('shop');
        }

        $this->request(function () use ($which, $dbs) {
            // The lock connection is open; then its socket goes away; then the lock is taken.
            app('redis')->connection($dbs['lockConnection'])->ping();
            $which === 'all' ? $this->killClientsOf('shop') : $this->killShopSocketsOnDb($dbs['lock']);
            // Failing loudly is acceptable; a lock in the wrong database is not; a silent false means nothing was locked.
            $this->acquireOrThrow('leaktest-lock-mid', 60);
        });

        $this->assertPlaced($dbs, "$which dropped mid-request");

        // Whatever happened, a request that now takes the same lock and holds it must be visible to the next one.
        $this->request(fn () => $this->assertTrue(Cache::lock('leaktest-lock-after', 60)->get(), 'the lock was not acquired'));
        $this->assertPlaced($dbs, "$which dropped mid-request, lock after");
        $this->request(fn () => $this->assertFalse(Cache::lock('leaktest-lock-after', 60)->get(), 'a held lock is invisible to the next request'));
    }

    public function test_a_lock_connection_that_was_idle_in_the_pool_while_only_the_cache_ran_stays_correct(): void
    {
        // Request 1 uses both connections. Request 2 only the lock one, request 3 only the cache one: the sockets
        // return to the pool in different orders, then the lock socket dies after the connection was made.
        $dbs = $this->request(function () {
            $this->warm('one');

            return $this->dbs();
        });
        $this->request(fn () => $this->warm('two', true));
        $this->request(fn () => Cache::put('leaktest-cache-three', 'v', 300));
        $this->request(function () use ($dbs) {
            app('redis')->connection($dbs['lockConnection'])->ping();
            $this->killShopSocketsOnDb($dbs['lock']);
            $this->acquireOrThrow('leaktest-lock-four', 60);
        });

        $this->assertPlaced($dbs, 'pool with an idle cache socket');
        $this->request(fn () => $this->assertTrue(Cache::lock('leaktest-lock-five', 60)->get(), 'the lock was not acquired'));
        $this->assertPlaced($dbs, 'pool with an idle cache socket, lock after');
        $this->request(fn () => $this->assertFalse(Cache::lock('leaktest-lock-five', 60)->get()));
    }

    public function test_payouts_run_after_the_lock_socket_drops_works_or_fails_loudly_and_locks_in_the_right_database(): void
    {
        $dbs = $this->request(function () {
            $this->migrate();
            $this->warm('one');

            return $this->dbs();
        });
        $this->request(function () {
            Merchant::create(['name' => 'a', 'balance_cents' => 1000]);
            Merchant::create(['name' => 'b', 'balance_cents' => 1000]);
        });

        $snapshots = [];
        $outcome = $this->request(function () use ($dbs, &$snapshots) {
            DB::listen(function ($query) use (&$snapshots) {
                if (str_contains($query->sql, 'into "payouts"')) {
                    $snapshots[] = $this->allKeys();
                }
            });
            app('redis')->connection($dbs['lockConnection'])->ping();
            $this->killShopSocketsOnDb($dbs['lock']);
            try {
                return Artisan::call('payouts:run');
            } catch (Throwable $e) {
                return $e;
            }
        });

        foreach ($snapshots as $keys) {
            $this->assertPlaced($dbs, 'during payouts:run', $keys);
            $this->assertTrue($this->hasKey($keys, $dbs['lock'], 'payouts:run'), 'payouts were written while the lock was not held in the lock database');
        }
        $this->assertPlaced($dbs, 'after payouts:run');

        $this->request(function () use ($outcome) {
            $payouts = Payout::count();
            if ($outcome instanceof Throwable || $outcome !== 0) {
                // Failed loudly: no half-done payout run.
                $this->assertSame(0, $payouts, 'a failed run left some merchants paid');
            } else {
                $this->assertSame(2, $payouts);
                $this->assertSame(0, Merchant::where('balance_cents', '>', 0)->count());
            }
        });
    }

    public function test_a_payouts_lock_taken_after_a_drop_blocks_a_run_in_another_process(): void
    {
        $dbs = $this->request(function () {
            $this->migrate();
            $this->warm('one');

            return $this->dbs();
        });
        $this->request(function () {
            $this->migrate();
            Merchant::create(['name' => 'a', 'balance_cents' => 1000]);
        });

        // This request is "another container" that holds the payouts lock and never releases it during the test.
        $this->request(function () use ($dbs) {
            app('redis')->connection($dbs['lockConnection'])->ping();
            $this->killShopSocketsOnDb($dbs['lock']);
            $this->acquireOrThrow('payouts:run', 600);
        });
        $held = $this->hasKey($this->allKeys(), $dbs['lock'], 'payouts:run');
        $this->assertPlaced($dbs, 'payouts lock after a drop');

        if ($held) {
            $this->request(function () {
                Artisan::call('payouts:run');
                $this->assertSame(0, Payout::count(), 'a second run paid out while the lock was held');
            });
        }
    }

    /**
     * Rolling deploy: containers on the old image and the new one use the same locks. The old (stock) config keeps the
     * lock on connection `default`, database 0, under the client prefix `shop:` and the cache prefix `laravel-cache-`.
     * The expected key comes from the stock fixture, not from the build's own config.
     */
    public function test_the_payouts_lock_stays_where_old_containers_look_for_it(): void
    {
        $stockKey = 'shop:'.\Illuminate\Support\Str::slug('laravel').'-cache-payouts:run';

        $this->request(function () {
            $this->migrate();
            Merchant::create(['name' => 'a', 'balance_cents' => 1000]);
        });

        // An old container holds the lock, taken the stock way. A new container must see it.
        $this->admin(0)->set($stockKey, 'old-container-owner', ['EX' => 600]);
        $this->request(function () {
            Artisan::call('payouts:run');
            $this->assertSame(0, Payout::count(), 'a new container paid out while an old container held the lock (db 0, key as stock)');
            $this->assertSame(1000, (int) Merchant::sum('balance_cents'));
        });
        $this->admin(0)->del($stockKey);

        // A new container holds the lock. An old container looks in db 0 under the stock key.
        $this->request(fn () => $this->assertTrue(Cache::lock('payouts:run', 600)->get()));
        $found = [];
        foreach ($this->allKeys() as $db => $keys) {
            foreach ($keys as $key) {
                if (str_contains($key, 'payouts:run')) {
                    $found[] = "db{$db}:{$key}";
                }
            }
        }
        $this->assertSame(["db0:{$stockKey}"], $found, 'the payouts lock is not where an old container looks for it');
    }

    public function test_the_cache_recovers_after_a_failover(): void
    {
        $this->request(fn () => Cache::put('leaktest-cache-before', 'v', 300));

        // A failover demotes the node the app is connected to; its connections stay open and now refuse writes.
        $admin = $this->admin();
        $admin->rawCommand('REPLICAOF', '127.0.0.1', '1');
        $refused = null;
        try {
            $this->request(function () use (&$refused) {
                try {
                    Cache::put('leaktest-cache-during', 'v', 300);
                } catch (Throwable $e) {
                    $refused = $e->getMessage();
                }
            });
            $this->assertNotNull($refused, 'the demoted node accepted a write: the failover was not simulated');
            $this->assertStringContainsStringIgnoringCase('READONLY', $refused);
        } finally {
            $admin->rawCommand('REPLICAOF', 'NO', 'ONE');
        }

        // Regression guard: Laravel 13.44 rebuilds the client on READONLY, so this passes for any build that keeps that behaviour.
        $this->request(function () {
            Cache::put('leaktest-cache-after', 'v', 300);
            $this->assertSame('v', Cache::get('leaktest-cache-after'));
        });
        $this->request(function () {
            Cache::put('leaktest-cache-after-two', 'v', 300);
            $this->assertSame('v', Cache::get('leaktest-cache-after-two'));
        });
    }
}
