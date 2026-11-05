#!/usr/bin/env bash
# Restore a MySQL-made backup into a site running on SQLite (SQLite Database Integration drop-in),
# then back that SQLite site up and restore it again. Leaves dst on SQLite: re-run env/up.sh after (down -v).
set -euo pipefail
cd "$(dirname "$0")/../env"
cli() { docker compose run --rm -T -e HOME=/tmp cli-dst "$@" 2>/dev/null; }
fail() { echo "FAIL: $*"; exit 1; }
P=/var/www/html/wp-content
cli plugin is-installed sqlite-database-integration || docker compose run --rm -T -e HOME=/tmp cli-dst plugin install sqlite-database-integration >/dev/null 2>&1
docker compose exec -T dst sh -c "sed -e 's#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$P/plugins/sqlite-database-integration#' -e 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' $P/plugins/sqlite-database-integration/db.copy > $P/db.php"
cli core install --url=http://localhost:8092 --title="PBK sqlite" --admin_user=admin --admin_password=admin --admin_email=a@b.test --skip-email >/dev/null
cli eval 'global $wpdb; exit( get_class($wpdb) === "wpdb" ? 1 : 0 );' || fail "not running on SQLite"
cli eval "exit(defined(\"FQDB\") && is_file(FQDB) ? 0 : 1);" || fail "sqlite db file"
cli plugin activate pro-backups sqlite-database-integration >/dev/null

docker compose exec -T dst mkdir -p $P/pro-backups
docker compose cp "${FIXTURES:-../fixtures}/large/full-plain.wpress" dst:$P/pro-backups/ >/dev/null 2>&1
docker compose exec -T -u root dst chown -R www-data: $P/pro-backups
cli pro-backups restore full-plain.wpress --yes | tail -1 | grep -q Success || fail "restore into sqlite"
[ "$(cli option get fx_marker)" = restored-from-src ] || fail "data"
cli eval "\$o=get_option('fx_option'); exit(\$o['home']==='http://localhost:8092' ? 0 : 1);" || fail "serialized replace"
cli user check-password admin admin || fail "admin"
docker compose exec -T dst test -f $P/db.php || fail "db.php drop-in was overwritten"
cli plugin activate sqlite-database-integration >/dev/null 2>&1 || true
[ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8092/)" = 200 ] || fail "front page"
echo "OK restore into SQLite"

cli pro-backups backup --name=from-sqlite.wpress | tail -1 | grep -q from-sqlite || fail "backup from sqlite"
cli option update fx_marker changed >/dev/null
cli pro-backups restore from-sqlite.wpress --yes | tail -1 | grep -q Success || fail "restore sqlite backup"
[ "$(cli option get fx_marker)" = restored-from-src ] || fail "sqlite round trip data"
echo "OK SQLite backup round trip"
echo "SQLITE TESTS PASSED"
