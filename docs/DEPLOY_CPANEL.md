# Publish A Zenith Fashion on existing cPanel hosting — no extra hosting bill

## Source and release
Source: https://github.com/azenithict/fashion
Build branch (until approved merge): `feature/mvp-foundation`
The GitHub Actions pipeline tests and exports a fully static Next.js build, then provides an artifact named `fashion-cpanel-site` (ZIP).

## User steps
1. In GitHub, open the Actions page. Select the newest successful `Build and test` run for `feature/mvp-foundation` (or approved main after merge).
2. Under **Artifacts**, download `fashion-cpanel-site`. GitHub downloads a ZIP containing the website's files.
3. In cPanel > **Domains**, locate `fashion.azenith.ca` and note its exact **Document Root**. Do not assume it is `public_html/fashion`; cPanel sets the correct path.
4. In cPanel > **File Manager**, visit that exact root. Back up existing contents, if any. Upload the downloaded ZIP there; select **Extract**.
5. Verify `index.html` and `_next/` exist directly in that Document Root, not inside an extra nested folder.
6. Check both `https://fashion.azenith.ca/` and `https://fashion.azenith.ca/enquiry/`; confirm secure HTTPS, navigation and visual assets.
7. Keep the generated zip out of the site's root after extraction if possible. Do not replace/modify unrelated domains, DNS records or email routing.

## Current functionality and limits
This is a static **pilot demo**. Enquiry is client-side only: **no message is sent, stored or emailed**. No customer checkout, database, payment, staff portal or order processing. Do not advertise it as a live tailoring order system.

Next.js `output: "export"` generates `out/` and `trailingSlash: true` creates `enquiry/index.html` so standard Apache/cPanel hosting can serve the route without Node.js. Future authenticated forms, secure measurements and orders will require a backend/server or external managed services; static pages alone cannot provide them.

## If something does not work
- GitHub Actions failed: inspect Build and test steps; download artifact only after full success.
- 404 at root: ZIP was extracted in wrong document root or one directory too deep.
- 404 at /enquiry/: verify `enquiry/index.html` is present; try the trailing slash.
- CSS missing: verify `_next/static/` was extracted and accessible.
- Certificate issue: use cPanel SSL/TLS Status (AutoSSL if supported); DNS must already point to cPanel.
