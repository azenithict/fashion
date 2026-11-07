# A Zenith Fashion architecture

## Selected deployment
**One static Next.js frontend and one PHP 8.3/PDO MySQL backend on existing cPanel.** MariaDB is the sole persistent authority; `/api/v1/` is same-origin. No Node server, Supabase, Vercel subscription or second backend is required.

Owner-supplied host evidence: PHP CLI8.3.36 with PDO MySQL, OpenSSL, mbstring, fileinfo, sessions, curl, intl and zip; MariaDB10.11; Apache2.4; domain PHP Selector8.3; cron and sendmail available. Node/npm absent from PATH. This is sufficient to implement the PHP option without depending on unverified Node Application Manager support. Actual DB grants, authenticated SMTP, account quotas, Apache rules and production backup/restore still need host acceptance.

## Public/private boundary
Only Next.js `out/` and `backend/public/index.php`/`.htaccess` are public. PHP modules, migrations, contract, credentials, sessions, uploads and backups live outside `/home/isp85/fashion.azenith.ca` under `/home/isp85/fashion-private`.

The private `current` symlink points at a reviewed release's `private/` directory. Public API defaults to `/home/isp85/fashion-private/current/backend`; `ZENITH_BACKEND` and `ZENITH_CONFIG` may override for tests. Production refuses HTTP and publicly located backend/config/storage/session paths. Cookie sessions are Secure, HttpOnly, SameSite=Lax with idle/absolute expiry, session rotation and version revocation.

## Authoritative boundaries
- Shared enums/measurement fields: `shared/contract.json`; API operations: `docs/API_CONTRACT.md`.
- Core: Kernel/Auth/Designs; migrations001/004. Private reference files, immutable submitted design versions, explicit measurement consent and independent verification.
- Commerce: integer CAD quotes, immutable customer-approved order/invoice snapshots, verified payments, controlled fulfillment/QC, actual recorded expenses and reports; migration002.
- Partners: approved contracts and versioned rate cards, branch-scoped tasks, append-only earnings/reversals, settlement membership uniqueness; migration003.
- Identity/permissions/pricing/payment verification enforced in PHP, never trusted from React.
- Financial mutations lock aggregate rows in transactions. Quote approval uses current locking reads, including concurrent retries; payment references and earning/settlement events have unique constraints.
- Notification outbox is durable and retryable; SMTP uses TLS/authentication, or explicitly approved local sendmail. Token links use URL fragments and are never included in in-app notifications.
- AI and scanning are not simulated. The core manual workflow has no AI dependency.

## Costs and approvals
No new recurring infrastructure subscription is introduced. Real payment processors charge fees. Optional AI incurs provider usage charges if later enabled. Business pricing/margin rules and manual payment verification default disabled until documented owner approval. Stripe requires authorized credentials; redirects never prove payment. Supplier commitments, partner contracts, legal/tax policies and final release require recorded approval.

See `PRODUCTION_READINESS.md` for executed evidence and limits. Passing local tests is not production acceptance.
