<?php

namespace Tests\Hidden;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

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

class ValkeyCacheHiddenTest extends TestCase
{
    use ValkeyEnv;

    /** The production env, exactly as the brief lists it. */
    private function prod(array $extra = []): array
    {
        return $extra + self::SHOP_ENV;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushValkey();
    }

    /** Every key on the server in every database, as admin: [db => keys]. */
    private function allKeys(): array
    {
        $all = [];
        for ($db = 0; $db < 16; $db++) {
            $keys = $this->keysIn($db);
            if ($keys !== []) {
                $all[$db] = $keys;
            }
        }

        return $all;
    }

    private function timing(string $label, float $seconds): void
    {
        fwrite(STDERR, sprintf("\n[timing] %s %.2fs\n", $label, $seconds));
    }

    private function flat(array $byDb): array
    {
        return array_merge([], ...array_values($byDb));
    }

    /** Runs DEBUG SLEEP in another process and returns [process, pipes] once the server is blocked. */
    private function blockServer(int $seconds): array
    {
        $code = '$r = new Redis; $r->connect("tls://valkey.test", 6379, 5.0); $r->auth(["admin", "adminpass"]); '
            .'echo "ready\n"; $r->rawCommand("DEBUG", "SLEEP", "'.$seconds.'");';
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $this->assertSame("ready\n", fgets($pipes[1]));
        usleep(300_000);

        return [$proc, $pipes];
    }

    public function test_the_cache_round_trips_and_every_key_is_under_the_shop_prefix(): void
    {
        $this->bootWithEnv($this->prod());

        $this->assertTrue(Cache::put('greeting', 'hello', 600));
        $this->assertSame('hello', Cache::get('greeting'));

        $this->assertTrue(Cache::add('once', 'first', 600));
        $this->assertFalse(Cache::add('once', 'second', 600));
        $this->assertSame('first', Cache::get('once'));

        Cache::put('counter', 5, 600);
        $this->assertSame(8, Cache::increment('counter', 3));
        $this->assertEquals(8, Cache::get('counter'));

        Cache::put('a', ['x' => 1], 600);
        Cache::put('b', 'bee', 600);
        $many = Cache::many(['a', 'b', 'missing']);
        $this->assertSame(['x' => 1], $many['a']);
        $this->assertSame('bee', $many['b']);
        $this->assertNull($many['missing']);

        $this->assertTrue(Cache::forget('b'));
        $this->assertNull(Cache::get('b'));

        $keys = $this->flat($this->allKeys());
        $this->assertNotEmpty($keys, 'the cache data must be on the Valkey node');
        $this->assertGreaterThanOrEqual(4, count($keys));
        foreach ($keys as $key) {
            $this->assertStringStartsWith('shop:', $key, "key `$key` is outside the ACL user's prefix");
        }
    }

    public function test_a_lock_is_taken_held_and_released_under_the_acl(): void
    {
        $this->bootWithEnv($this->prod());

        $first = Cache::lock('x', 10);
        $this->assertTrue($first->get());

        $keys = $this->flat($this->allKeys());
        $this->assertNotEmpty($keys, 'the lock must live on the Valkey node');
        foreach ($keys as $key) {
            $this->assertStringStartsWith('shop:', $key);
        }
        $this->assertCount(1, $keys, 'taking one lock should add exactly one key: '.json_encode($keys));
        $this->assertTrue(str_ends_with($keys[0], 'x'), "the lock key should be the prefixes followed by `x`, got `{$keys[0]}`");

        $second = Cache::lock('x', 10);
        $this->assertFalse($second->get(), 'a second owner must not get a held lock');

        $this->assertTrue($first->release());
        $this->assertTrue($second->get(), 'the lock is free after release');
        $this->assertTrue($second->release());
    }

    /**
     * Runs one cache call and returns [seconds, result, thrown]. A call against a node that does not answer must fail:
     * it throws, or it returns a failure value. A build that quietly falls back to another store succeeds, and is caught here.
     *
     * @return array{float, mixed, ?\Throwable}
     */
    private function timedCall(callable $fn): array
    {
        $result = null;
        $seconds = $this->elapsed(function () use ($fn, &$result) {
            $result = $fn();
        });

        return [$seconds, $result, $this->lastError()];
    }

    private function assertReadFailed(?\Throwable $thrown, mixed $result, string $what): void
    {
        $this->assertTrue($thrown !== null || $result === null, "$what returned ".json_encode($result).' instead of failing');
    }

    private function assertWriteFailed(?\Throwable $thrown, mixed $result, string $what): void
    {
        $this->assertTrue($thrown !== null || $result === false, "$what returned ".json_encode($result).' instead of throwing or returning false');
    }

    private function assertLockFailed(?\Throwable $thrown, mixed $result, string $what): void
    {
        $this->assertTrue($thrown !== null || $result === false, "$what returned ".json_encode($result).': the lock must not be granted, it must throw or return false');
    }

