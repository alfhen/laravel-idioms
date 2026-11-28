<?php

namespace Tests\Hidden;

use App\Models\Merchant;
use App\Models\Payout;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
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
 * Token checks must survive Valkey being down; the payouts lock must not. A request is simulated by building a new
 * application (bootWithEnv) and ending it (endRequest). Static properties of App classes are reset between requests,
 * as a new PHP request would, so a flag kept in a static does not outlive the outage in this one process.
 */
#[RunTestsInSeparateProcesses]
class ValkeyOutageHiddenTest extends TestCase
{
    use ValkeyEnv;

    private const SECRET = 'top-secret-signing-key';

    private string $sqlite;

    /** @var list<array{level: string, text: string}> */
    private array $logged = [];

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
        $this->tearDownValkeyEnv();
        @unlink($this->sqlite);
        parent::tearDown();
    }

    private function prod(array $extra = []): array
    {
        return array_merge(self::SHOP_ENV, ['DB_DATABASE' => $this->sqlite], $extra);
    }

    /** One request: a new application, the callback, then the end of the request. */
    private function request(array $env, callable $fn): mixed
    {
        $this->bootWithEnv($env);
        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $text = $e->message.' '.json_encode($e->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (isset($e->context['exception']) && $e->context['exception'] instanceof Throwable) {
                $text .= ' '.get_class($e->context['exception']).' '.$e->context['exception']->getMessage();
            }
            $this->logged[] = ['level' => $e->level, 'text' => $text, 'exception' => isset($e->context['exception']) && $e->context['exception'] instanceof Throwable];
        });
        Http::fake(['idp.example.test/*' => Http::response(['keys' => [['kid' => 'k1', 'secret' => self::SECRET]]])]);
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
        foreach (get_declared_classes() as $class) {
            if (! str_starts_with($class, 'App\\')) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                if ($property->isInitialized() && ! $property->isReadOnly()) {
                    $property->setValue($property->hasDefaultValue() ? $property->getDefaultValue() : null);
                }
            }
        }
        gc_collect_cycles();
    }

    private function token(string $payload, string $secret = self::SECRET): string
    {
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', "k1.$payload", $secret, true)), '+/', '-_'), '=');

        return "k1.$payload.$signature";
    }

    private function check(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/token-checks', ['token' => $token]);
    }

    private function jwksFetches(): int
    {
        return Http::recorded()->count();
    }

    /** True when some key on the server holds the JWKS (found by its content, so any key name or database is fine). */
    private function jwksOnServer(): bool
    {
        foreach (range(0, 15) as $db) {
            $admin = $this->admin($db);
            foreach ($this->keysIn($db) as $key) {
                $value = $admin->get($key);
                if (is_string($value) && str_contains($value, self::SECRET)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Runs the command the way php artisan does: an exception is reported and turned into exit code 1. */
    private function runCommand(string $command, array $arguments = []): int
    {
        return $this->app->make(Kernel::class)->handle(new ArrayInput(['command' => $command] + $arguments), new BufferedOutput);
    }

    private function seedMerchants(): void
    {
        $this->request($this->prod(), function () {
            $this->assertSame(0, $this->runCommand('migrate:fresh', ['--force' => true]));
        });
        $this->request($this->prod(), function () {
            Merchant::create(['name' => 'a', 'balance_cents' => 1500]);
            Merchant::create(['name' => 'b', 'balance_cents' => 2500]);
        });
    }

    /** Notice level or worse (or a reported exception), and about the cache, Redis or the connection. */
    private function assertOutageVisible(string $what): void
    {
        $serious = array_filter($this->logged, fn ($entry) => ($entry['exception'] || in_array($entry['level'], ['notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true))
            && preg_match('/redis|valkey|connection|closed\.test|tarpit\.test|refused|cache/i', $entry['text']));
        $this->assertNotEmpty($serious, "$what: the Valkey failure was not reported or logged (entries: ".json_encode($this->logged).')');
    }

    public function test_with_valkey_up_the_jwks_is_fetched_once_and_cached_on_the_server(): void
    {
        $this->request($this->prod(), function () {
            $this->check($this->token('one'))->assertOk()->assertExactJson(['valid' => true]);
            $this->check($this->token('two'))->assertOk()->assertExactJson(['valid' => true]);
            $this->assertSame(1, $this->jwksFetches(), 'the JWKS was fetched more than once');
        });
        $this->assertTrue($this->jwksOnServer(), 'the JWKS is not cached on the Valkey server');
    }

    public function test_with_valkey_up_a_held_payouts_lock_stops_another_run(): void
    {
        $this->seedMerchants();
        $this->request($this->prod(), fn () => $this->assertTrue(Cache::lock('payouts:run', 600)->get()));
        $this->request($this->prod(), function () {
            $this->runCommand('payouts:run'); // any exit code: skipping quietly and failing are both fine
            $this->assertSame(0, Payout::count(), 'a run paid out while another run held the lock');
            $this->assertSame(4000, (int) Merchant::sum('balance_cents'), 'balances changed while another run held the lock');
        });
    }

    public function test_with_valkey_up_payouts_run_pays_every_merchant_once(): void
    {
        $this->seedMerchants();
        $this->request($this->prod(), function () {
            $this->assertSame(0, $this->runCommand('payouts:run'));
            $this->assertSame(2, Payout::count());
            $this->assertSame(0, Merchant::where('balance_cents', '>', 0)->count());
        });
    }

    public function test_token_checks_answer_correctly_while_valkey_refuses_connections(): void
    {
        $this->request($this->prod(['REDIS_HOST' => 'closed.test']), function () {
            $this->check($this->token('one'))->assertOk()->assertExactJson(['valid' => true]);
            $this->assertOutageVisible('valid token');
            $this->check($this->token('two', 'a-different-secret'))->assertOk()->assertExactJson(['valid' => false]);
            $this->check('k1.three.not-a-signature')->assertOk()->assertExactJson(['valid' => false]);
            $this->assertGreaterThanOrEqual(1, $this->jwksFetches(), 'the keys must come from the identity provider when Valkey is down');
        });
    }

    public function test_payouts_run_refuses_to_run_while_valkey_refuses_connections(): void
    {
        $this->seedMerchants();
        $this->request($this->prod(['REDIS_HOST' => 'closed.test']), function () {
            $this->assertNotSame(0, $this->runCommand('payouts:run'), 'the run must exit with a failure code when it cannot take its lock');
            $this->assertSame(0, Payout::count(), 'payouts were written without the cross-container lock');
            $this->assertSame(4000, (int) Merchant::sum('balance_cents'), 'balances changed without the cross-container lock');
            $this->assertOutageVisible('payouts:run');
        });
    }

    public function test_a_token_check_answers_within_the_budget_while_valkey_does_not_answer(): void
    {
        $this->request($this->prod(['REDIS_HOST' => 'tarpit.test']), function () {
            $response = null;
            $seconds = $this->elapsed(function () use (&$response) {
                $response = $this->check($this->token('one'));
            });
            $this->assertNull($this->lastError(), 'the token check threw: '.$this->lastError()?->getMessage());
            $response->assertOk()->assertExactJson(['valid' => true]);
            $this->assertLessThan(2 * 2.0 + 2.0, $seconds, "the token check took {$seconds} s with a 2 s connect timeout");
        });
    }

    public function test_the_jwks_is_cached_again_once_valkey_is_back(): void
    {
        $this->request($this->prod(['REDIS_HOST' => 'closed.test']), function () {
            $this->check($this->token('during')); // the outcome is covered by the other tests
        });
        // "Resume without a deploy or a restart" allows a short circuit breaker: come back two minutes later.
        $this->travel(2)->minutes();
        $this->request($this->prod(), function () {
            $this->check($this->token('one'))->assertOk()->assertExactJson(['valid' => true]);
            $this->check($this->token('two'))->assertOk()->assertExactJson(['valid' => true]);
            $this->assertSame(1, $this->jwksFetches(), 'the JWKS was fetched more than once after Valkey came back');
        });
        $this->assertTrue($this->jwksOnServer(), 'the JWKS is not cached on the server after the outage');
    }
}
