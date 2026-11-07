# Operator approval records — required before paid launch

No approval is implied by these blank records. Record approver, dated evidence/reference, scope and revision in private operational records; do not commit customer or contract secrets.

| Decision | Required input | Current state |
|---|---|---|
| Merchant/tax | Legal entity, tax registrations, province/place-of-supply handling, approved invoice content | BLOCKED: not supplied |
| Supplier | Approved garment capabilities, materials, quantities, unit/landed cost, QC/rework terms, shipping responsibilities and delivery commitments | BLOCKED: not supplied |
| Pricing | Approved cost components, target contribution margin in basis points, tax rules, discount authority | BLOCKED: no default prices; config pricing_min_margin_bps=null |
| Customer terms | Approved privacy/measurement consent, cancellation/refund/rework, delivery and dispute policies; applicable accessibility/consumer/label requirements | BLOCKED: business/legal review required |
| Manual payments | Approved bank/payment instructions, who can verify, evidence and reconciliation/refund procedure | BLOCKED: config disabled |
| Stripe | Owner-controlled account activation, authorized test/live credentials, verified webhook/refund acceptance | BLOCKED: no account keys supplied |
| Partner | Real signed agreement per independent business/branch, approved service rate-card version, qualifying evidence/referral/refund conditions, settlement cycle | BLOCKED: no partners presumed active |
| Mail | Authenticated SMTP or approved sendmail sender, actual delivery and recovery test | BLOCKED: not configured |
| Data operations | Approved retention/deletion/export handling and private backup custody/restore test | BLOCKED: operational/legal signoff |
| Final release | Exact commit/package, completed actual-domain acceptance, backup and rollback evidence, named owner approval | BLOCKED |

Zero new recurring infrastructure cost was introduced. Payment processing fees and optional future AI usage are separate costs. The uncontracted50-location Ontario network is not represented as participating.
