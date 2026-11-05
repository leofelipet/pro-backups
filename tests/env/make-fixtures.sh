#!/usr/bin/env bash
# Builds .wpress fixtures with the real AI1WM plugin on the src test site.
#   small fixtures (committed)  -> tests/fixtures/ai1wm-*.wpress
#   full-site fixtures (ignored) -> tests/fixtures/large/*.wpress
# Optional: BIG=1 also builds a >2 GB archive (sparse media file) in large/.
set -euo pipefail
cd "$(dirname "$0")"
cli() { docker compose run --rm -T -e HOME=/tmp cli-src "$@" 2>&1 | grep -v 'Container' || true; }
E=wp-content/plugins/pro-backups/tests/env/ai1wm-export.php
cli plugin activate all-in-one-wp-migration >/dev/null
docker compose run --rm -T -e HOME=/tmp cli-src option get fx_marker >/dev/null 2>&1 || cli eval-file wp-content/plugins/pro-backups/tests/env/seed.php

cli eval-file $E ai1wm-plain.wpress none - small
cli eval-file $E ai1wm-gzip.wpress gzip - small
cli eval-file $E ai1wm-bzip2.wpress bzip2 - small
cli eval-file $E ai1wm-encrypted.wpress none secret123 small
cli eval-file $E ai1wm-encrypted-gzip.wpress gzip secret123 small
cli eval-file $E full-plain.wpress none
cli eval-file $E full-encrypted-bzip2.wpress bzip2 secret123
cli eval-file $E full-encrypted-gzip.wpress gzip secret123

if [ "${BIG:-0}" = 1 ]; then
	docker compose exec -T src sh -c 'head -c 2200000000 /dev/urandom > /var/www/html/wp-content/uploads/pbk-big.bin'
	cli eval-file $E full-big.wpress none
	docker compose exec -T src rm -f /var/www/html/wp-content/uploads/pbk-big.bin
fi

mkdir -p ../fixtures/large
B=/var/www/html/wp-content/ai1wm-backups
for f in ai1wm-plain ai1wm-gzip ai1wm-bzip2 ai1wm-encrypted ai1wm-encrypted-gzip; do
	docker compose cp "src:$B/$f.wpress" "../fixtures/$f.wpress" >/dev/null
done
for f in $(docker compose exec -T src sh -c "ls $B | grep '^full-'"); do
	docker compose cp "src:$B/$f" "../fixtures/large/$f" >/dev/null
done
ls -la ../fixtures ../fixtures/large
