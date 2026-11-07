#!/usr/bin/env bash
# Private extraction only. New ZIP artifacts require their independently reported SHA256.
set -euo pipefail
archive=${1:-}
private=${2:-/home/isp85/fashion-private}
expected_digest=${3:-}
if [[ ! -f "$archive" || "$private" != /* || "$private" == /home/isp85/fashion.azenith.ca* || -L "$private" ]]; then
  echo 'Usage: bash stage-cpanel-release.sh /private/artifact.zip [/private/install] EXPECTED_ZIP_SHA256' >&2;exit 2
fi
umask 077
mkdir -p -- "$private/releases"
chmod 700 -- "$private" "$private/releases"
folder=$(mktemp -d "$private/releases/.stage-XXXXXXXX")
trap 'if [[ -d "$folder" ]]; then rm -rf -- "$folder"; fi; if [[ -f "$folder.tar.gz" ]]; then rm -- "$folder.tar.gz"; fi' EXIT
if [[ "$archive" == *.zip ]]; then
  php -r '$path=$argv[1];$expected=$argv[2];if(!preg_match("/^[a-f0-9]{64}$/D",$expected)||!hash_equals($expected,hash_file("sha256",$path))){fwrite(STDERR,"Artifact checksum mismatch; nothing staged.\n");exit(1);}if(!class_exists("ZipArchive")){fwrite(STDERR,"ZIP extension required.\n");exit(1);}$zip=new ZipArchive;if($zip->open($path)!==true||$zip->numFiles!==1)exit(1);$name=$zip->getNameIndex(0);if(!preg_match("/^zenith-[a-f0-9]{12}(-preview)?\\.tar\\.gz$/D",$name))exit(1);$in=$zip->getStream($name);$out=fopen($argv[3],"x");if(!$in||!$out||stream_copy_to_stream($in,$out)===false)exit(1);fclose($in);fclose($out);$zip->close();echo "GitHub artifact checksum verified.\n";' "$archive" "$expected_digest" "$folder.tar.gz"
  archive="$folder.tar.gz"
elif [[ -n "$expected_digest" ]]; then
  php -r 'if(!preg_match("/^[a-f0-9]{64}$/D",$argv[2])||!hash_equals($argv[2],hash_file("sha256",$argv[1])))exit(1);' "$archive" "$expected_digest"
fi
if tar -tzf "$archive" | awk 'BEGIN {bad=0} /^\// || /(^|\/)\.\.(\/|$)/ {bad=1} END {exit bad}'; then :; else echo 'Unsafe archive names.' >&2;exit 1;fi
if tar -tvzf "$archive" | awk 'substr($0,1,1)!="-" && substr($0,1,1)!="d" {bad=1} END {exit bad}'; then :; else echo 'Archive links or special files rejected.' >&2;exit 1;fi
tar --no-same-owner --no-same-permissions -xzf "$archive" -C "$folder"
# Authenticate the verifier against this pinned helper before executing any package PHP.
verifier_sha='2690ceef7674fe48d594ffde3723d4f23b12d0f7692325a93a9f3d8b54bf2701'
php -r 'if(!is_file($argv[1])||!hash_equals($argv[2],hash_file("sha256",$argv[1]))){fwrite(STDERR,"Untrusted release verifier; nothing staged.\n");exit(1);}' "$folder/private/backend/bin/verify-release.php" "$verifier_sha"
php "$folder/private/backend/bin/verify-release.php" "$folder"
commit=$(php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);echo $m["commit"];' "$folder/release-manifest.json")
variant=$(php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);echo $m["variant"]??"production";' "$folder/release-manifest.json")
namespace='releases'
if [[ "$variant" == preview ]]; then namespace='preview-releases'; fi
mkdir -p -- "$private/$namespace"
chmod 700 -- "$private/$namespace"
if [[ -e "$private/$namespace/$commit" || -L "$private/$namespace/$commit" ]]; then echo 'That release is already staged; existing files retained.' >&2;exit 1;fi
mv -- "$folder" "$private/$namespace/$commit"
printf 'Verified private candidate: %s\nNo public files, secrets or database were changed.\n' "$private/$namespace/$commit"
