# A Zenith Fashion

**A technology-enabled custom tailoring platform connecting customers, professional tailoring services, and garment production through one integrated experience.**

A Zenith Fashion is built around **custom tailoring**, not ready-to-wear retail or AI image generation. Customers specify garments and measurements; the platform supports feasibility review, quotations, production, fitting, alterations, and repeat orders.

## Public address
- Intended domain: **https://fashion.azenith.ca**
- **Status:** domain reported as created by the owner; hosting, DNS and live HTTPS still require verification.
- See [deployment runbook](docs/DEPLOYMENT.md) before configuring the subdomain.

## Business model (working definition)

- **Core revenue:** margin on custom garments manufactured by a contracted production partner in Asia.
- **Local service economy:** independent alteration and tailoring shops may earn separately for referrals, professional measurements, fitting, pickup, delivery, and alterations.
- **Two-way value:** the platform may also refer alteration and care inquiries to participating shops for an agreed service/referral fee.
- **Pilot:** 3–5 independently branded Ontario partner locations, subject to signed agreements. No location is currently contracted.
- **Future capabilities:** digital garment history, AI-assisted design, reusable measurements, and deeper partner tooling after commercial validation.

## Local development
Use Node.js 22 and run `npm install`, then `npm run dev`. Execute `npm run typecheck`, `npm run lint`, `npm test` and `npm run build` before proposing a merge. The current enquiry tool is **local-only**; it does not send customer information to a server.

## Project rules
- Treat paid custom-tailoring orders and positive contribution margin as the primary outcomes.
- Preserve shop ownership, branding, and commercial independence.
- Pay for objectively verifiable services; do not promise an undefined percentage of net profit.
- Never present 50 Ontario alteration locations as confirmed partners.
- Never treat AI-generated designs as production-approved garments.
- Do not accept paid orders before pricing, delivery commitments, and refunds/alterations responsibility are operationally defined.

## Status
**Foundation / validation stage.** Product specification, engineering rules and implementation are proposed in feature branches before approval and integration into `main`.

Repository: https://github.com/azenithict/fashion
