#!/usr/bin/env bash
# Extract and verify in PRIVATE storage. Does not change public files or database.
set -euo pipefail
archive=${1:-}
private=${2:-/home/isp85/fashion-private}
if [[ ! -f "$archive" || "$private" != /* || "$private" == /home/isp85/fashion.azenith.ca* ]]; then
  echo 'Usage: bash stage-cpanel-release.sh /private/release.tar.gz [/private/install]' >&2;exit 2
fi
umask 077
mkdir -p -- "$private/releases"
chmod 700 -- "$private" "$private/releases"
folder=$(mktemp -d "$private/releases/.stage-XXXXXXXX")
# GNU tar rejects escaping .. names; additionally refuse absolute names and links.
if tar -tzf "$archive" | awk 'BEGIN {bad=0} /^\// || /(^|\/)\.\.(\/|$)/ {bad=1} END {exit bad}'; then :; else echo 'Unsafe archive names.' >&2;exit 1;fi
if tar -tvzf "$archive" | awk 'substr($0,1,1)!="-" && substr($0,1,1)!="d" {bad=1} END {exit bad}'; then :; else echo 'Archive links or special files rejected.' >&2;exit 1;fi
tar --no-same-owner --no-same-permissions -xzf "$archive" -C "$folder"
php "$folder/private/backend/bin/verify-release.php" "$folder"
commit=$(php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);echo $m["commit"];' "$folder/release-manifest.json")
if [[ -e "$private/releases/$commit" ]]; then echo 'That release is already staged; existing files retained.' >&2;exit 1;fi
mv -- "$folder" "$private/releases/$commit"
printf 'Verified private candidate: %s\nNo public files, secrets or database were changed.\n' "$private/releases/$commit"
