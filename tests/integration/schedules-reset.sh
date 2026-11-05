#!/usr/bin/env bash
# Scheduled backups through WP-Cron, retention, reset (via the web endpoints) and undo by restoring the safety backup.
# Runs on dst (it gets wiped and restored).
set -euo pipefail
cd "$(dirname "$0")/../env"
U=http://localhost:8092
AJAX=$U/wp-admin/admin-ajax.php
cli() { docker compose run --rm -T -e HOME=/tmp cli-dst "$@" 2>/dev/null; }
fail() { echo "FAIL: $*"; exit 1; }
json() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d; foreach(explode(".",$argv[1]) as $k){$v=$v[$k]??null;} echo is_scalar($v)?$v:json_encode($v);' "$1"; }

# Fresh dst content = src backup
docker compose cp ../fixtures/large/full-plain.wpress dst:/var/www/html/wp-content/pro-backups/ >/dev/null 2>&1
docker compose exec -T -u root dst chown -R www-data: /var/www/html/wp-content/pro-backups
cli plugin activate pro-backups >/dev/null
cli pro-backups restore full-plain.wpress --yes >/dev/null || fail "seed restore"
docker compose exec -T dst sh -c 'rm -f /var/www/html/wp-content/pro-backups/*.wpress'

# --- schedules + retention -------------------------------------------------------------------
cli eval 'foreach (PBK_Schedules::all() as $s) PBK_Schedules::delete($s["id"]);' >/dev/null
cli option update pbk_settings '{"keep_count":2,"keep_days":0,"max_total_mb":0}' --format=json >/dev/null
id=$(cli eval 'echo PBK_Schedules::save(array("name"=>"Nightly","frequency"=>"daily","time"=>"03:00","enabled"=>true,"options"=>array("exclude"=>array("media","cache"))));')
cli cron event list --hook=pbk_schedule_run --fields=hook,recurrence --format=csv | grep -q "pbk_schedule_run,\"\?1 day" || fail "cron event not registered"
for i in 1 2 3; do
	cli cron event run pbk_schedule_run >/dev/null || fail "cron run" 
	for _ in $(seq 1 30); do # unfinished jobs continue in follow-up cron events
		cli cron event list --hook=pbk_job_continue --format=count | grep -q '^0$' && break
		cli cron event run pbk_job_continue >/dev/null 2>&1 || true
	done
	sleep 1 # distinct mtimes
done
[ "$(cli eval "echo PBK_Schedules::all()['$id']['last_status'];")" = done ] || fail "schedule last_status"
count=$(docker compose exec -T dst sh -c 'ls /var/www/html/wp-content/pro-backups/*.wpress | wc -l' | tr -d ' ')
[ "$count" = 2 ] || fail "retention kept $count backups (want 2)"
echo "OK schedules + retention"

# --- reset through the web endpoints (as the logged-in admin) ----------------------------------
theme=$(cli option get stylesheet)
jar=$(mktemp); trap 'rm -f $jar' EXIT
curl -s -c "$jar" -b "$jar" -o /dev/null "$U/wp-login.php"
curl -s -c "$jar" -b "$jar" -o /dev/null -d log=admin -d pwd=admin -d wp-submit=1 -d testcookie=1 "$U/wp-login.php"
nonce=$(curl -s -b "$jar" "$U/wp-admin/admin.php?page=pro-backups&tab=reset" | grep -o 'var PBK = {[^;]*' | grep -o '"nonce":"[a-f0-9]*"' | cut -d'"' -f4)
[ -n "$nonce" ] || fail "nonce"
bad=$(curl -s -b "$jar" -X POST "$AJAX" -d action=pbk_reset_start -d nonce="$nonce" -d 'reset[]=media' -d confirm_text=nope)
echo "$bad" | grep -q 'Type RESET' || fail "reset without confirmation accepted"
start=$(curl -s -b "$jar" -X POST "$AJAX" -d action=pbk_reset_start -d nonce="$nonce" -d 'reset[]=media' -d 'reset[]=plugins' -d 'reset[]=themes' -d 'reset[]=database' -d confirm_text=RESET)
jid=$(echo "$start" | json data.id); tok=$(echo "$start" | json data.token)
[ -n "$jid" ] || fail "reset start: $start"
status=running
while [ "$status" = running ]; do
	resp=$(curl -s -X POST "$AJAX" -d action=pbk_run -d id="$jid" -d token="$tok")
	status=$(echo "$resp" | json data.status)
done
[ "$status" = done ] || fail "reset: $(echo "$resp" | json data.message)"
backup=$(echo "$resp" | json data.result.file)
cli option get fx_marker >/dev/null && fail "database not reset"
[ "$(cli plugin list --field=name | tr '\n' ' ')" = "pro-backups " ] || fail "plugins not reset: $(cli plugin list --field=name | tr '\n' ' ')"
[ "$(cli theme list --field=name | tr '\n' ' ')" = "$theme " ] || fail "themes: $(cli theme list --field=name | tr '\n' ' ') (active was $theme)"
[ "$(cli option get stylesheet)" = "$theme" ] || fail "active theme changed"
docker compose exec -T dst sh -c 'test -z "$(ls -A /var/www/html/wp-content/uploads)"' || fail "media not reset"
cli user check-password admin admin || fail "admin password kept"
[ "$(cli option get siteurl)" = "$U" ] || fail "siteurl kept"
[ "$(cli eval "echo count(PBK_Schedules::all());")" = 1 ] || fail "schedules kept"
cli cron event list --hook=pbk_schedule_run --format=count | grep -q '^1$' || fail "schedule event re-registered"
echo "OK reset (safety backup: $backup)"

# --- undo: restore the safety backup -----------------------------------------------------------
cli pro-backups restore "$backup" --yes >/dev/null || fail "undo restore"
[ "$(cli option get fx_marker)" = restored-from-src ] || fail "undo: data"
cli plugin is-installed akismet || fail "undo: plugins"
docker compose exec -T dst test -f /var/www/html/wp-content/uploads/pbk-fixture.txt || fail "undo: media"
echo "OK undo"
cli eval "PBK_Schedules::delete('$id');" >/dev/null
echo "SCHEDULES AND RESET TESTS PASSED"
