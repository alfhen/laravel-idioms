#!/usr/bin/env bash
# usage: run-hidden.sh <app_dir> <hidden_test_file> <junit_out>
# Runs one hidden test inside the PHP container (phpredis) next to the Valkey stack.
# app_dir is a throwaway copy that already holds tests/Hidden/<file>. Its .env and .env.testing are deleted,
# so the app runs from config defaults, phpunit.xml and the env vars the test sets itself.
# The exit code is the test runner's (124 when the test run exceeds VALKEY_TEST_TIMEOUT seconds, default 840).
# Exit code 3 means the infrastructure failed (Docker down, image build failed, Valkey reset failed): the test never
# ran, so the caller must not score it. PHPUnit itself exits 0, 1 or 2, never 3.
# VALKEY_CONTAINER_NAME names the runner container (default: unique per run), so a caller can `docker rm -f` it.
set -uo pipefail

app=$(cd "${1:?app_dir}" && pwd)
hidden=$(basename "${2:?hidden_test_file}")
junit_out=${3:?junit_out}
here=$(cd "$(dirname "$0")" && pwd)
project=laravel-idioms-valkey
compose=(docker compose -p "$project" -f "$here/docker-compose.yml")
app_key='base64:aGlkZGVuLXRlc3Qta2V5LTAxMjM0NTY3ODlhYmNkZWY='

[ -f "$app/tests/Hidden/$hidden" ] || { echo "run-hidden: $app/tests/Hidden/$hidden missing" >&2; exit 2; }

# One run at a time across processes: every run resets the shared Valkey. The lock is an flock on a file, held by
# this script and its children through an inherited descriptor. The kernel drops it when the last holder dies, so a
# SIGKILLed run cannot leave it behind (a mkdir lock would stay forever). The script re-executes itself under the lock.
if [ -z "${VALKEY_LOCK_HELD:-}" ]; then
  lockfile="${TMPDIR:-/tmp}/laravel-idioms-valkey.flock"
  VALKEY_LOCK_HELD=1 exec perl -e 'use Fcntl qw(:flock); $^F = 255; open(my $fh, ">>", shift @ARGV) or die "run-hidden: cannot open lock file: $!"; flock($fh, LOCK_EX) or die "run-hidden: flock failed: $!"; exec @ARGV or die "run-hidden: exec failed: $!"' \
    "$lockfile" bash "$0" "$@"
fi

name="${VALKEY_CONTAINER_NAME:-valkey-hidden-$$-$RANDOM$RANDOM}"
trap 'docker rm -f "$name" >/dev/null 2>&1' EXIT

"$here/gen-certs.sh" >&2 || { echo "run-hidden: gen-certs failed" >&2; exit 3; }
"${compose[@]}" up -d --wait >&2 || { echo "run-hidden: could not start the Valkey stack" >&2; exit 3; }
docker image inspect laravel-idioms-valkey-php:local >/dev/null 2>&1 || "${compose[@]}" --profile run build php >&2 || { echo "run-hidden: image build failed" >&2; exit 3; }

# Reset shared state: ACL users from users.acl, no replication, empty databases.
cli=("${compose[@]}" exec -T valkey valkey-cli --tls --cacert /certs/ca.crt --user admin --pass adminpass --no-auth-warning)
"${cli[@]}" ACL LOAD >&2 && "${cli[@]}" REPLICAOF NO ONE >&2 && "${cli[@]}" FLUSHALL >&2 || { echo "run-hidden: Valkey reset failed" >&2; exit 3; }

rm -f "$app/.env" "$app/.env.testing" "$app/.hidden-junit.xml"
# A build that ran config:cache or route:cache would freeze the config and ignore the env the tests set.
find "$app/bootstrap/cache" -maxdepth 1 -name '*.php' -delete 2>/dev/null
# PHPUnit even for Pest builds: hidden tests are plain PHPUnit classes (reconcile() handles both name styles),
# and Pest without a .env prints a warning per test ("file_get_contents(/app/.env)") that PHPUnit does not.
runner=vendor/bin/phpunit

"${compose[@]}" --profile run run --rm -T --name "$name" \
  --user "$(id -u):$(id -g)" \
  -v "$app:/app" \
  -e APP_KEY="$app_key" \
  php timeout -k 5 "${VALKEY_TEST_TIMEOUT:-840}" php "$runner" "tests/Hidden/$hidden" --log-junit /app/.hidden-junit.xml
code=$?

mkdir -p "$(dirname "$junit_out")"
if [ -s "$app/.hidden-junit.xml" ]; then cp "$app/.hidden-junit.xml" "$junit_out"; else rm -f "$junit_out"; fi
exit $code
