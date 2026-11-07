# Production engineering evidence

Decision: **NOT PRODUCTION READY.** This branch implements a persistent commercial core and a cPanel release candidate. It has not replaced the live static site. Local test fixtures are isolated; no mock success paths or seeded production customers/prices/partners/payments are deployed.

## Implemented functionality
- Secure account registration/login/logout, verification/recovery token workflows, session expiry/revocation/CSRF/rate limits, profiles/addresses, data export and deletion-request recording.
- Real private design persistence/resume/duplicate/revision/submission, immutable version snapshots, reference uploads, unit-aware consented measurements and independent staff verification.
- Task-scoped partner-assisted measurements with explicit customer consent, provenance/captured operator, branch isolation and no silent mutation of approved specifications.
- Staff feasibility review, integer CAD quote components/margin gates, approved merchant/invoice identity, tax, version/expiry, customer acceptance, immutable order/invoice snapshots and repeat-design creation.
- Approved manual payment evidence and reconciliation, deposit/balance, unique-reference protection, genuine Stripe Checkout/signature/canonical retrieval integration, idempotent receipt handling, refund bounds and disputes. No processor account is activated.
- Payment-gated production preparation/work orders, supplier acknowledgement, controlled production/QC/rework/shipping/fitting/delivery lifecycle, verified expense recording and order contribution on actual recorded collections/costs.
- Approved partner applications/contracts/branches, branch staff access, versioned immutable rate cards, assigned appointment/service tasks, completion/independent approval, append-only earnings/reversals, reconciled settlements/statements and tenant isolation.
- Role-specific customer/partner/operations workspaces; customer support/internal-note separation; notifications/outbox with genuine SMTP/sendmail worker and durable retry handling.
- Public category/service/process pages, measurement guidance, truthful partner/application pages, support links, per-page canonicals, sitemap and draft policy notices. Public indexing remains disabled pending approval.
- Coherent private/public release packaging, hash manifest/verification, private staging, archive quarantine helper, logical database export, private backup wrapper and documented recovery.

## Executed evidence (isolated environment)
| Check | Result | Scope |
|---|---|---|
| PHP8.3 syntax | PASS | Backend source/CLI/tests |
| MariaDB10.11 clean migrations001–005 | PASS |37 tables,28 immutability triggers |
| Core DB integration | PASS |46 assertions: account hashing/tokens/replay/CSRF, consent/units, private design persistence/isolation, submitted immutability, support privacy, session revocation/expiry, partner measurement consent/isolation/provenance |
| Mail failure/retry | PASS |6 assertions: unavailable SMTP never marked delivered, durable attempts/retry/exhaustion/manual retry audit |
| Commerce unit | PASS |28 integer pricing/bounds/tax/due/transition/HMAC checks |
| Commerce DB integration | PASS |27 assertions: quote versions, approval, invoice, payment/refund bounds/idempotency, expense reporting, QC/payment gates |
| Commerce concurrency | PASS |9 assertions: concurrent approval yields one order; duplicate/competing payment receipts cannot double funds |
| Actual HTTP API acceptance | PASS |42 assertions plus full order journey: registration/verification → saved design/measurements → review/quote/acceptance → verified manual deposit/balance → production/QC → shipment/delivery; partner agreement/service/earning/settlement/reversal; isolation and removed-rate handling |
| Frontend TypeScript/lint/build | PASS |Next16.4.5, React19.3.1; 27 generated static routes |
| Frontend/unit tests | PASS |13 tests including real-error/CSRF client boundaries |
| Production dependency audit | PASS |npm audit --omit=dev: zero known vulnerabilities at test time; not a guarantee of security |
| Chromium workflow checks | PASS |8 checks: real signup/design save/navigation persistence/consented measurements, unverified-email rejection, duplication, support and desktop/mobile width; admin login/reports/partner network and service canonical checked |
| Static UI browser checks | PASS |Studio options and unavailable-backend failures; no fabricated successful persistence |
| Logical database restore | PASS |Snapshot restored into independent MariaDB10.11 instance:37 tables,28 triggers |
| PHP/source/core credentials in public package | PASS by construction |Only public entry PHP; secrets/config/storage outside public. Manifest verifier rejects extra executables/checksum mismatches |

