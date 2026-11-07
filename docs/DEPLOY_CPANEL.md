# cPanel PHP/MariaDB installation and release runbook

Canonical domain: https://fashion.azenith.ca. Public root: `/home/isp85/fashion.azenith.ca`. Private root: `/home/isp85/fashion-private`. Do not change unrelated sites, DNS or email configuration.

## Release package
CI builds static Next.js and packages `fashion-cpanel-release`, a tar.gz containing `public/`, `private/`, docs and a SHA-256 manifest recording the source commit. This replaces the obsolete static-only `fashion-cpanel-site` artifact. Source/config/migrations are never placed in public.

Locally: `npm ci`, required checks, `npm run build`, commit reviewed source, then `python3 scripts/package-cpanel.py`. `--allow-dirty` is test-only; release verification rejects dirty packages.

## Host prerequisites / owner actions
1. The domain PHP version is8.3. Verify web PDO MySQL/session/fileinfo/curl/mbstring and Apache rewrite/AllowOverride. Confirm account CPU/process/storage quotas, cron cadence and private home access. Terminal's reported filesystem free space is not the cPanel account quota.
2. In cPanel Database Wizard, create a dedicated database and user (prefix `isp85_`), grant privileges only on that database. Keep credentials private; never post them in chat or commit them. Migrations require CREATE/ALTER/INDEX/REFERENCES/TRIGGER plus data privileges; production runtime should use a separate least-privilege user with required SELECT/INSERT/UPDATE/DELETE only. The immutable triggers remain enforced.
3. Obtain an authenticated SMTP account and approved sender, or explicitly approve cPanel sendmail and confirm delivery. No email is assumed delivered merely because it was queued.
4. Obtain business approvals in `COMMERCIAL_APPROVALS.md`. Do not enable payment or public launch without them.

## Audit, backup and staging (no public replacement)
- Preserve and inventory current deployment. `scripts/audit-cpanel.sh` reads capabilities and potential artifacts, not secret values.
- The owner's audit found `fashion-cpanel-site (1).zip` in the public root. `scripts/quarantine-public-archives.sh` moves only that known archive into private quarantine; no deletion or site overwrite.
- Upload the release archive outside the document root and run `bash scripts/stage-cpanel-release.sh /private/release.tar.gz`. It extracts/validates under private `releases/COMMIT`, rejecting dirty builds, links/special files, unexpected executables and checksum mismatches. It does not activate code or change the database.
- Inspect manifest/version, keep original archive privately, and retain the prior verified release.

## Configure a candidate privately
The extracted release has `public/` and `private/`. Copy `private/backend/config.example.php` to `/home/isp85/fashion-private/config.php`, chmod600; create private `storage/uploads`, `sessions` and `backups` directories chmod700. Fill DB/SMTP privately. No production secrets are in the package.

Point `/home/isp85/fashion-private/current` at `releases/COMMIT/private` only under the release lock and after migration compatibility checks. Before an existing installation is changed, back up it and its database. Run migrations explicitly:

```sh
php /home/isp85/fashion-private/releases/COMMIT/private/backend/bin/migrate.php --config /home/isp85/fashion-private/config.php
```

Migrations use advisory locking and checksums. MySQL DDL is not transactional: a failed migration can leave partial schema; do not blindly retry or delete production data. Inspect and restore/recover with an authorized operator.

Bootstrap the first admin using the CLI tool's password-on-stdin procedure, never a public setup page or committed password:

```sh
php /home/isp85/fashion-private/current/backend/bin/bootstrap-admin.php --config /home/isp85/fashion-private/config.php --email OPERATOR_EMAIL --name 'Operator name'
```

The tool reads a12–72-byte password from stdin; use the documented hidden prompt procedure, not a literal secret in shell history. No preseeded admin account exists.

## Notifications
Run the private outbox worker using the cadence actually permitted by cPanel, e.g. once per minute if approved:

```sh
php /home/isp85/fashion-private/current/backend/bin/notify.php --config /home/isp85/fashion-private/config.php
```

Failed deliveries have attempts/next_attempt_at/last_error; exhausted items can be explicitly retried with `--retry-failed`. Confirm real verification/recovery and order notifications arrive and verify SPF/DKIM/DMARC with the mail operator. Do not change unrelated email records automatically.

## Payments
Manual collection is disabled until both `manual_payments_enabled=true` and an approved reference are configured. Staff must record actual bank/receipt evidence and a unique reference; pending payment is distinct from confirmed funds. No card data enters this application.

For Stripe, enter authorized secret/webhook keys privately and configure an HTTPS endpoint `/api/v1/stripe/webhook` for Checkout completion/async success. Checkout is card-only; verification requires HMAC/timestamp and canonical Stripe retrieval. Test real provider events, duplicate delivery, deposit/balance, cancelled links and refunds using authorized test credentials before live activation. Provider fees apply. Never use the redirect as payment evidence.

## Final public release — approval required
No activation was performed by the agent. Before replacing public files: pass host/provider/email/backup acceptance, obtain legal/commercial and final release approval, create a private backup of the old public root and data, and record the previous release. Validate the candidate's API, routing and all customer-critical flows on the actual host. Coordinate the public tree and private `current` version under the exclusive `/home/isp85/fashion-private/application.lock`; avoid serving mixed versions. Copy only the package's `public/` contents into the exact domain root. Never extract the full package there. Preserve `.well-known` certificate validation as required by the host.

Verify HTTPS, `/api/v1/health`, registration/verification/recovery, private files, customer/partner authorization, quoting/payment and notifications. Record actual production commit and evidence. Keep `NEXT_PUBLIC_PUBLIC_LAUNCH=false` until launch authorization.

## Backups / recovery / rollback
`backend/bin/backup.sh /home/isp85/fashion-private` acquires the exclusive application lock and creates a mode600 private tar of config, uploads and consistent logical DB snapshot. Secure off-host copies are still an operator responsibility; do not upload backups into public. Do not migrate or activate releases during backup.

Local verification restored a logical snapshot into an independent MariaDB10.11 database with37 tables and28 triggers. Production restoration and full file/SMTP/provider acceptance are NOT TESTED.

Recovery: stop mutations under the lock; preserve failed state; verify backup checksum; restore into an isolated database and private storage first; verify row counts, financial totals, triggers and file hashes; then authorize production switch to the matching release/config/database. A code-only rollback is safe only when schema/data remain compatible. Never downgrade or drop production data automatically. Retain prior public files and private release for a coordinated rollback. cPanel JetBackup service availability does not prove this account's restore procedure.

## Guided private credential setup
`backend/bin/configure-cpanel.php --release /home/isp85/fashion-private/releases/COMMIT` verifies the staged template and prompts for the database password with terminal echo disabled. It writes only a new mode600 private config and mode700 private directories after verifying the dedicated database is empty. It never overwrites existing configuration, migrates a database, activates mail/payments or changes public files. Passwords are read from the terminal rather than shell arguments/history; `var_export` handles punctuation safely. For automated isolated tests only, `--password-stdin` explicitly allows noninteractive input. Host/port overrides support test fixtures; production defaults remain localhost:3306. The helper can be downloaded separately at its reviewed pinned commit when working with an earlier already-verified package.
