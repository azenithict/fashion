#!/usr/bin/env bash
# Run from the private installation; config contains private database selectors.
set -euo pipefail
private=${1:-/home/isp85/fashion-private}
if [[ "$private" != /* || ! -f "$private/config.php" || ! -d "$private/current/backend" ]]; then
  echo 'Usage: bash backup.sh /absolute/private-installation' >&2; exit 2
fi
umask 077
mkdir -p -- "$private/backups"
chmod 700 -- "$private/backups"
exec 9>"$private/application.lock"
flock -x 9
stamp=$(date -u +%Y%m%dT%H%M%SZ)-$$
folder=$(mktemp -d "$private/backups/.snapshot-XXXXXX")
trap 'rm -f -- "$folder/database.sql"; rmdir -- "$folder" 2>/dev/null || true' EXIT
php "$private/current/backend/bin/export-db.php" --config "$private/config.php" --output "$folder/database.sql"
tar -czf "$private/backups/fashion-$stamp.tar.gz" -C "$private" config.php storage -C "$folder" database.sql
chmod 600 -- "$private/backups/fashion-$stamp.tar.gz"
sha256sum -- "$private/backups/fashion-$stamp.tar.gz" > "$private/backups/fashion-$stamp.tar.gz.sha256"
printf 'Private backup: %s\nContains sensitive customer data and configuration; store securely.\n' "$private/backups/fashion-$stamp.tar.gz"
