#!/usr/bin/env bash
# >2 GB backup (made by the real AI1WM) restored over HTTP, in request-sized slices.
set -euo pipefail
cd "$(dirname "$0")/../env"
AJAX=http://localhost:8092/wp-admin/admin-ajax.php
F=${FIXTURES:-../fixtures}/large/full-big.wpress
fail() { echo "FAIL: $*"; exit 1; }
json() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d; foreach(explode(".",$argv[1]) as $k){$v=$v[$k]??null;} echo is_scalar($v)?$v:json_encode($v);' "$1"; }
[ -f "$F" ] || BIG=1 ./make-fixtures.sh >/dev/null 2>&1
size=$(stat -c %s "$F"); [ "$size" -gt 2147483648 ] || fail "fixture is only $size bytes"
docker compose run --rm -T -e HOME=/tmp cli-dst plugin activate pro-backups >/dev/null 2>&1
docker compose exec -T dst mkdir -p /var/www/html/wp-content/pro-backups
docker compose cp "$F" dst:/var/www/html/wp-content/pro-backups/full-big.wpress >/dev/null 2>&1
docker compose exec -T -u root dst chown -R www-data: /var/www/html/wp-content/pro-backups
start=$(docker compose run --rm -T -e HOME=/tmp cli-dst eval "\$j=PBK_Importer::start(PBK_Storage::find('full-big.wpress'), array('confirmed'=>true)); echo json_encode(array('id'=>\$j->id,'token'=>\$j->data['token']));" 2>/dev/null)
id=$(echo "$start" | json id); tok=$(echo "$start" | json token)
n=0; status=running; t0=$(date +%s)
while [ "$status" = running ] && [ $n -lt 1000 ]; do
	n=$((n+1)); resp=$(curl -s -m 120 -X POST "$AJAX" -d action=pbk_run -d id="$id" -d token="$tok"); status=$(echo "$resp" | json data.status)
done
[ "$status" = done ] || fail "$(echo "$resp" | json data.message)"
got=$(docker compose exec -T dst stat -c %s /var/www/html/wp-content/uploads/pbk-big.bin)
[ "$got" = 2200000000 ] || fail "big file size $got"
echo "restored $(numfmt --to=iec "$size") in $n requests, $(( $(date +%s) - t0 ))s"
docker compose exec -T dst rm -f /var/www/html/wp-content/uploads/pbk-big.bin /var/www/html/wp-content/pro-backups/full-big.wpress
echo "BIG RESTORE PASSED"
