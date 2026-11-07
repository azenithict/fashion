# Execution state

_Concise continuity record. No secrets or customer data._

## Baseline
- Base: `feature/private-preview` @ `7c163dc1533cb79e14847c2cd7c55b669d02a3fb`.
- Working branch: `feature/commercial-completion` (local; not yet pushed — GitHub write access not linked to the agent session).
- Deployed revisions (production and `/_fashion-preview/`): **unverified** — the agent environment cannot reach `fashion.azenith.ca`. Preview login/origin repair status on the host remains unconfirmed.

## Completed and verified (local, isolated MariaDB 10.11-compatible test DB)
- Phase 1 slice: guest enquiry → MariaDB → reference `AZF-XXXXXX` → operations inbox → acknowledgement in durable outbox.
  - Backend: migration `006_enquiries.sql`, module `Enquiries.php`, guest outbox recipient (`recipient_email`), event-specific mail bodies, worker `LEFT JOIN`.
  - Contract: `enquiryServices`, `enquiryStatuses`, `enquiryTransitions`, `enquiryContactMethods` in `shared/contract.json`; API in `docs/API_CONTRACT.md`.
  - Frontend: rebuilt homepage (typographic, no imagery), mobile nav, `/enquiry/` real form (draft autosave, inline validation, honest success), ops tab `/workspace/?tab=enquiries`; fonts self-hosted (no Google Fonts request).
- Evidence: `enquiries-integration.php` 33 checks; core 46; mail retry 6; commerce 27; preview mail policy 9; HTTP smoke (CSRF/origin/401); Playwright: desktop+mobile no horizontal overflow, no console errors, form → reference → visible in ops inbox; build/typecheck/lint/vitest 13 pass.

- Phase 2: migration `007_catalog.sql`, `Catalog.php` (verified products/materials/options, server-enforced publication rules, licensed private media with GD derivatives), catalog-aware designs (`Designs.php`: validated selections, `availability_issues`, immutable submission snapshot). UI: ops tab `/workspace/?tab=catalog`, public `/designs/` and `/designs/item/?slug=`, six-step `/studio/` (catalog or labelled concept path, local draft recovery, measurement selection, review/submit), shared spec rendering in workspace.
  - Evidence: `catalog-integration.php` 46 checks; HTTP multipart upload/serve smoke; text-only browser run: published design → studio 6 steps → submitted design whose version snapshot holds fabric name and option label; vitest 16 pass.

## Decisions
- Lead states: new → awaiting_details/reviewing → quotation_ready → converted | closed (close requires reason; closed may reopen to reviewing).
- Abuse controls: 5 submissions/hour/IP, 3/day/email, honeypot; rejected submissions are never stored or shown success.
- "Explore Designs" points to the homepage category section until a verified catalog exists.

## Blockers / owner inputs
- Link GitHub to the agent session (push branch → CI artifact `fashion-private-preview`).
- Host evidence for preview: current deployed commit and preview login result.
- Commercial approvals unchanged — see `COMMERCIAL_APPROVALS.md`. No photography, catalog, prices, partners or Stripe supplied.
- Host: enable PHP `gd` extension (metadata-stripping image derivatives).
- Hero copy "Explore real tailoring designs…" needs owner confirmation while no verified designs are published.

## Next task
1. Owner design-direction approval of homepage + enquiry flow (still pending).
2. Phase 3: convert an enquiry into a reviewed specification; customer-visible quotation view/approval polish; request-missing-information message to customer; order milestone timeline from persisted events.

## Rollback
- Migration 006 is additive (new tables, nullable outbox column). Code rollback = redeploy previous release; preview installer keeps private backups.
