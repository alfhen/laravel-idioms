#!/usr/bin/env bash
# Generates a local CA and two server certs (valkey.test, other.test) into eval/valkey/certs/.
# Idempotent: does nothing when the certs exist. Pass --force to regenerate.
set -euo pipefail
cd "$(dirname "$0")"
out=certs
if [ "${1:-}" != "--force" ] && [ -f $out/ca.crt ] && [ -f $out/valkey.test.crt ] && [ -f $out/other.test.crt ]; then
  exit 0
fi
rm -rf $out && mkdir -p $out

openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=laravel-idioms test CA" \
  -keyout $out/ca.key -out $out/ca.crt 2>/dev/null

for host in valkey.test other.test; do
  openssl req -newkey rsa:2048 -nodes -subj "/CN=$host" -keyout $out/$host.key -out $out/$host.csr 2>/dev/null
  printf 'subjectAltName=DNS:%s\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n' "$host" > $out/$host.ext
  openssl x509 -req -in $out/$host.csr -CA $out/ca.crt -CAkey $out/ca.key -CAcreateserial -days 3650 \
    -extfile $out/$host.ext -out $out/$host.crt 2>/dev/null
  rm -f $out/$host.csr $out/$host.ext
done
rm -f $out/ca.srl
# The container's valkey user must read the keys.
chmod 644 $out/*.key $out/*.crt
echo "certs written to eval/valkey/certs/"
