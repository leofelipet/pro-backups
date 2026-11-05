#!/usr/bin/env bash
# Boots the src/dst test sites, installs WordPress, AI1WM (free, from wordpress.org) and Pro Backups.
set -euo pipefail
cd "$(dirname "$0")"

docker compose up -d db src dst >/dev/null

for site in src dst; do
	port=$([ "$site" = src ] && echo 8091 || echo 8092)
	for _ in $(seq 1 60); do
		docker compose exec -T "$site" test -f /var/www/html/wp-config.php && break
		sleep 1
	done
	cli() { docker compose run --rm -T "cli-$site" "$@"; }
	cli db create >/dev/null 2>&1 || true
	if ! cli core is-installed >/dev/null 2>&1; then
		cli core install --url="http://localhost:$port" --title="PBK $site" \
			--admin_user=admin --admin_password=admin --admin_email="admin@$site.test" --skip-email >/dev/null
	fi
	cli plugin is-installed all-in-one-wp-migration >/dev/null 2>&1 || cli plugin install all-in-one-wp-migration >/dev/null
	cli plugin activate pro-backups >/dev/null 2>&1 || true
	echo "$site ready: http://localhost:$port (admin/admin)"
done
