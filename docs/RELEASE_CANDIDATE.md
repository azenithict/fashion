# Release candidate — commercial completion (private preview only)

Status: **not released**. Public release and live payments require the owner's explicit approval.

## Candidate
- Branch `feature/commercial-completion`, base `7c163dc1533c`. Package built locally: `zenith-<commit12>-preview.tar.gz` (`python3 scripts/package-cpanel.py --variant preview`, `NEXT_PUBLIC_BASE_PATH=/_fashion-preview`), verified with `tests/staging.py`. The CI artifact `fashion-private-preview` is the authoritative package once the branch is pushed.
- New migrations (additive, run by the installer): `006_enquiries`, `007_catalog`, `008_enquiry_conversion`, `009_branch_listings`. `notification_outbox.user_id` becomes nullable with a recipient check.

## Verified locally (isolated MariaDB)
Backend: core 46, mail retry 6, preview mail policy 9, commerce unit 28 / integration 27 / concurrency 9, enquiries 45, catalog 46, locations 12, HTTP e2e 42. Frontend: build, typecheck, lint, vitest 16. Text-only browser runs: enquiry → reference → ops inbox; published design → six-step studio → submitted immutable snapshot; ops message → customer "My requests"; `/locations/` empty state; no console errors, no mobile horizontal overflow.

## Staging acceptance (to run on `/_fashion-preview/` after install)
1. Preview login, HTTPS, cookies, CSRF/origin on POST, admin authorization, nested routes, JSON API, private uploads.
2. Guest enquiry → reference → appears in Operations → acknowledgement in outbox; mail worker run (preview restricts recipients to the owner).
3. Admin creates a test catalog entry with an owned photo → verify → publish → `/designs/` → studio submit → review → quote → customer approval (payment stays disabled).
4. Confirm preview and production databases are separate; take a backup before migrations.

## Known limitations / blocked
- No supplier-approved catalog, photography, fabric references, prices, tax/merchant setup, policies or signed partner agreements — the site states these honestly and publishes nothing unverified.
- Stripe/live and manual payment collection remain disabled.
- PHP `gd` not confirmed on host; without it the licensed original image is served instead of a metadata-stripped derivative.
- Catalog detail pages are client-rendered (`/designs/item/?slug=`), so they are not individually indexed; acceptable while indexing is off.
- Hero line "Explore real tailoring designs…" awaits owner confirmation while no designs are published.

## Rollback
Re-activate the previous preview release with the installer; migrations are additive (new tables/columns), so the previous code ignores them. Restore from the pre-install backup only if data repair is required.

## Owner approval checklist
- [ ] Homepage/enquiry design direction
- [ ] Hero copy
- [ ] Staging acceptance results above
- [ ] Commercial inputs (see `COMMERCIAL_APPROVALS.md`)
- [ ] Public release (separate, explicit)
