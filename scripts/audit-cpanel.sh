#!/usr/bin/env bash
# Run over an authorized cPanel SSH session. No writes or secret values.
set -euo pipefail
root=${1:-}
if [[ -z "$root" || ! -d "$root" || "$root" != /* ]]; then
  echo 'Usage: bash scripts/audit-cpanel.sh /absolute/existing/document-root' >&2
  exit 2
fi
printf 'A Zenith Fashion — read-only hosting evidence\n'
printf 'Collected UTC: '; date -u +%FT%TZ
printf 'Document root: %s\n' "$root"
printf '\nRuntime availability (CLI only; verify web runtime in cPanel)\n'
for executable in php node npm mysql mariadb crontab sendmail; do
  if command -v "$executable" >/dev/null 2>&1; then
    printf '%s: available\n' "$executable"
  else
    printf '%s: unavailable in PATH\n' "$executable"
  fi
done
if command -v php >/dev/null 2>&1; then
  php -r 'echo "PHP CLI: ", PHP_VERSION, PHP_EOL; foreach (["pdo_mysql","openssl","mbstring","fileinfo","session","curl","intl","zip"] as $e) { echo $e, ": ", extension_loaded($e) ? "available" : "missing", PHP_EOL; } echo "memory_limit: ", ini_get("memory_limit"), PHP_EOL; echo "upload_max_filesize: ", ini_get("upload_max_filesize"), PHP_EOL; echo "post_max_size: ", ini_get("post_max_size"), PHP_EOL;'
fi
if command -v node >/dev/null 2>&1; then node --version; fi
printf '\nDisk capacity\n'
df -Pk "$root"
printf '\nPotential public source/configuration artifacts (names only; do not publish report)\n'
find "$root" -maxdepth 2 \( -name .git -o -name '.env*' -o -name '*.zip' -o -name '*.tar*' -o -name '*.sql' -o -name '*.bak' -o -name AGENTS.md -o -name CLAUDE.md -o -name package.json -o -name composer.json -o -name next.config.ts \) -print
printf '\nManual verification still required\n'
printf '%s\n' \
  'cPanel: web PHP version/extensions; MySQL database/user grants and transactional engine.' \
  'cPanel: Node Application Manager/Passenger support, supported versions, restart and limits.' \
  'Apache: AllowOverride/rewrite and private-path denial behavior; TLS/AutoSSL.' \
  'Cron: allowed cadence/process limits. SMTP: authenticated account/port/TLS and delivery test.' \
  'Backups: scope, retention, offsite availability and isolated restore test.' \
  'Storage: account quota, private directory outside document root, process/CPU/memory limits.' \
  'Do not upload this script or report into the public document root.'
