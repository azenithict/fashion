# fashion.azenith.ca — deployment runbook

## Status
The project owner reports that **fashion.azenith.ca** has been created and is ready for use. This is the intended public/canonical domain. **Public DNS, hosting attachment, TLS certificate, and deployed build have not yet been verified.** Do not claim the website is live until all checks pass.

## Recommended deployment target
Vercel for the Next.js application (or another Next.js-compatible host with equivalent features). GitHub repo: `azenithict/fashion`.

## Activation checklist
1. Import the existing `azenithict/fashion` GitHub repository into the hosting account; **do not** create a new repo or overwrite the WordPress site at `azenith.ca`.
2. Build and deploy the reviewed branch to **preview**, with Vercel framework = Next.js, Node 22 and suitable build/install commands. Avoid connecting preview to the public domain.
3. Verify the build, accessible customer-facing content, basic navigation, and local-only enquiry behavior. Until secure persistence exists, the form is **not a real submission**.
4. Approve/merge the reviewed Pull Request to `main` only after CI and a manual preview review.
5. Set production project's domain to `fashion.azenith.ca` in hosting provider settings.
6. In the authoritative `azenith.ca` DNS manager, create or update **only** the record(s) specifically instructed by that hosting provider for `fashion`; do not change the apex, `www`, MX or unrelated records. Record names/targets vary by provider; do not invent DNS targets.
7. Wait for the domain to verify; confirm DNS resolves, a trusted TLS certificate is issued, HTTPS loads without redirects or certificate errors, and the page presents the intended application.
8. Add `NEXT_PUBLIC_SITE_URL=https://fashion.azenith.ca` in the production environment. Keep `NEXT_PUBLIC_PUBLIC_LAUNCH=false` while this is a pilot/demo.
9. Inspect security headers, application logs and the public response; verify there are no exposed secrets or unsupported launch promises.
10. Only after legally approved pricing, policies, functioning inquiry intake, QA, and a launch decision: change `NEXT_PUBLIC_PUBLIC_LAUNCH=true`, redeploy, and verify search crawl permissions.

## Recommended prelaunch test commands
```sh
npm install
npm run typecheck
npm run lint
npm test
npm run build
```
The lockfile should be committed before switching CI to `npm ci`. **Do not state the deployment succeeded just because the GitHub build passed.**

## Manual domain verification
```sh
nslookup fashion.azenith.ca
curl -I https://fashion.azenith.ca
```
Expected: record resolves; HTTPS responds with a valid certificate; content is the correct current build. If it does not, investigate DNS propagation, wrong target, missing domain attachment or TLS issuance.

## Rollback
On hosting incidents, rollback to the last known-good verified deployment. Never force-push or delete unrelated DNS records. No live payments should be enabled until verified checkout, refund terms, and production operations are in place.

## Owner-dependent blockers
- Access to the intended deployment hosting account
- Confirmed hosting destination/project
- DNS control for `azenith.ca`, or completed record configuration
- Approved merge from feature branch after review
