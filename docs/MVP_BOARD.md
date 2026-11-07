# MVP execution board

| ID | Workstream | Owner | Status | Acceptance |
|---|---|---|---|---|
| P0 | Product definition and partner economics | Orchestrator | DONE | Source-controlled, commercial assumptions explicitly marked |
| P1 | Working UI scaffold and garment enquiry demo | Frontend | DONE | Static, validated, no misleading paid-order claim |
| P2 | Auth, private profiles, measurement persistence | Backend | TODO | Customer isolation tests |
| P3 | Partner/branch data and role boundaries | Backend | TODO | No shared partner visibility |
| P4 | Design review and versioned quote | Backend + Frontend sequential ownership | TODO | Locked approved price and specs |
| P5 | Stripe test checkout and webhook idempotency | Backend | TODO | Duplicate event test |
| P6 | Production/QC/fulfillment state machine | Backend | TODO | Authorized transition tests |
| P7 | Service records, referral attribution and partner ledger | Backend | TODO | Reconciliation tests |
| P8 | Pilot workflow E2E and accessibility | QA | TODO | Pass/fail evidence |
| P9 | CI and static deployment artifact | Release | IN_PROGRESS | Successful GitHub Actions artifact, cPanel install by owner |
| D1 | Canonical URL: fashion.azenith.ca | Release | DONE | URL recorded in app and documentation |
| D2 | cPanel DNS / SSL / upload | Owner | TODO | Exact document root and working HTTPS checked |
| D3 | Production order intake | Engineering | BLOCKED | Secure persistence, legal/commercial approval, verified end-to-end ordering |

No partner activation before signed commercial terms.

## Production directive audit — 2026-11-06

| ID | Owner | Status | Evidence |
|---|---|---|---|
| A1 repository/live-site audit | Orchestrator + read-only reviewer | DONE | docs/HOSTING_AUDIT.md |
| A2 read-only hosting capability collector | Orchestrator | DONE | scripts/audit-cpanel.sh; syntax/local execution checked |
| A3 actual cPanel inspection and backend selection | Owner access → Orchestrator | BLOCKED | No authorized hosting connection; evidence requested |

The existing MVP board records historical scope. The current owner directive requires an integrated production system; prior demo completion does not satisfy it. No backend architecture is selected until A3 passes.

## Integrated production engineering — current ownership and evidence

| Work | Owner | State | Evidence |
|---|---|---|---|
| Host architecture evidence | Owner + orchestrator | PASS | Terminal + domain PHP Selector8.3, docs/ARCHITECTURE.md |
| Core auth/design/measurement/private support | Core specialist | IMPLEMENTED / LOCAL PASS |46 DB integration checks +6 mail-retry checks |
| Quotes/payments/fulfillment/economics | Commerce specialist + orchestrator concurrency fix | IMPLEMENTED / LOCAL PASS |28 unit,27 integration,9 concurrency checks |
| Partner agreements/tasks/ledger/settlements/branch access | Orchestrator | IMPLEMENTED / LOCAL PASS |Actual HTTP flow and tenant/financial protections |
| Customer/partner/operations frontend | Frontend specialist + orchestrator | IMPLEMENTED / LOCAL PASS |13 unit/client tests, Chromium workflows, typecheck/lint/static build |
| Packaging/private staging/logical backup | Orchestrator | IMPLEMENTED / PARTIAL LOCAL PASS |Manifest package; independent DB restore; host file rollback NOT TESTED |
| Live installation/SMTP/provider/legal/commercial approvals | Owner setup → engineering acceptance | BLOCKED |See docs/COMMERCIAL_APPROVALS.md |
| Standalone aftercare/catalog capability administration/marketing automation | Engineering after verified operational inputs | INCOMPLETE |Explicit limits in docs/PRODUCTION_READINESS.md |
| Full production acceptance and final release | Owner + engineering | BLOCKED |No production release claimed |

This section supersedes historical TODO/DONE labels above for current implementation evidence; the historical starter is not the strict production definition of done.
