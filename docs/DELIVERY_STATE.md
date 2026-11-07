# Delivery state — 2026-11-07

Source baseline: remote `feature/private-preview` at `7c163dc1533cb79e14847c2cd7c55b669d02a3fb`. Local history has equivalent source with preserved local commit IDs. Work branch: `feature/launch-experience`.

## Agreed direction
Improve the existing PHP/MariaDB/static Next application. Preserve data, migrations, financial controls, hosting and history. Owner rejected current appearance/usability. Build homepage + complete connected brief/account/save path first; owner approves visual direction before broader redesign.

## Host facts / no repeat discovery
Main database and preview clone exist (37 tables,28 triggers,5 migrations). SMTP test received; main cron executes every5minutes. Preview installed at `/_fashion-preview/`, release `f7ba5a46f17eec40061896b3ace66e760947d0dc`. Initial public entry permission repaired by owner. Same-origin form-policy repair published at7c163dc; execution/login confirmation requested asynchronously. No hosting credentials bound to agent.

## Current work
- Editorial cream/ink visual direction, photography supporting the tailoring brief instead of representing stock for sale.
- Preserve anonymous brief through sign-in using browser session storage, clear it after actual API save; avoid forcing customers to recreate selections.
- Reuse canonical account/API/design handlers; no database migration needed for this slice.

## Pending acceptance / blockers
Owner design acceptance, actual-host browser acceptance, authorized payment provider and commercial/legal/supplier approvals. No final public release/payment activation authorized. Do not label production ready.

## Next
Implement/test the representative flow, publish reviewable preview/artifact, obtain visual decision while independent fixes continue. Update this file with results rather than repeating prior audits.

## Verified this slice
- New homepage +3-step brief, category deep links, contextual account continuation, private API persistence.
- Browser flow passed on isolated local DB: dress/ivory/relaxed → account creation → same named selections restored → real API save → temporary session draft removed. Screenshots captured at1440px and390px; no measured mobile horizontal overflow.
- TypeScript/lint passed;16 unit/client tests passed; preview build generated27 routes.
- Code-only preview activator passed12 assertions including failed-health rollback, preserved homepage/config/routing and refused schema changes.
- GitHub deployment workflow prepared, disabled pending SSH/environment setup. SSH Access availability requested from owner. Never claim actual automated deployment until a successful host run.
- Owner reported origin repair command done; live HTTPS response now returns `Referrer-Policy: same-origin` and owner gate. Authenticated host acceptance remains pending.
