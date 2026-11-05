#!/usr/bin/env bash
# Backups made by Pro Backups on src, restored on dst by Pro Backups AND by the real AI1WM plugin.
set -euo pipefail
cd "$(dirname "$0")/../env"
csrc() { docker compose run --rm -T -e HOME=/tmp cli-src "$@" 2>&1 | grep -v Container; }
cdst() { docker compose run --rm -T -e HOME=/tmp cli-dst "$@" 2>&1 | grep -v Container; }
cq() { docker compose run --rm -T -e HOME=/tmp cli-dst "$@" >/dev/null 2>&1; } # exit code only
fail() { echo "FAIL: $*"; exit 1; }
check_dst() { # $1 label
	[ "$(cdst option get fx_marker)" = restored-from-src ] || fail "$1: marker"
	[ "$(cdst option get siteurl)" = http://localhost:8092 ] || fail "$1: siteurl"
	cq eval "\$o=get_option('fx_option'); exit(\$o['home']==='http://localhost:8092' ? 0 : 1);" || fail "$1: serialized replace"
	cq eval 'global $wpdb; $v=$wpdb->get_col("SELECT v FROM {$wpdb->prefix}fx_custom ORDER BY id"); exit($v[0]==="line1\nline2 ; with semicolon;\n" ? 0 : 1);' || fail "$1: custom table"
	cq user check-password admin admin || fail "$1: admin user"
	cq eval 'exit(current_user_can("manage_options") || user_can(get_user_by("login","admin"),"manage_options") ? 0 : 1);' || fail "$1: admin role (user_roles prefix)"
	docker compose exec -T dst test -f /var/www/html/wp-content/uploads/pbk-fixture.txt || fail "$1: media"
	[ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8092/)" = 200 ] || fail "$1: front page"
	echo "OK $1"
}
transfer() { # $1 name, $2 dst folder
	docker compose cp "src:/var/www/html/wp-content/pro-backups/$1" "/tmp/pbk-$1" >/dev/null 2>&1
	docker compose exec -T dst mkdir -p "/var/www/html/wp-content/$2"
	docker compose cp "/tmp/pbk-$1" "dst:/var/www/html/wp-content/$2/$1" >/dev/null 2>&1
	docker compose exec -T -u root dst chown -R www-data: "/var/www/html/wp-content/$2"
	rm -f "/tmp/pbk-$1"
}

csrc plugin activate pro-backups >/dev/null
docker compose exec -T src sh -c 'rm -f /var/www/html/wp-content/pro-backups/pbk-*.wpress'
docker compose exec -T dst sh -c 'rm -f /var/www/html/wp-content/pro-backups/pbk-*.wpress /var/www/html/wp-content/ai1wm-backups/pbk-*.wpress'
for variant in "plain::" "gz:gzip:" "bz:bzip2:" "enc-gz:gzip:secret123"; do
	IFS=: read -r label comp pw <<<"$variant"
	name="pbk-$label.wpress"
	args=(pro-backups backup --name="$name" --exclude=cache)
	[ -n "$comp" ] && args+=(--compression="$comp")
	[ -n "$pw" ] && args+=(--password="$pw")
	csrc "${args[@]}" | tail -1 | grep -q "$name" || fail "backup $label"
	transfer "$name" pro-backups
	cdst plugin activate pro-backups >/dev/null; cdst option update fx_marker before >/dev/null
	rargs=(pro-backups restore "$name" --yes); [ -n "$pw" ] && rargs+=(--password="$pw")
	cdst "${rargs[@]}" | tail -1 | grep -q Success || fail "pbk restore $label"
	check_dst "pro-backups restore of pro-backups $label"
done

# Same archive restored by the real All-in-One WP Migration plugin.
for variant in "plain:" "enc-gz:secret123"; do
	IFS=: read -r label pw <<<"$variant"
	name="pbk-$label.wpress"
	transfer "$name" ai1wm-backups
	cdst plugin activate all-in-one-wp-migration >/dev/null; cdst option update fx_marker before >/dev/null
	echo "$pw" | docker compose run --rm -T -e HOME=/tmp cli-dst eval-file wp-content/plugins/pro-backups/tests/env/ai1wm-import.php "$name" 2>&1 | tail -1 | grep -q Success || fail "ai1wm import $label"
	check_dst "AI1WM restore of pro-backups $label"
done

# Exclusions are honoured.
csrc pro-backups backup --name=pbk-small.wpress --exclude=media,plugins,themes,cache --exclude-tables=wp_fx_custom | tail -1 >/dev/null
docker compose exec -T src php -r '
	define("ABSPATH","/tmp/"); foreach (glob("/var/www/html/wp-content/plugins/pro-backups/includes/class-pbk-*.php") as $f) require $f;
	$r = new PBK_Archive_Reader("/var/www/html/wp-content/pro-backups/pbk-small.wpress");
	$n = array_column(iterator_to_array($r->entries(), false), "name");
	$bad = preg_grep("#^(uploads|plugins|themes)/#", $n);
	$sql = $r->read_file("database.sql");
	exit(!$bad && strpos($sql, "fx_custom") === false && strpos($sql, "SERVMASK_PREFIX_options") !== false ? 0 : 1);' || fail "exclusions"
echo "OK exclusions"
echo "ALL EXPORT TESTS PASSED"
