# Partner network — service-based economics

## Principle
Pay each party for measurable value created and work delivered. No undefined percentage of company net profit. Branches retain existing names, ownership and retail independence. The 50-location family is a potential future partner, **not contracted**.

## Example payable events (CAD; negotiable assumptions, not approved tariffs)
| Event | Qualifying evidence | Illustrative fee | Payment trigger |
|---|---|---:|---|
| Customer referral | Attributed, qualified paid order, no duplicate | $30 | Confirmed payment after refund window policy |
| Professional measurement | Approved measurement record with operator/time | $25 | Completion and review |
| Final fitting / handover | Customer receipt confirmation | $20 | Completion |
| Alteration | Approved itemized service order | By job rate card | Completed/accepted |
| Fashion-to-partner alteration referral | Referral code linked to a completed paid partner service | Negotiated fee/cap | Settlement confirmation |

Different events can earn separate fees. Never pay referral commission for self-acquired customers incorrectly attributed to a shop. Partner service fees are payable irrespective of commission eligibility. Reverse or offset amounts on cancellation under agreed terms, never silently erase ledger history.

## Financial model
Customer retail price (excluding sales tax)
minus manufacturing
minus freight/import/duties
minus payment fees
minus partner service fees and earned commissions
minus customer acquisition
minus expected rework/refund costs
= **contribution before fixed overhead and income tax**.

Illustrative $650 order, not verified: manufacturing $220, freight/import $85, payment/operations $45, partner events $75, rework provision $40 = $185 contribution. Any tariff, tax, GST/HST treatment or margin target requires accountant and commercial validation.

## Partner ledger
Store entries as immutable transaction records with:
partner_id, branch_id, order_id, service_id, entry_type, amount_minor, currency, trigger_event_id, state (pending/earned/settled/reversed), created_at, approved_at, settlement_id, idempotency_key.
Prevent duplicate earnings, preserve correction history, and distinguish gross partner revenue from partner profit. Settlement batches should be reconcilable and exportable. Recommend fortnightly settlements only if agreed.

## Operational agreement checklist
- Separate role of customer merchant vs independent service provider.
- Service rates and trigger evidence; refunds/chargebacks.
- Responsibility matrix for bad measurements, fabrication errors, missing deadlines, repairs.
- Consent and data sharing, privacy, retention and deletions.
- Branding/IP, no exclusivity by default, staff training, customer escalation, termination.
- Ontario legal review for the exact partner structure.
