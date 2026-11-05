#!/usr/bin/env bash
# End-to-end restore: real AI1WM backups of src (:8091) restored into dst (:8092) through the AJAX endpoints.
# Requires: tests/env/up.sh and tests/env/make-fixtures.sh.
set -euo pipefail
cd "$(dirname "$0")/../env"
URL=http://localhost:8092
AJAX=$URL/wp-admin/admin-ajax.php
cli() { docker compose run --rm -T -e HOME=/tmp cli-dst "$@" 2>/dev/null; }
fail() { echo "FAIL: $*"; exit 1; }
json() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d; foreach(explode(".",$argv[1]) as $k){$v=$v[$k]??null;} echo is_scalar($v)?$v:json_encode($v);' "$1"; }

restore() { # $1 fixture, $2 password ('' = none)
	local fixture=$1 password=$2
	echo "== restore $fixture"
	cli plugin activate pro-backups >/dev/null
	cli option update pbk_keep_me "survives" >/dev/null
	docker compose exec -T dst mkdir -p /var/www/html/wp-content/pro-backups
	docker compose cp "../fixtures/large/$fixture" "dst:/var/www/html/wp-content/pro-backups/$fixture" >/dev/null
	docker compose exec -T -u root dst chown -R www-data: /var/www/html/wp-content/pro-backups

	local start; start=$(cli eval "\$j=PBK_Importer::start(PBK_Storage::find('$fixture')); echo json_encode(['id'=>\$j->id,'token'=>\$j->data['token']]);")
	local id token; id=$(echo "$start" | json id); token=$(echo "$start" | json token)
	local status="running" resp="" n=0
	while [ $n -lt 500 ]; do
		n=$((n+1))
		resp=$(curl -s -X POST "$AJAX" -d action=pbk_run -d id="$id" -d token="$token")
		status=$(echo "$resp" | json data.status)
		[ "$status" = running ] && continue
		if [ "$status" = input ]; then
			need=$(echo "$resp" | json data.need)
			if [ "$need" = password ]; then
				[ -n "$password" ] || fail "asked for password"
				# first a wrong password must be rejected
				if [ "${tried_wrong:-0}" = 0 ]; then
					tried_wrong=1
					curl -s -X POST "$AJAX" -d action=pbk_input -d id="$id" -d token="$token" -d password=wrong >/dev/null
				else
					curl -s -X POST "$AJAX" -d action=pbk_input -d id="$id" -d token="$token" --data-urlencode password="$password" >/dev/null
				fi
			else
				curl -s -X POST "$AJAX" -d action=pbk_input -d id="$id" -d token="$token" -d confirm=1 >/dev/null
			fi
			continue
		fi
		break
	done
	echo "$resp" | json data.log | tr ',' '\n' | tail -6
	[ "$status" = done ] || fail "status=$status $(echo "$resp" | json data.message)"
	[ -n "$password" ] && [ "${tried_wrong:-0}" = 1 ] || [ -z "$password" ] || fail "wrong password path not exercised"

	# bad token must be refused
	code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$AJAX" -d action=pbk_run -d id="$id" -d token=nope)
	[ "$code" = 404 ] || fail "bad token accepted ($code)"

	[ "$(cli option get fx_marker)" = restored-from-src ] || fail "marker option"
	[ "$(cli option get siteurl)" = "$URL" ] || fail "siteurl changed"
	[ "$(cli option get pbk_keep_me)" = survives ] || fail "own options not preserved"
	cli eval "\$o=get_option('fx_option'); exit(\$o['home']==='$URL' && \$o['list'][1]==='$URL/b' ? 0 : 1);" || fail "serialized option not replaced"
	cli eval "\$p=get_page_by_title('PBK Fixture Post', OBJECT, 'post'); \$m=get_post_meta(\$p->ID,'fx_serialized',true); exit(strpos(\$p->post_content,'$URL/hello/')!==false && strpos(\$p->post_content,'localhost:8091')===false && \$m['url']==='$URL' && \$m['nested']['path']===WP_CONTENT_DIR ? 0 : 1);" || fail "post content/meta not replaced"
	cli eval 'global $wpdb; $v=$wpdb->get_col("SELECT v FROM {$wpdb->prefix}fx_custom ORDER BY id"); exit($v[0]==="line1\nline2 ; with semicolon;\n" && $v[1]==="http://localhost:8092" ? 0 : 1);' || fail "custom table"
	docker compose exec -T dst test -f /var/www/html/wp-content/uploads/pbk-fixture.txt || fail "media file"
	cli plugin is-active pro-backups || fail "plugin not active after restore"
	cli user check-password admin admin || fail "admin login from backup"
	[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/")" = 200 ] || fail "front page"
	echo "OK $fixture"
}

restore full-plain.wpress ''
restore full-encrypted-gzip.wpress secret123
if docker compose exec -T dst php -m | grep -q bz2; then
	restore full-encrypted-bzip2.wpress secret123
else
	echo "== bzip2 without ext-bz2 must fail cleanly"
	cli plugin activate pro-backups >/dev/null
	docker compose cp ../fixtures/large/full-encrypted-bzip2.wpress dst:/var/www/html/wp-content/pro-backups/ >/dev/null 2>&1
	docker compose exec -T -u root dst chown -R www-data: /var/www/html/wp-content/pro-backups
	start=$(cli eval "\$j=PBK_Importer::start(PBK_Storage::find('full-encrypted-bzip2.wpress')); echo json_encode(['id'=>\$j->id,'token'=>\$j->data['token']]);")
	out=$(curl -s -X POST "$AJAX" -d action=pbk_run -d id="$(echo "$start" | json id)" -d token="$(echo "$start" | json token)")
	echo "$out" | grep -q 'bz2 extension is missing' || fail "bzip2 error message: $out"
	cli plugin is-active pro-backups || fail "site changed by failed restore"
	echo OK
fi
echo "ALL RESTORE TESTS PASSED"
