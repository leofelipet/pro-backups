#!/usr/bin/env bash
# Runs the integration suite against PHP / database combinations (fresh containers each time).
# Usage: tests/matrix.sh [combo ...]   combos: php74 php83 php84 mysql84
# Each combo builds its own fixtures with AI1WM (tests/fixtures/matrix-<combo>, git-ignored).
set -euo pipefail
cd "$(dirname "$0")"
combos=("${@:-php74 php84 mysql84}")
[ $# -gt 0 ] && combos=("$@")
for combo in "${combos[@]}"; do
	case $combo in
		php74)   php=7.4; db=mariadb:11 ;;
		php83)   php=8.3; db=mariadb:11 ;;
		php84)   php=8.4; db=mariadb:11 ;;
		mysql84) php=8.3; db=mysql:8.4 ;;
		*) echo "unknown combo $combo"; exit 1 ;;
	esac
	echo "################ $combo: PHP $php, $db"
	export WP_IMAGE=wordpress:php$php-apache CLI_IMAGE=wordpress:cli-php$php CLI_TAG=php$php DB_IMAGE=$db
	(cd env && docker compose down -v >/dev/null 2>&1 || true)
	(cd env && docker compose --profile cli build -q cli-src >/dev/null && ./up.sh)
	(cd env && docker compose exec -T src php -v | head -1 && docker compose run --rm -T -e HOME=/tmp cli-src eval-file wp-content/plugins/pro-backups/tests/env/seed.php 2>&1 | tail -1)
	export FIXTURES=$PWD/fixtures/matrix-$combo # same WordPress version as the sites under test
	(cd env && ./make-fixtures.sh >/dev/null 2>&1)
	for t in restore cli-and-files export schedules-reset; do
		timeout 1800 integration/$t.sh 2>&1 | tail -1 || true
	done
done
