# Plan: Redis and Valkey connections (round 9)

Goal: cover Redis connection configuration, which the skill had nothing on. `references/caching.md` covers cache use (TTLs, locks, tags, rate limiters), but not how the app reaches the server. A production move of a Laravel 12.73 app (Apache mod_php, PHP 8.2, phpredis 6.3, 0.5 vCPU tasks) from the `database` cache store to TLS-only ElastiCache Valkey 8 with a per-tenant ACL user hit every gap. The evidence bar is the same as round 8, with one addition: the new tasks' hidden tests run against a real server.

## What the research found

Five researchers each covered one area and cited source by tag and line: Laravel's `Illuminate/Redis` and cache stores (11.57.2, 12.73/12.74, 13.44.1) and the skeletons; phpredis 6.3.0 and 6.4.0RC1 (`library.c`, `redis.c`, the tracker); predis 2.4.1 and 3.8.0; Redis, Valkey and managed services (ACL categories, cluster mode, ElastiCache, eviction); and runtimes (Octane, `queue:work`, Horizon, FPM, outages, store switches). The phpredis researcher reproduced the pool and retry behaviour against Valkey 8 in Docker. What the round acts on:

| Area | Finding |
|---|---|
| Config | No skeleton (11.x to 13.x) has a `scheme` key, so `REDIS_SCHEME=tls` does nothing. `url` overrides scheme, host and database. Unset `timeout`/`read_timeout` pass `0.0`, which phpredis and predis treat as `default_socket_timeout` (60 s). Keys on the server are connection prefix + `cache.prefix` + key. |
| Persistent connections | phpredis pools persistent streams by default with key `host:port`, so `persistent_id` is ignored and `default` (db 0) and `cache` (db 1) share sockets. phpredis's own retry (`max_retries`, skeleton 3) reconnects and re-selects only a non-zero db, so a db 0 lock can land in db 1 (issue #1920, open). `max_retries => 0` hands the reconnect to Laravel, which SELECTs. |
| Failover | 11.x and 12.0 to 12.37 don't reconnect on READONLY; 12.38 to 13.35 rebuild without closing the old client, so a pooled stale socket can keep hitting the demoted primary; 13.26+ retries idempotent commands once; 13.36+ closes the old client. |
| Outages | Nothing in cache, locks, the rate limiter or sessions catches a Redis error. The `failover` store (12.35+) retries every store on every call and is no lock guarantee. |
| ACL | Laravel needs EVAL (`Cache::add`, locks, queues), MULTI and SELECT. `-@dangerous` blocks `cache:clear` (FLUSHDB), KEYS and INFO by design; key patterns don't limit FLUSHDB. PING and CLIENT SETNAME need their own grants. |
| Other | The `database` store never prunes expired rows. A rolling store switch splits locks and mutexes. ElastiCache defaults to `volatile-lru`, which can evict held locks. Horizon writes under its own prefix, which an ACL pattern must allow. |

Left out because unverified: MemoryDB, Upstash, Memorystore and Redis Cloud specifics; Valkey 9 multi-db cluster limits; phpredis 6.4.0RC1 `reconnect()`.

## Skill changes

