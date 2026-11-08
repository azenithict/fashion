# Execution state

_Concise continuity record. No secrets or customer data._

## Baseline
- Base: `feature/private-preview` @ `7c163dc1533cb79e14847c2cd7c55b669d02a3fb`.
- Working branch: `feature/commercial-completion` (pushed; CI green).
- Merged `feature/launch-experience` (PR #6, `de60c666`) for its GitHub→cPanel code-only preview deployment; this branch keeps its own versions of overlapping pages.
- Private preview: **activated `1d16f20d5d38` on 2026-11-08** after owner-run `migrate-preview.php` (private backup `backups/preview-before-migration-20261108T070345Z-c8bfc855.sql`, migrations 006–009 applied to the preview DB only, owner gate health check passed). Rollback entry: `preview-code-backups/20261108070345-dfee71d8/index.php`. Later pushes deploy code-only automatically.
- Production public site: untouched.

## Completed and verified (local, isolated MariaDB 10.11-compatible test DB)
- Phase 1 slice: guest enquiry → MariaDB → reference `AZF-XXXXXX` → operations inbox → acknowledgement in durable outbox.
  - Backend: migration `006_enquiries.sql`, module `Enquiries.php`, guest outbox recipient (`recipient_email`), event-specific mail bodies, worker `LEFT JOIN`.
  - Contract: `enquiryServices`, `enquiryStatuses`, `enquiryTransitions`, `enquiryContactMethods` in `shared/contract.json`; API in `docs/API_CONTRACT.md`.
  - Frontend: rebuilt homepage (typographic, no imagery), mobile nav, `/enquiry/` real form (draft autosave, inline validation, honest success), ops tab `/workspace/?tab=enquiries`; fonts self-hosted (no Google Fonts request).
- Evidence: `enquiries-integration.php` 33 checks; core 46; mail retry 6; commerce 27; preview mail policy 9; HTTP smoke (CSRF/origin/401); Playwright: desktop+mobile no horizontal overflow, no console errors, form → reference → visible in ops inbox; build/typecheck/lint/vitest 13 pass.

- Phase 2: migration `007_catalog.sql`, `Catalog.php` (verified products/materials/options, server-enforced publication rules, licensed private media with GD derivatives), catalog-aware designs (`Designs.php`: validated selections, `availability_issues`, immutable submission snapshot). UI: ops tab `/workspace/?tab=catalog`, public `/designs/` and `/designs/item/?slug=`, six-step `/studio/` (catalog or labelled concept path, local draft recovery, measurement selection, review/submit), shared spec rendering in workspace.
  - Evidence: `catalog-integration.php` 46 checks; HTTP multipart upload/serve smoke; text-only browser run: published design → studio 6 steps → submitted design whose version snapshot holds fabric name and option label; vitest 16 pass.

- Phase 3: migration `008_enquiry_conversion.sql`; enquiry customer messaging (outbox email), customer `/my/enquiries` + replies, conversion by linking the customer's own verified-email design; `Operations.php` overview + funnel. UI: ops Overview tab (default), enquiry message/convert, customer "My requests" tab, honest no-online-payment notice on orders.
  - Evidence: `enquiries-integration.php` 45 checks; text-only browser run: ops message → appears in customer's My requests, internal notes not shown; overview + funnel render. Existing quote → approval → order → production/QC/shipping/delivery remains covered by `commerce_integration.php` (27).

- Phase 4: migration `009_branch_listings.sql`, `Locations.php` (admin-verified listings; services re-checked against latest approved rate card on every read), follow-up counts in overview. UI: `/locations/` (honest empty state, no map), admin per-branch listing editor. Existing partner portal (tasks, evidence, earnings, settlements, branch isolation, referral attribution) verified by HTTP e2e (42 checks).
  - Evidence: `locations-integration.php` 12 checks; text-only browser check of `/locations/` empty state.

## Decisions
- Lead states: new → awaiting_details/reviewing → quotation_ready → converted | closed (close requires reason; closed may reopen to reviewing).
- Abuse controls: 5 submissions/hour/IP, 3/day/email, honeypot; rejected submissions are never stored or shown success.
- "Explore Designs" points to the homepage category section until a verified catalog exists.

## Blockers / owner inputs
- Commercial approvals unchanged — see `COMMERCIAL_APPROVALS.md`. No photography, catalog, prices, partners or Stripe supplied.
- Host: enable PHP `gd` extension (metadata-stripping image derivatives).
- Hero copy "Explore real tailoring designs…" needs owner confirmation while no verified designs are published.

## Next task
1. Owner design-direction approval of homepage + enquiry flow (still pending).
2. Owner browser acceptance on `/_fashion-preview/` (checklist in `RELEASE_CANDIDATE.md`), then commercial inputs package.

## Rollback
- Migration 006 is additive (new tables, nullable outbox column). Code rollback = redeploy previous release; preview installer keeps private backups.
