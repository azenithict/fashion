# Production hosting and repository audit

Evidence collected 2026-11-06 UTC. Production readiness: **BLOCKED**.

## Verified repository state
- Existing repository `azenithict/fashion`; main `c6f68d95aa1646294c65f4a5927e027d81204905`.
- Existing working branch `feature/mvp-foundation` at `6bfaf9233a4f96abe3dcf6a842eaeb189fdc3c3a`; PR #1 is open and draft.
- Existing Next.js static export, React design controls, original SVG garment previews and validation code are reusable.
- Design Studio holds data in React memory and shares through clipboard/mailto. No API, authentication, database, payments, order fulfillment or partner compensation exists.
- Existing architecture's Supabase assumption is superseded by evidence-based cPanel selection.
- No hosting secrets, runtime credentials or outbound identities are bound to this environment. GitHub access is available. This cloud worker is not the cPanel host.

## Public domain observations
- `https://fashion.azenith.ca/`: HTTPS request succeeded, HTTP 200, static Next.js HTML.
- Requests for `/.git/HEAD` and `/.env`: HTTP 403. This does not prove artifacts are absent or every configuration path is protected.
- `/package.json`, `/AGENTS.md`, `/CLAUDE.md`, `/next.config.ts`, `/fashion.zip`: HTTP 503 in this session; exposure assessment inconclusive. Response bodies were not retained.
- No authenticated document-root inspection, backup, removal, restore, deployment or DNS changes performed.

## Minimum hosting evidence needed
Provide an authorized secure SSH/cPanel connection, or have an authorized operator run:

```sh
bash scripts/audit-cpanel.sh /home/isp85/fashion.azenith.ca
```

Run from a private directory outside the public document root. The script reads runtime availability, PHP CLI extensions, disk capacity and potential source artifact names. It changes no files and prints no environment or credential values. Keep the report private. Test execution on the cloud worker verifies only the collector, not cPanel capabilities.

Supply cPanel observations for web PHP/extensions, Node/Application Manager, database and privileges, Apache rewrite/denial support, cron, authenticated SMTP, limits, SSL, private storage and backup/restore. CLI output cannot establish these by itself.

Before any replacement: authenticated inventory and backup of this domain only; inspect source/archive/config exposure without publishing contents; validate a candidate privately; obtain final release approval. Never delete artifacts without a verified safe backup/replacement.

## Acceptance evidence
| Requirement | Status | Evidence / reason |
|---|---|---|
| Existing repository/branches/PR inspection | PASS | GitHub metadata and local checkout |
| Live homepage HTTPS response | PASS | HTTP 200; no claim of application acceptance |
| Authenticated cPanel inspection | BLOCKED | No connected hosting access |
| Backend selection and implementation | BLOCKED | Actual host must be verified first |
| Document-root inventory / safe backup | BLOCKED | No hosting access |
| Capability collector syntax / local execution | PASS | bash syntax, invalid-path rejection, valid local path run |
| Account/design/quote/payment/order end-to-end | BLOCKED | Backend and commercial/provider setup absent |
| Manufacturing / partner ledger / settlement | BLOCKED | Backend absent; approved contracts and rates unavailable |
| Email delivery / payment webhooks | BLOCKED | SMTP/provider authorization unavailable |
| Production restore / rollback / acceptance | NOT TESTED | No production access or validated replacement |
| Legal/tax/pricing/supplier/partner approvals | BLOCKED | Owner/operator approval records required |

No new recurring costs introduced. Payment processing incurs provider fees if enabled; AI remains optional and may incur usage fees. Neither integration has been activated.

## Dependency and CI hardening

The original dependency audit reported one critical and two high production dependency findings. Next.js and its ESLint config were patched from 15.5.7 to 15.5.29. A lockfile was added and CI now uses `npm ci`. Generated `.next/` and `out/` artifacts are excluded from linting (a repeated-build lint run previously included generated JavaScript).

After the patch, `npm audit --omit=dev` reports zero critical, two high and one moderate findings (Next/PostCSS/sharp). These are unresolved release findings; npm proposes a Next.js major upgrade for some transitive fixes. Static export does not establish server-side exploitability or safety. Do not enable a Node backend until dependency remediation and security validation are complete.
