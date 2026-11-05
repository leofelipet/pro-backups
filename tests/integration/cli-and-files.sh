#!/usr/bin/env bash
# WP-CLI restore + list + delete, and authenticated download/delete from the admin list.
set -euo pipefail
cd "$(dirname "$0")/../env"
U=http://localhost:8092
cli() { docker compose run --rm -T -e HOME=/tmp cli-dst "$@" 2>&1 | grep -v Container; }
fail() { echo "FAIL: $*"; exit 1; }
put() { docker compose cp "${FIXTURES:-../fixtures}/large/$1" "dst:/var/www/html/wp-content/pro-backups/$1" >/dev/null 2>&1; docker compose exec -T -u root dst chown -R www-data: /var/www/html/wp-content/pro-backups; }

cli plugin activate pro-backups >/dev/null
put full-plain.wpress; put full-encrypted-gzip.wpress
out=$(cli pro-backups list); echo "$out" | grep -q full-plain.wpress || fail "list"
cli option update fx_marker before >/dev/null
cli pro-backups restore full-plain.wpress --yes | tail -1 | grep -q "Success: Restore complete" || fail "cli restore"
[ "$(cli option get fx_marker)" = restored-from-src ] || fail "cli restore data"
{ cli pro-backups restore full-encrypted-gzip.wpress --yes --password=wrong || true; } | grep -q "pass --password" || fail "wrong password message"
cli pro-backups restore full-encrypted-gzip.wpress --yes --password=secret123 | tail -1 | grep -q "Success" || fail "encrypted cli restore"
cli pro-backups restore /var/www/html/wp-content/pro-backups/full-plain.wpress --yes | tail -1 | grep -q Success || fail "restore by path"

# admin download + delete
jar=$(mktemp); trap 'rm -f $jar' EXIT
curl -s -c "$jar" -b "$jar" -o /dev/null "$U/wp-login.php"
curl -s -c "$jar" -b "$jar" -o /dev/null -d log=admin -d pwd=admin -d wp-submit=1 -d testcookie=1 "$U/wp-login.php"
page=$(curl -s -b "$jar" "$U/wp-admin/admin.php?page=pro-backups")
dl=$(echo "$page" | grep -o 'href="[^"]*action=pbk_download[^"]*file=full-plain.wpress[^"]*"' | head -1 | sed 's/href="//;s/"$//;s/&#038;/\&/g;s/&amp;/\&/g')
[ -n "$dl" ] || fail "download link missing"
[ "$(curl -s -b "$jar" "$dl" | md5sum | cut -c1-32)" = "$(md5sum < ${FIXTURES:-../fixtures}/large/full-plain.wpress | cut -c1-32)" ] || fail "download content"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$dl")" != 200 ] || fail "download without login"
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" "${dl/_wpnonce=/_wpnonce=x}")" = 403 ] || fail "download with bad nonce"
del=$(echo "$page" | grep -o 'href="[^"]*action=pbk_delete[^"]*file=full-plain.wpress[^"]*"' | head -1 | sed 's/href="//;s/"$//;s/&#038;/\&/g;s/&amp;/\&/g')
curl -s -b "$jar" -o /dev/null "$del"
docker compose exec -T dst test ! -f /var/www/html/wp-content/pro-backups/full-plain.wpress || fail "delete"
cli pro-backups delete full-encrypted-gzip.wpress | grep -q Success || fail "cli delete"
echo "CLI AND FILE TESTS PASSED"