1. **New `references/redis.md`**, with version scope on each rule: client choice and the keys that differ, connection config (one tested snippet), ACL users, persistent connections, failover and READONLY, outages and fail-open, the `database` store, store switches, eviction, testing without Redis, cluster mode.
2. **`SKILL.md` §8**: four bullets scoped to tasks that set up or change Redis connections (so they don't push config changes or fail-open wrappers into other tasks), a checklist line, a References row.
3. **`references/caching.md`**: the `failover` line and the `flush()` line point to the new file.

## Eval changes

1. **`eval/valkey/`**: a PHP 8.5 + phpredis 6.3.0 runner, a TLS-only Valkey 8 with ACL users, a tarpit host and a refused host. `hidden.py` runs tasks with `"hidden_runner": "valkey"` there, one at a time under a kernel lock, and refuses to score a run whose infrastructure failed.
2. **`base-redis-shop`**: `base-pest` plus a small shop already moved to the node correctly (TLS, ACL user, prefix, 2 s timeouts), with a token-check endpoint, a JWKS client and a `payouts:run` command that takes a cache lock.
3. **Tasks** (round `redis`):

   | id | Base | Set | Behaviour the hidden tests check |
   |---|---|---|---|
   | `valkey-cache` | pest | target | cache and locks work through the ACL with only the production env; every key under `shop:`; a silent or blocked node gives up within the budget; the certificate host name is checked; local boot without Redis env |
   | `persistent-valkey` | redis-shop | target | connections reused across requests; after a dropped socket a lock stays in the lock database and blocks another run, where old containers look for it; recovery after a demote and promote |
   | `valkey-outage` | redis-shop | target | token checks answer correctly and in time while Valkey refuses or is silent; `payouts:run` pays nobody and exits non-zero without its lock; the outage is logged; caching resumes |
   | `valkey-queue` | pest | holdout | dispatch, processing and delayed jobs through the ACL with Horizon installed, every key under `shop:`; an empty pop doesn't error |
   | `orders-today` | api | probe | a cached count, judged on proportion |
   | 7 earlier tasks: scheduled-reports, shipment-sync, coupon-redeem, carrier-quote, token-login, caching, sales-report | — | regression | their own hidden tests where they have them |

4. **Reference gate**: every hidden test passed two or more correct reference builds of different designs, and the trap tests failed a naive build.

## Test protocol

- **Arms:** `plain`, `prev` (v0.5.1) and `skill`, Sonnet builders, headless, with a prompt that never names the skill.
- **Order:** research, then the draft, then an Opus accuracy review of the draft against source and the Valkey stack (all findings fixed), then the tasks and the reference gate, then the `prev` and `plain` pilot, then an Opus fairness review of the tasks (test-only fixes; briefs frozen after the pilot), then the skill builds.
- **Builds:** targets and holdout 3 × `skill`, 3 × `prev`, 1 × `plain`; probe 2 × `skill`, 2 × `prev`, 1 × `plain`; regression 2 × `skill`, 2 × `prev`.
- **Judging:** 2 blind, sandboxed Opus judges per task, replicates 1 and 2.
- **Pass bar:**
  1. Hidden tests on the new tasks: `skill` above `prev` and `plain` in total and not lower on any task.
  2. Judge mean on targets not lower than `prev`.
  3. Regression mean ≥ `prev` − 0.3 in both replicates, regression hidden tests not lower, probe simplicity ≥ `prev` − 0.5.
  4. The skill fired in every `skill` and `prev` build.
- **Revise loop (at most two):** from target-task judge findings only; rebuild `skill` on the tasks a revision could touch.

## Outcome

1. Hidden tests: 181/181 against 169/181 (`prev`) and 33/37 (`plain`). persistent-valkey separates the arms (45/45 against 33/45 and 11/15); the other new tasks are at the ceiling for every arm. **Met.**
2. Judge mean on targets: 6.55 / 6.97 against 7.83 / 7.57. **Not met.** Most of the gap is valkey-cache and valkey-outage, where the judges found real issues (two connections per request, `tinker` subprocess tests, cutover steps). On persistent-valkey (lower in replicate 1, level in replicate 2) the judges listed none of the `prev` builds' lock leaks and asked for a `persistent_id` that phpredis ignores, but they also docked a real cost of `max_retries => 0` (a mid-request drop makes the next lock or write throw), which the text now states with an alternative.
3. Regression 7.97 / 7.75 against 7.81 / 7.97, regression hidden tests 74/74 for both, probe simplicity 8.00 / 8.50 against 7.00 / 8.00. **Met.**
4. The skill fired in 56/56 builds. **Met.**

Follow-ups:
- Harder tasks for the rest of the reference: the four new tasks other than persistent-valkey stated their requirements so plainly that every arm met them. A task where the trap is implied (a time budget not spelled out, an existing `REDIS_URL`, a cluster endpoint) would measure more of the text.
- Judges can't observe Redis behaviour. A judge probe environment with the Valkey stack, or a rubric note pointing at the hidden-test results, would stop the rubric from rewarding the wrong fix.
- The ClosingPhpRedisConnection workaround for 11.x is not run end to end (verified on 12.37 and 12.74 only).
