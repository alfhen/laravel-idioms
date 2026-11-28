# Valkey stack for hidden tests

Some hidden tests need a real TLS-only Valkey with ACL users and PHP with phpredis. The host has no phpredis, so those tests run in a container. This directory holds that stack.

## Versions

- PHP 8.5 (`php:8.5-cli`), phpredis 6.3.0.
- Valkey 8 (`valkey/valkey:8`).
- The base apps need PHP 8.3 or newer, so 8.5 and 8.6 both run their `vendor`.
- phpredis 6.3.0 does not compile on PHP 8.6. The newest 6.x that does is 6.4.0RC1, a release candidate. I kept 8.5 and the stable 6.3.0. Try the other pair with `--build-arg PHP_VERSION=8.6 --build-arg PHPREDIS_VERSION=6.4.0RC1`.
- The php:8.4+ images have no `pecl`, so the Dockerfile builds phpredis from the PECL tarball.
- Extensions: pdo_sqlite, mbstring, bcmath, intl, zip, pcntl, redis.
- `default_socket_timeout=15` is set in `php-hidden.ini`. A client with no timeout set would otherwise hang until the harness's 900 s limit. Now it fails after 15 s.

## Run

```bash
eval/valkey/run-hidden.sh <app_dir> <hidden_test_file> <junit_out>
```

`hidden.py` calls this for tasks with `"hidden_runner": "valkey"`. You can also call it by hand on a throwaway copy of an app. It changes the app dir: it deletes `.env` and `.env.testing` there.

The script:

1. Creates the certs if missing (`gen-certs.sh`) and starts the stack (`docker compose -p laravel-idioms-valkey up -d --wait`). The first run builds the PHP image, which takes a few minutes.
2. Reloads `users.acl`, runs `REPLICAOF NO ONE` and `FLUSHALL` as admin.
3. Runs `php vendor/bin/phpunit tests/Hidden/<file> --log-junit ...` in the php container with the app mounted at `/app`, `APP_KEY` set to a fixed test key, and a time limit of 840 s (`VALKEY_TEST_TIMEOUT`).
4. Copies the JUnit report to `<junit_out>` and exits with the test runner's code.

Before step 1 the script takes an `flock` on `$TMPDIR/laravel-idioms-valkey.flock` (through a small `perl` wrapper), so concurrent callers queue and a killed run cannot leave the lock behind. It deletes `bootstrap/cache/*.php` in the app copy, and names the runner container (`VALKEY_CONTAINER_NAME`, unique by default), which it removes on exit. Exit code 3 means the infrastructure failed (Docker, image build, reset): `hidden.py` raises on it instead of scoring the build.

It uses PHPUnit even for Pest builds. The hidden tests are plain PHPUnit classes. Pest without a `.env` prints a warning for every test.

Valkey tests share one server, so `hidden.py` runs them one at a time, whatever `--parallel` says.

Stop the stack with `docker compose -p laravel-idioms-valkey -f eval/valkey/docker-compose.yml down`.

## Services

| Service | Alias | Use |
|---|---|---|
| `valkey` | `valkey.test:6379`, `valkey-alias.test:6379` | TLS only (`--port 0`), no client certs, `DEBUG` enabled (tests use `DEBUG SLEEP`), 16 databases, `noeviction`, no persistence. |
| `tarpit` | `tarpit.test:6379` | Accepts TCP and never sends a byte. A TLS client hangs in the handshake until its connect timeout. |
| `closed` | `closed.test` | The name resolves, nothing listens. Connections are refused at once. |
| `php` | none | Runner. Profile `run`, so `up` does not start it. `openssl.cafile` points at the generated CA, so TLS to `valkey.test` verifies with no app config. |

`valkey-alias.test` is a second name for the same server. Its certificate does not cover that name (only `valkey.test`), so a client that connects to it and verifies the host name must fail. Use it to test that a build did not turn off TLS peer or name verification.

