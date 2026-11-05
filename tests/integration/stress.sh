#!/usr/bin/env bash
# Resumability under a tiny request budget (0.1 s): restore and backup driven over HTTP need many requests.
set -euo pipefail
cd "$(dirname "$0")/../env"
AJAX=http://localhost:8091/wp-admin/admin-ajax.php
fail() { echo "FAIL: $*"; exit 1; }
json() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d; foreach(explode(".",$argv[1]) as $k){$v=$v[$k]??null;} echo is_scalar($v)?$v:json_encode($v);' "$1"; }
mu='<?php add_filter("pbk_request_budget", function () { return 0.1; });'
for site in src dst; do
	docker compose exec -T -u root "$site" sh -c "mkdir -p /var/www/html/wp-content/mu-plugins && printf '%s' '$mu' > /var/www/html/wp-content/mu-plugins/pbk-budget.php && chown -R www-data: /var/www/html/wp-content/mu-plugins"
done
trap 'for s in src dst; do docker compose exec -T -u root $s rm -f /var/www/html/wp-content/mu-plugins/pbk-budget.php; done' EXIT

# Backup over HTTP on src with 0.1 s budget, encrypted + gzip.
docker compose run --rm -T -e HOME=/tmp cli-src plugin activate pro-backups >/dev/null 2>&1
start=$(docker compose run --rm -T -e HOME=/tmp cli-src eval "\$j=PBK_Exporter::start(array('name'=>'stress.wpress','password'=>'pw','compression'=>'gzip','exclude'=>array('cache'))); echo json_encode(array('id'=>\$j->id,'token'=>\$j->data['token']));" 2>/dev/null)
id=$(echo "$start" | json id); tok=$(echo "$start" | json token)
n=0; status=running
while [ "$status" = running ] && [ $n -lt 2000 ]; do
	n=$((n+1)); resp=$(curl -s -X POST "$AJAX" -d action=pbk_run -d id="$id" -d token="$tok"); status=$(echo "$resp" | json data.status)
done
[ "$status" = done ] || fail "stress backup: $(echo "$resp" | json data.message)"
echo "backup took $n requests"
[ "$n" -gt 5 ] || fail "budget not applied ($n requests)"
docker compose exec -T src rm -f /var/www/html/wp-content/pro-backups/stress.wpress >/dev/null 2>&1 || true

# Restore over HTTP on dst with 0.1 s budget (restore.sh drives everything through pbk_run).
out=$(timeout 1500 ../integration/restore.sh 2>&1 | tail -1 || true)
echo "$out"
[ "$out" = "ALL RESTORE TESTS PASSED" ] || fail "stress restore"
echo "STRESS TESTS PASSED"
