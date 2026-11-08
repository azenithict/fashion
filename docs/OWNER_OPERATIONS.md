# Owner operations — launch candidate

This application is not yet approved for public paid trading. Work in the protected preview while completing commercial approvals.

## Day-to-day customer order

1. Customer creates a garment brief, signs in, and saves it. In the account they add private references and consented measurements, then submit for review. Saving is not submission or payment.
2. Operations checks references and measurement evidence, verifies measurements, and records feasibility approval or a revision request.
3. With approved merchant/pricing configuration, operations issues a versioned quotation with actual costs, customer-facing lines, tax/deposit, delivery terms and expiry. Never guess supplier capacity or lead time.
4. Customer accepts the current valid quote and terms. The server creates the order and invoice snapshot.
5. Only an authorized configured payment method may collect/verify funds. A browser redirect or customer screenshot alone must not create a paid state. Preview payments remain disabled.
6. Operations records supplier acknowledgement and the work order; progresses production with evidence. Passing QC and the required verified payment precede shipment. Record carrier/tracking, fitting and delivery as applicable.
7. Handle support in the order/account. Internal notes remain staff-only. Financial corrections use authorized refund/reversal paths; never edit immutable rows directly.

## Partners

Activate only real signed agreements and approved service rates. Assign a branch-scoped task; partner completion needs independent approval before earnings. Assisted measurements require explicit customer consent. Settle eligible ledger entries with evidence/reference, retaining the immutable statement.

## Before enabling public orders

Record approved merchant identity, tax handling, supplier capabilities/costs/lead times, delivery/refund/rework terms, privacy/measurement consent, partner terms where used, and the real payment provider's acceptance. Test one complete authorized journey on the actual domain. Confirm mail authentication/delivery, account limits, offsite backup retention and recovery ownership. Obtain owner design and final public-release approval.

## Code updates and incidents

GitHub preview deployment, once enabled, is described in GITHUB_CPANEL_DEPLOY.md. It does not migrate databases. A schema-changing release needs a separate backup/migration/recovery review. For failed updates, preserve error output and the private deployment record. Do not share passwords, token links, measurements or private attachments in chat/issues. Use the retained previous entry for code rollback. Database restore is a separate operation and must not be inferred from a code rollback.