    public function test_an_unreachable_host_fails_within_the_time_limit_and_does_not_hang(): void
    {
        $this->bootWithEnv($this->prod(['REDIS_HOST' => 'tarpit.test']));

        [$elapsed, $result, $thrown] = $this->timedCall(fn () => Cache::get('anything'));
        $this->timing(__FUNCTION__.' elapsed', $elapsed);
        $this->assertLessThan(8.0, $elapsed, sprintf('a cache read took %.1fs against a host that never answers', $elapsed));
        $this->assertReadFailed($thrown, $result, 'a cache read against a host that never answers');

        [$elapsed, $result, $thrown] = $this->timedCall(fn () => Cache::put('anything', 1, 60));
        $this->timing(__FUNCTION__.' elapsed', $elapsed);
        $this->assertLessThan(8.0, $elapsed, sprintf('a cache write took %.1fs against a host that never answers', $elapsed));
        $this->assertWriteFailed($thrown, $result, 'a cache write against a host that never answers');

        [$elapsed, $result, $thrown] = $this->timedCall(fn () => Cache::lock('x', 10)->get());
        $this->timing(__FUNCTION__.' elapsed', $elapsed);
        $this->assertLessThan(8.0, $elapsed, sprintf('taking a lock took %.1fs against a host that never answers', $elapsed));
        $this->assertLockFailed($thrown, $result, 'taking a lock against a host that never answers');
    }

    public function test_a_node_that_stops_answering_is_given_up_on_within_the_time_limit(): void
    {
        $this->bootWithEnv($this->prod());

        Cache::put('warm', 'up', 60);
        $this->assertSame('up', Cache::get('warm'));
        $lock = Cache::lock('warm-lock', 10);
        $this->assertTrue($lock->get());
        $lock->release();

        // Each call gets its own blocked node: a slow call must not use up the block and leave the next one a free server.
        $blocked = function (callable $fn, string $label) {
            [$proc, $pipes] = $this->blockServer(10);
            $outcome = $this->timedCall($fn);
            $this->timing(__FUNCTION__." $label", $outcome[0]);
            proc_close($proc); // waits for the sleep to end, so the server is free for the next call

            return $outcome;
        };
        [$read, $readResult, $readError] = $blocked(fn () => Cache::get('warm'), 'read');
        [$write, $writeResult, $writeError] = $blocked(fn () => Cache::put('warm', 'again', 60), 'write');
        [$locked, $lockResult, $lockError] = $blocked(fn () => Cache::lock('other-lock', 10)->get(), 'locked');

        $this->assertLessThan(8.0, $read, sprintf('a cache read waited %.1fs on a blocked node', $read));
        $this->assertNotNull($readError, 'the read cannot succeed while the node is blocked');
        $this->assertReadFailed($readError, $readResult, 'a cache read on a blocked node');
        $this->assertLessThan(8.0, $write, sprintf('a cache write waited %.1fs on a blocked node', $write));
        $this->assertNotNull($writeError);
        $this->assertWriteFailed($writeError, $writeResult, 'a cache write on a blocked node');
        $this->assertLessThan(8.0, $locked, sprintf('taking a lock waited %.1fs on a blocked node', $locked));
        $this->assertLockFailed($lockError, $lockResult, 'taking a lock on a blocked node');
    }

    public function test_tls_host_name_verification_stays_on(): void
    {
        // Control: the real name works, so a failure below is about the name and not about the setup.
        $this->bootWithEnv($this->prod());
        Cache::put('control', 'ok', 60);
        $this->assertSame('ok', Cache::get('control'));
        $this->flushValkey();

        // The same server under a name its certificate does not cover.
        $this->bootWithEnv($this->prod(['REDIS_HOST' => 'valkey-alias.test']));

        $stored = null;
        $this->elapsed(function () use (&$stored) {
            Cache::put('mismatch', 'leaked', 60);
            $stored = Cache::get('mismatch');
        });

        $this->assertNotSame('leaked', $stored, 'the cache worked over a connection whose certificate does not match the host name');
        $this->assertSame([], $this->allKeys(), 'nothing may be written over an unverified connection');
    }

    public function test_the_app_still_runs_with_no_redis_or_cache_env_at_all(): void
    {
        $this->bootWithEnv([
            'CACHE_STORE' => null,
            'REDIS_HOST' => null,
            'REDIS_PORT' => null,
            'REDIS_USERNAME' => null,
            'REDIS_PASSWORD' => null,
            'REDIS_SCHEME' => null,
            'REDIS_URL' => null,
            'REDIS_PREFIX' => null,
            'REDIS_CLIENT' => null,
            'CACHE_PREFIX' => null,
        ]);

        $this->artisan('about')->assertExitCode(0);
        $this->get('/')->assertSuccessful();
    }
}
