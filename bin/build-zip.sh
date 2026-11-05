#!/usr/bin/env bash
# Builds dist/pro-backups-<version>.zip (plugin folder "pro-backups/", without dev files listed in .distignore).
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(grep -m1 -oP '^\s*\*\s*Version:\s*\K\S+' pro-backups.php)
out=$PWD/dist; stage=$(mktemp -d)
mkdir -p "$out" "$stage/pro-backups"
rsync -a --exclude-from=<(sed 's#^/##' .distignore) --exclude=dist ./ "$stage/pro-backups/"
(cd "$stage" && python3 -m zipfile -c "$out/pro-backups-$version.zip" pro-backups)
rm -r "$stage"
echo "$out/pro-backups-$version.zip"
