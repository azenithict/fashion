#!/usr/bin/env bash
# Specific, reversible containment for the archive found by the owner's audit.
set -euo pipefail
source_file='/home/isp85/fashion.azenith.ca/fashion-cpanel-site (1).zip'
private='/home/isp85/fashion-private/quarantine'
if [[ ! -f "$source_file" || -L "$source_file" ]]; then
  echo 'Expected regular archive not found; nothing changed.'; exit 1
fi
umask 077
mkdir -p -- "$private"
chmod 700 -- "$private"
target="$private/fashion-cpanel-site-$(date -u +%Y%m%dT%H%M%SZ)-$$.zip"
if [[ -e "$target" ]]; then echo 'Destination exists; nothing changed.'; exit 1; fi
mv -n -- "$source_file" "$target"
if [[ -f "$source_file" || ! -f "$target" ]]; then echo 'Quarantine could not be verified.'; exit 1; fi
chmod 600 -- "$target"
printf 'Archive preserved privately: %s\nNo live application files were changed.\n' "$target"
