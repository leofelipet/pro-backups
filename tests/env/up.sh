#!/usr/bin/env bash
# Boots the src/dst test sites, installs WordPress, AI1WM (free, from wordpress.org) and Pro Backups.
set -euo pipefail
cd "$(dirname "$0")"

docker compose up -d db src dst >/dev/null
# Create the databases with the server's own client (wp db create's client can't auth to MySQL 8.4).
for _ in $(seq 1 60); do
	docker compose exec -T db sh -c 'q="CREATE DATABASE IF NOT EXISTS src; CREATE DATABASE IF NOT EXISTS dst;"; (command -v mariadb >/dev/null && mariadb -uroot -proot -e "$q") || mysql -uroot -proot -e "$q"' >/dev/null 2>&1 && break
	sleep 2
done

for site in src dst; do
	port=$([ "$site" = src ] && echo 8091 || echo 8092)
	for _ in $(seq 1 60); do
		docker compose exec -T "$site" test -f /var/www/html/wp-config.php && break
		sleep 1
	done
	cli() { docker compose run --rm -T "cli-$site" "$@"; }
	if ! cli core is-installed >/dev/null 2>&1; then
		cli core install --url="http://localhost:$port" --title="PBK $site" \
			--admin_user=admin --admin_password=admin --admin_email="admin@$site.test" --skip-email >/dev/null
	fi
	cli plugin is-installed all-in-one-wp-migration >/dev/null 2>&1 || cli plugin install all-in-one-wp-migration >/dev/null
	cli plugin activate pro-backups >/dev/null 2>&1 || true
	echo "$site ready: http://localhost:$port (admin/admin)"
done
