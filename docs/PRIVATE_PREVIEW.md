# Owner-only cPanel preview

This additive preview does not activate the final public release. The existing homepage remains in place. The compiled frontend and PHP runtime stay outside the document root; a new `/_fashion-preview/` directory contains only the protected PHP entry and Apache routing.

## Installation

1. Create a separate empty database/user `isp85_fashion_preview` with a unique password. Grant the setup permissions described in DEPLOY_CPANEL.md. Keep the password private.
2. Download the successful CI artifact named `fashion-private-preview` and upload its ZIP into `/home/isp85/fashion-private`.
3. Run the reviewed, commit-pinned `scripts/stage-cpanel-release.sh ZIP PRIVATE_ROOT EXPECTED_SHA256`. The independent SHA256 comes from GitHub's artifact metadata. This verifies the ZIP and pinned verifier before staging into `preview-releases/COMMIT`.
4. Run `php /home/isp85/fashion-private/preview-releases/COMMIT/private/backend/bin/install-preview.php --release /home/isp85/fashion-private/preview-releases/COMMIT --operator-email f.heravy@gmail.com`. Enter only the separate preview database password at its hidden prompt.

The installer requires an empty target and distinct credentials. It creates a private SQL snapshot, private storage copy and public-site archive, restores the file archive in isolation, restores SQL into the preview database, and compares row counts, financial totals and triggers. It never drops tables. On failure, stop and inspect the reported state before retrying; a partially restored target must not be automatically overwritten.

The owner logs in at `https://fashion.azenith.ca/_fashion-preview/` using the primary administrator account. The outer gate rechecks the primary account's role, disabled state and session version. The preview app uses its separate restored database, cookie and private session directory; the gate has a third session directory. Customer testing remains behind the owner gate.

## Email and payments

Preview payments and pricing approvals are disabled. Preview mail is limited to the configured owner email and that owner's Gmail plus aliases; other recipients are blocked. A separate preview mail worker must use `config-preview.php`, the preview release's `notify.php`, and its own lock/log. Keep the existing main worker unchanged. The installer does not add cron jobs or send email.

## Acceptance and rollback

Verify HTTPS login, owner revocation, API requests, private reference attachments, and deep links on the actual host. Confirm the old homepage still works. The successful local restore is evidence for the installer, not a substitute for host acceptance. Private backups remain under `fashion-private/backups` with owner-only permissions; arrange offsite retention independently.

To withdraw the preview, remove only its newly created public entry directory after checking the installation record. Preserve private backups, configuration, release and preview database for review. Do not replace the existing public root or restore SQL into the main database without a separate reviewed recovery procedure.

This preview does not resolve commercial/legal/provider approvals or the open engineering scope in PRODUCTION_READINESS.md.
