<?php

namespace Tests\Hidden;

use Illuminate\Support\Facades\Cache;
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

class SmokeHiddenTest extends TestCase
{
    use ValkeyEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushValkey();
    }

    /** The config change a build is expected to make: a scheme on both connections, and a prefix from env. */
    private function withSchemeConfig(): void
    {
        config([
            'database.redis.default.scheme' => env('REDIS_SCHEME', 'tcp'),
            'database.redis.cache.scheme' => env('REDIS_SCHEME', 'tcp'),
        ]);
    }

    public function test_shop_user_caches_over_tls_with_a_scheme_config(): void
    {
        $this->bootWithEnv(self::SHOP_ENV + ['REDIS_PREFIX' => 'shop:']);
        $this->withSchemeConfig();

        Cache::store('redis')->put('smoke', 'hello', 60);

        $this->assertSame('hello', Cache::store('redis')->get('smoke'));
        $this->assertSame([], $this->keysIn(0));
        $keys = $this->keysIn(1);
        $this->assertCount(1, $keys);
        $this->assertStringStartsWith('shop:', $keys[0]);
    }

    public function test_stock_config_fails_against_the_tls_only_server(): void
    {
        $this->bootWithEnv(self::SHOP_ENV + ['REDIS_PREFIX' => 'shop:']);

        $error = null;
        try {
            Cache::store('redis')->put('smoke', 'hello', 60);
        } catch (Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'stock config has no scheme, so it should not reach a TLS-only port');
        fwrite(STDERR, "\n[smoke] stock config error: ".get_class($error).': '.substr($error->getMessage(), 0, 100)."\n");
        $this->assertSame([], $this->keysIn(1));
    }

    public function test_env_is_restored_between_boots(): void
    {
        $this->bootWithEnv(['CACHE_STORE' => 'redis']);
        $this->assertSame('redis', config('cache.default'));

        $this->bootWithEnv(['REDIS_HOST' => 'elsewhere.test']);
        $this->assertSame('array', config('cache.default'), 'phpunit.xml value must come back');
        $this->assertSame('elsewhere.test', config('database.redis.default.host'));

        $this->bootWithEnv([]);
        $this->assertSame('127.0.0.1', config('database.redis.default.host'));
    }

    public function test_connecting_to_the_tarpit_gives_up_at_the_connect_timeout(): void
    {
        $elapsed = $this->elapsed(function () {
            (new \Redis)->connect('tls://tarpit.test', 6379, 2.0);
        });

        $this->assertNotNull($this->lastError());
        $this->assertGreaterThan(1.5, $elapsed);
        $this->assertLessThan(3.5, $elapsed);
        fwrite(STDERR, sprintf("\n[smoke] tarpit gave up after %.2fs: %s\n", $elapsed, $this->lastError()->getMessage()));
    }

    public function test_a_closed_port_is_refused_at_once(): void
    {
        $elapsed = $this->elapsed(fn () => (new \Redis)->connect('tls://closed.test', 6379, 5.0));

        $this->assertNotNull($this->lastError());
        $this->assertLessThan(2.0, $elapsed);
    }

    public function test_a_blocked_server_trips_the_read_timeout(): void
    {
        $reader = new \Redis;
        $reader->connect('tls://valkey.test', 6379, 5.0, null, 0, 1.0);
        $reader->auth(['admin', 'adminpass']);
        $this->assertTrue($reader->ping());

        $code = '$r = new Redis; $r->connect("tls://valkey.test", 6379, 5.0); $r->auth(["admin", "adminpass"]); '
            .'echo "ready\n"; $r->rawCommand("DEBUG", "SLEEP", "5");';
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $this->assertSame("ready\n", fgets($pipes[1]));
        usleep(300_000);

        $elapsed = $this->elapsed(fn () => $reader->get('anything'));

        $this->assertInstanceOf(\RedisException::class, $this->lastError());
        $this->assertGreaterThan(0.8, $elapsed);
        $this->assertLessThan(2.0, $elapsed);
        fwrite(STDERR, sprintf("\n[smoke] read timeout after %.2fs: %s\n", $elapsed, $this->lastError()->getMessage()));

        proc_close($proc); // waits for the 5 s sleep to end, so the server is free for the next test
    }
}