## Important findings resolved
- Premature external-hosting/Supabase assumption replaced with owner-verified PHP8.3/MariaDB cPanel architecture; no new subscription.
- Known vulnerable Next/React dependencies upgraded; deterministic lockfile/npm ci and correct generated-output lint exclusions.
- MariaDB reserved `lines` identifier quoted; consistent utf8mb4 foreign-key collation.
- Concurrent quote approvals initially exposed a stale snapshot read: changed order/latest-quote reads to locking current reads; nine concurrency assertions pass.
- Removed partner service no longer falls back to an older rate card; settlement/reversal serialize on branch; pending negative adjustments must be included; completed measurement fees require captured and independently verified data.
- Backend/config/storage/session paths rejected when public; source archives denied by candidate Apache rules; the existing live ZIP still needs operator quarantine.

## External production gates
| Gate | Status | Required evidence |
|---|---|---|
| Existing-host PHP8.3/MariaDB/Apache | PASS for architecture |Owner Terminal + PHP Selector reports; live API deployment not yet tested |
| Actual DB/database-user creation and runtime grants | BLOCKED |Dedicated cPanel database and private credentials; no credentials connected to agent |
| Web extensions, rewrite/headers/limits, private path permissions | NOT TESTED |Actual deployed-host API acceptance and cPanel account quota/process inspection |
| Current public root backup and archive containment | BLOCKED |Owner/authorized host action; discovered ZIP retained until moved privately |
| Authenticated SMTP / actual mail delivery | BLOCKED |Real sender credentials or approved sendmail and received verification/recovery/order mail |
| Stripe remote checkout/webhooks/refunds | BLOCKED |Authorized provider test/live account; no real provider calls verified |
| Manual collection | BLOCKED in production |Written business approval, bank/payment instructions and operators; default off |
| Supplier/material/pricing/merchant/tax/refund approvals | BLOCKED |Actual signed/approved commercial evidence; no invented rates or dates |
| Independent partner activation | BLOCKED in production |Real signed agreements/rates; test fixtures do not imply active shops |
| Production backup restore/file rollback | NOT TESTED |Private host backup, isolated actual-host recovery and coordinated rollback exercise |
| Legal/privacy/accessibility/consumer/tax/labelling review | BLOCKED |Approved policies and operational/legal review; draft pages do not establish compliance |
| Final public release | BLOCKED |Exact reviewed commit/package and named owner approval after actual-domain acceptance |

## Remaining scope that is not represented as complete
- Independent alteration/repair/cleaning services are traceable support intake plus approved partner tasks against orders; a separate standalone aftercare quotation/invoice/booking lifecycle is not yet implemented. Do not advertise unsupported providers or cleaning/pickup as available.
- Catalog/production options are conceptual directions reviewed by staff, not a supplier-approved inventory/capability management system. Supplier capability/rate data must be supplied before binding option availability is published.
- There is no autonomous body scan, try-on or AI provider; no simulated substitutes. A provider-agnostic paid AI layer remains unimplemented until authorized integration requirements are supplied.
- Marketing attribution, consented abandoned-design email recovery, category/retention acquisition reports, automated settlement cycles, and advanced appointment rescheduling/capacity are not implemented. Current reports are actual per-order economics and partner statements.
- Deletion requests are recorded for authorized privacy handling; automated lawful erasure/retention orchestration is not implemented.
- Browser coverage is Chromium only; full cross-browser/accessibility audit, real provider handoff and actual-domain acceptance remain outstanding.

These limits mean the original strict definition of done is not met. Do not describe this branch or its passing local tests as a completed production business platform.

## Costs
Existing cPanel only; no new recurring hosting subscription. Stripe charges processing/refund-related fees under the actual account. SMTP must fit the existing account's limits. Future AI use would incur separately approved provider charges. Off-host backup custody and business/legal operations remain owner responsibilities.
