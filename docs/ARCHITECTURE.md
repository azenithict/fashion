# Technical architecture — controlled pilot

## Principle
Backend selection is BLOCKED pending evidence from the actual cPanel account. The owner's production directive supersedes the earlier Supabase assumption. No new recurring hosting expense is authorized.

Preserve the existing Next.js frontend and select exactly one backend after inspection:
- Adequate native Node hosting: supported Next.js server with the available relational database.
- Otherwise, verified PHP 8.2+ and MySQL/MariaDB: static Next.js plus same-origin `/api/v1/`, PDO, private storage and secrets outside the document root.

Neither option is verified yet. CLI availability alone is insufficient evidence of web hosting support. Authentication, migrations, jobs, private storage and notifications must match the selected host. No competing backends will be implemented. Stripe requires an authorized account and signed webhooks; manual payment requires business approval and staff-verifiable evidence. See [hosting audit](HOSTING_AUDIT.md).

## Domain ownership
- catalog: categories, permissible options, material options, verified supplier capabilities
- design: customer draft and immutable version snapshots
- measurements: explicit unit, provenance (self/partner), consent, verification
- quoting: deterministic CAD minor-unit calculation and staff-reviewed version
- payments: server-side amount validation, signed webhook, idempotency
- orders: transition-controlled lifecycle, snapshots
- production: factory handoff and QC status
- partners: branches, service catalogue, service completion events, referral attribution
- finance: append-only payable ledger, reversals and settlements
- aftercare: alterations and garment-care inquiries

## Boundaries
- All client data authorization enforced server-side and with DB policies.
- No client-supplied price accepted.
- AI output is typed, rate-limited and requires staff feasibility approval before quoting.
- All photos, measurements, customer data are private and access-controlled.
- The supplier is external to the application; no unsanctioned direct factory API or invented integration.
- Never connect or publish credentials to GitHub.

## First vertical slice
A real (non-checkout) garment design inquiry with validated inputs and clear non-binding status, followed by secure persistence/authentication on the verified hosting architecture; then staff review, quote, payment, and partner settlement. Never advertise an inquiry as a confirmed paid order.

## Delivery gates
Commit independently reviewable changes. CI: install, typecheck, lint, unit tests and build. Tests not executed must be declared NOT RUN. Use feature branch and PR; no automatic merging to main.
