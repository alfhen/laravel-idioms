<?php

namespace Tests\Hidden;

use App\Jobs\SendInvoiceReminder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

class ValkeyQueueHiddenTest extends TestCase
{
    use ValkeyEnv;

    private const UUID = '~[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~';

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushValkey();
    }

    private function assertHorizonInstalled(): void
    {
        $this->assertTrue(class_exists(\Laravel\Horizon\Horizon::class), 'laravel/horizon is not installed');
        $this->assertTrue(class_exists(SendInvoiceReminder::class), 'App\Jobs\SendInvoiceReminder does not exist');
    }

    /** The production env of the brief: the queue on Valkey, the cache still on the database. */
    private function bootProduction(): void
    {
        $this->assertHorizonInstalled();
        $this->bootWithEnv(['CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'redis'] + self::SHOP_ENV);
        Artisan::call('migrate', ['--force' => true]);
    }

    /** @return array<int, list<string>> db number => raw keys, as admin */
    private function serverKeys(): array
    {
        $all = [];
        for ($db = 0; $db < 16; $db++) {
            if ($keys = $this->keysIn($db)) {
                $all[$db] = $keys;
            }
        }

        return $all;
    }

    private function assertEveryKeyIsUnderShop(string $when): void
    {
        $foreign = [];
        foreach ($this->serverKeys() as $db => $keys) {
            foreach ($keys as $key) {
                if (! str_starts_with($key, 'shop:')) {
                    $foreign[] = "db{$db}:{$key}";
                }
            }
        }
        $this->assertSame([], $foreign, "keys outside shop: {$when}");
    }

    /** @return list<array{0: int, 1: string}> */
    private function keysMatching(string $regex): array
    {
        $found = [];
        foreach ($this->serverKeys() as $db => $keys) {
            foreach ($keys as $key) {
                if (preg_match($regex, $key)) {
                    $found[] = [$db, $key];
                }
            }
        }

        return $found;
    }

    private function runWorkerOnce(?string $queue = null): string
    {
        // --sleep 0 so a configured block_for plus the default 3 s sleep cannot delay the pickup.
        Artisan::call('queue:work', array_filter([
            'connection' => 'redis', '--once' => true, '--sleep' => 0, '--queue' => $queue,
        ], fn ($v) => $v !== null));

        return Artisan::output();
    }

    /** The name of the queue the dispatched job landed in: the list key `<prefix>queues:<name>`, not its :delayed/:reserved/:notify siblings. */
    private function queueNameOf(array $hit): string
    {
        $this->assertSame(1, preg_match('~queues:([^:]+)$~', $hit[1], $m));

        return $m[1];
    }

    public function test_dispatching_works_as_the_shop_user_and_every_key_stays_under_shop(): void
    {
        $this->bootProduction();

        $error = null;
        try {
            SendInvoiceReminder::dispatch(42);
        } catch (Throwable $e) {
            $error = $e;
        }
        $this->assertNull($error, 'dispatch failed: '.($error ? get_class($error).': '.$error->getMessage() : ''));

        $this->assertEveryKeyIsUnderShop('after a dispatch');

        $queue = $this->keysMatching('~queues:[^:]+$~');
        $this->assertCount(1, $queue, 'the job is not in exactly one queue list: '.json_encode($queue));
        $this->assertSame(1, $this->admin($queue[0][0])->lLen($queue[0][1]));
        $this->assertNotEmpty($this->keysMatching('~pending_jobs$~'), 'Horizon recorded nothing about the pushed job');
    }

    public function test_a_worker_processes_the_job_and_horizon_records_it_under_shop(): void
    {
        $this->bootProduction();
        SendInvoiceReminder::dispatch(42);

        $queues = $this->keysMatching('~queues:[^:]+$~');
        $this->assertCount(1, $queues, 'the job is not in exactly one queue list');
        $output = $this->runWorkerOnce($this->queueNameOf($queues[0]));

        $this->assertTrue(DB::table('reminders')->where('invoice_id', 42)->exists(), "the job did not run: {$output}");
        $this->assertEveryKeyIsUnderShop('after a job ran');

        $jobs = array_filter($this->keysMatching(self::UUID), fn ($hit) => $this->admin($hit[0])->type($hit[1]) === \Redis::REDIS_HASH);
        $this->assertCount(1, $jobs, 'Horizon has no record of the job');
        $record = array_values($jobs)[0];
        $this->assertSame('completed', $this->admin($record[0])->hGet($record[1], 'status'));

        $metric = $this->keysMatching('~job:App\\\\Jobs\\\\SendInvoiceReminder$~');
        $this->assertCount(1, $metric, 'Horizon has no metrics for the job class');
        $this->assertSame('1', (string) $this->admin($metric[0][0])->hGet($metric[0][1], 'throughput'));

        $measured = $this->keysMatching('~measured_jobs$~');
        $this->assertCount(1, $measured);
        $this->assertTrue($this->admin($measured[0][0])->sIsMember($measured[0][1], $metric[0][1]));
    }

    public function test_a_delayed_job_is_moved_to_the_queue_and_processed_after_its_delay(): void
    {
        $this->bootProduction();
        $dispatchedAt = hrtime(true);

        SendInvoiceReminder::dispatch(7)->delay(1);

        $delayed = $this->keysMatching('~queues:[^:]+:delayed$~');
        $this->assertCount(1, $delayed, 'the delayed job is not in the delayed set');
        $this->assertSame(1, $this->admin($delayed[0][0])->zCard($delayed[0][1]));
        preg_match('~queues:([^:]+):delayed$~', $delayed[0][1], $m);
        $queueName = $m[1];

        $output = '';
        $done = false;
        while ((hrtime(true) - $dispatchedAt) / 1e9 < 15 && ! $done) {
            usleep(250_000);
            $output = $this->runWorkerOnce($queueName);
            $done = DB::table('reminders')->where('invoice_id', 7)->exists();
        }

        $this->assertTrue($done, "the delayed job was not processed within 15 s: {$output}");
        $this->assertEveryKeyIsUnderShop('after a delayed job ran');
    }

    public function test_polling_an_empty_queue_with_the_configured_settings_neither_throws_nor_hangs(): void
    {
        $this->bootProduction();

        // The brief states no time limit; the runner's timeout bounds a hang. Only a thrown error ("read error on connection") fails.
        $popped = 'unset';
        $this->elapsed(function () use (&$popped) {
            $popped = app('queue')->connection('redis')->pop('default');
        });
        $this->assertNull($this->lastError(), 'pop on an empty queue failed: '.$this->lastError()?->getMessage());
        $this->assertNull($popped);

        $this->elapsed(fn () => $this->runWorkerOnce());
        $this->assertNull($this->lastError(), 'queue:work failed on an empty queue: '.$this->lastError()?->getMessage());
    }

    public function test_the_app_boots_without_any_redis_env(): void
    {
        $this->assertHorizonInstalled();
        $this->bootWithEnv([
            'REDIS_HOST' => null, 'REDIS_PORT' => null, 'REDIS_USERNAME' => null, 'REDIS_PASSWORD' => null,
            'REDIS_SCHEME' => null, 'REDIS_PREFIX' => null, 'REDIS_URL' => null, 'REDIS_CLIENT' => null,
            'HORIZON_PREFIX' => null,
        ]);

        $this->get('/up')->assertOk();
        $this->assertSame(0, Artisan::call('list'));
        $this->assertIsString(config('horizon.prefix'));
        $this->assertNotSame('', config('horizon.prefix'));
        $this->assertNotNull(app('queue')->connection('redis'));
    }
}