`gen-certs.sh` makes a local CA and one server cert each for `valkey.test` and `other.test`, in `certs/` (git-ignored). Valkey uses the `valkey.test` cert. The `other.test` cert is for a wrong-hostname test: connect to `valkey.test` expecting a different name, or swap the cert in. Use `--force` to regenerate.

## Users (`users.acl`)

- `default`: off.
- `admin` / `adminpass`: everything.
- `shop` / `shoppass`: keys `shop:*` and channels `shop:*` only, all commands except `@dangerous` (but `INFO` is allowed), `FUNCTION`, `SCRIPT`, `SCAN`, `RANDOMKEY` and `CLIENT TRACKING`. `SELECT`, `EVAL` and `EVALSHA` work. `FLUSHDB`, `KEYS`, `SCAN` and `CLIENT LIST` give NOPERM, and so does any key outside `shop:*`.

## Test helpers

`HiddenValkeyHelpers.php.txt` holds the trait `ValkeyEnv`. A hidden test is one self-contained file, so paste the trait between the `use` imports and the class, and add `use ValkeyEnv;` in the class. It needs no imports. See `SmokeHiddenTest.php` for a pasted copy. Keep the `.txt` as the single source and re-paste after edits.

- `bootWithEnv(array $env)`: sets vars in `$_ENV`, `$_SERVER` and `putenv`, undoes the previous call, then `refreshApplication()`. A `null` value unsets the var.
- `SHOP_ENV`: production-like env for the `shop` user (`CACHE_STORE=redis`, host, port, user, password, `REDIS_SCHEME=tls`). Use `self::SHOP_ENV + ['REDIS_PREFIX' => 'shop:']`.
- `admin(int $db = 0)`: phpredis client over TLS as admin.
- `keysIn(int $db)`: sorted keys from `SCAN` as admin. Keys are raw, with no prefix.
- `flushValkey()`: `FLUSHALL`. The harness flushes once per run, not per test.
- `killClientsOf(string $user)`: `CLIENT KILL USER`.
- `elapsed(callable)`: seconds taken. It catches a Throwable. Read it with `lastError()`.

Env caveats:

- Laravel's `Env` keeps a static repository, but it caches only the adapter list. Each `env()` call reads `$_ENV`, then `$_SERVER`, then `getenv()`. A value must be set and cleared in all three.
- `phpunit.xml` sets some vars (`CACHE_STORE=array`, `DB_DATABASE=:memory:`). The helper restores them rather than unsetting.
- Config is read once per app. Env changes reach `config()` only after `refreshApplication()`.
- `refreshApplication()` drops an in-memory SQLite database. Call `bootWithEnv()` before using the database.
- There is no `.env`. The app runs on config defaults plus the vars the test sets.

## Smoke test

`SmokeHiddenTest.php` checks the stack end to end. Run it on a copy of an app:

```bash
cp -cR eval/.work/base-pest /tmp/smoke-app && mkdir -p /tmp/smoke-app/tests/Hidden
cp eval/valkey/SmokeHiddenTest.php /tmp/smoke-app/tests/Hidden/
eval/valkey/run-hidden.sh /tmp/smoke-app SmokeHiddenTest.php /tmp/smoke-junit.xml
```

It covers: the `shop` user caching over TLS with a `scheme` config, stock config failing, env restore, the tarpit giving up at a 2 s connect timeout, a refused port, and a read timeout of 1 s while another process runs `DEBUG SLEEP 5`.

## Add a Valkey task

1. Write the hidden test as one file under `eval/hidden/`. Paste in the `ValkeyEnv` trait. Have the test set its own env with `bootWithEnv()`.
2. In `eval/tasks.json`, add `"hidden": "eval/hidden/<Name>HiddenTest.php"` and `"hidden_runner": "valkey"` to the task.
3. Check the test against correct reference builds with `python3 eval/hidden.py --tasks <task>`.
4. Keep tests that share the server from depending on each other's keys. Call `flushValkey()` in `setUp()`.

The runner container touches `/app/.hidden-started` before PHPUnit starts. If the marker is missing after the run, Compose failed before the container ran (it can exit 1, like a failing PHPUnit), so the script exits 3 and `hidden.py` does not score the run.
