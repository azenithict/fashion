# MVP execution board

| ID | Workstream | Owner | Status | Acceptance |
|---|---|---|---|---|
| P0 | Product definition and partner economics | Orchestrator | DONE | Source-controlled, commercial assumptions explicitly marked |
| P1 | Working UI scaffold and garment enquiry demo | Frontend | IN_PROGRESS | Validated and responsive, no misleading paid-order claim |
| P2 | Auth, private profiles, measurement persistence | Backend | TODO | Customer isolation tests |
| P3 | Partner/branch data and role boundaries | Backend | TODO | No shared partner visibility |
| P4 | Design review and versioned quote | Backend + Frontend sequential ownership | TODO | Locked approved price and specs |
| P5 | Stripe test checkout and webhook idempotency | Backend | TODO | Duplicate event test |
| P6 | Production/QC/fulfillment state machine | Backend | TODO | Authorized transition tests |
| P7 | Service records, referral attribution and partner ledger | Backend | TODO | Reconciliation tests |
| P8 | Pilot workflow E2E and accessibility | QA | TODO | Pass/fail evidence |
| P9 | CI, env docs and pilot runbook | Release | IN_PROGRESS | CI passed, deployment guide included, production not linked |
| D1 | Canonical URL: fashion.azenith.ca | Release | DONE | URL recorded in app and documentation |
| D2 | Public DNS / TLS / hosting attach | Release + Owner | BLOCKED | Owner hosting connection and independent HTTPS verification |
| D3 | Production publish | Release + Owner | BLOCKED | Reviewed merge, passing preview and explicit production go-live decision |

No parallel edit of the same files. Master owns integration and releases. No partner activation before signed commercial terms.
