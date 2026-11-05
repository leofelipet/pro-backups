#!/usr/bin/env bash
# On a multisite network the restore must refuse cleanly, before changing anything.
# Converts dst to multisite: re-create the env afterwards (docker compose down -v && env/up.sh).
set -euo pipefail
cd "$(dirname "$0")/../env"
cli() { docker compose run --rm -T -e HOME=/tmp cli-dst "$@" 2>&1 | grep -v Container || true; }
cq() { docker compose run --rm -T -e HOME=/tmp cli-dst "$@" >/dev/null 2>&1; } # exit code only
fail() { echo "FAIL: $*"; exit 1; }
cli core multisite-convert --title="PBK network" >/dev/null
cq eval 'exit(is_multisite() ? 0 : 1);' || fail "convert"
cli plugin activate pro-backups --network >/dev/null
docker compose exec -T dst mkdir -p /var/www/html/wp-content/pro-backups
docker compose cp "${FIXTURES:-../fixtures}/large/full-plain.wpress" dst:/var/www/html/wp-content/pro-backups/ >/dev/null 2>&1
docker compose exec -T -u root dst chown -R www-data: /var/www/html/wp-content/pro-backups
before=$(cli option get blogname)
out=$(cli pro-backups restore full-plain.wpress --yes)
echo "$out" | grep -q "multisite network is not supported" || fail "message: $out"
[ "$(cli option get blogname)" = "$before" ] || fail "site changed"
cq eval 'exit(is_multisite() ? 0 : 1);' || fail "network broken"
echo "MULTISITE TESTS PASSED"
