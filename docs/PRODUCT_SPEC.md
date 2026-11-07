# Product specification — A Zenith Fashion
Status: production scope; implementation evidence in PRODUCTION_READINESS.md | 2026-11-06

## Definition
**A technology-enabled custom tailoring platform connecting customers, professional tailoring services, and garment production through one integrated experience.**

Custom tailoring is the core service and primary commercial engine. AI is an optional assistant, not the business model. Toronto/Ontario is the initial market.

## Confirmed facts and constraints
- A contracted clothing producer in Asia can manufacture men's suits, women's clothing, occasionwear, traditional clothing, uniforms and other garments; specific costs, minimum quantities, lead times and quality terms are not yet verified.
- An Ontario family owns approximately 50 alteration locations; **there is no agreement or confirmed participation**. Do not present these locations as partners, sales outlets or assets controlled by A Zenith Fashion.
- The initial pilot targets **3–5 independent partner locations**, which must be recruited and contracted before activation.
- Each location keeps its brand and business independence.
- Partners should be compensated for **services actually performed**, while A Zenith Fashion also creates referral opportunities for those partners.

## Core customer journey
1. Customer selects a custom garment category, preferences and reference images.
2. Customer saves a draft and provides measurements manually or at a contracted fitting partner.
3. Staff reviews feasibility; AI suggestions cannot commit production details.
4. Staff issues a versioned quote with price, scope, lead time, fitting, delivery and cancellation terms.
5. Customer approves the final specification and pays via verified checkout.
6. Operations sends the locked production specification to the overseas manufacturing partner.
7. Production, QC, import and shipment are tracked.
8. Customer receives a fitting/alterations service when needed, and delivery is confirmed.
9. Measurements and garment history can support repeat orders with customer consent.

## Product lanes
- **Custom tailoring**: core; men's, women's, occasionwear, traditional wear; garment categories are configurable, not freeform manufacturing promises.
- **Uniform / group orders**: B2B enquiry and manually approved quotes.
- **Local alteration**: partner-provided, priced per service.
- **Cleaning / garment care**: inquiry/referral only until operating agreements exist.

## Business events
- Referral attribution: capture origin once with traceable source.
- Service completion: branch staff records measurements, fitting, pickup, delivery or alteration with evidence.
- Partner payable: fee earned only under agreement's qualifying event and refund rules.
- Manufacturer payable: contracted cost under supplier contract.
- Customer charge: approved quote under A Zenith Fashion commercial policy.
- Adjustments: audit credit/reversal, never delete ledger entries.

## Roles
CUSTOMER, PARTNER_STAFF, PARTNER_ADMIN, FASHION_OPERATIONS, FASHION_ADMIN. A manufacturing role is a later integration option.

## Unsupported technology and approval boundaries
Automatic 3D measurement claims, AR try-on, unrestricted generative design sold as manufacturable, public marketplace, 50-location rollout, automatic garment manufacture, unreviewed quote/discount rules, and live payment processing without approved policies.

## Commercial validation gates
Before paid launch, confirm with written evidence: supplier cost and lead time, landed import cost (HS code/country), fitting and alteration responsibility, refund/cancellation policies, partner agreements, privacy/measurement consent and merchant-of-record structure. Track paid orders, contribution margin, rework rate, lead time, partner earnings net of service cost and repeat orders.

## Success metric
Paid custom tailoring orders fulfilled at positive **contribution margin after production, import, partner fees, customer acquisition, processing, rework and other variable costs**. Do not substitute number of AI interactions or signups for revenue.
