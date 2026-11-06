# Engineering rules

1. Read PRODUCT_SPEC, PARTNER_ECONOMICS, ASSUMPTIONS, ARCHITECTURE and MVP_BOARD before editing.
2. Custom tailoring is the domain center. Do not reframe the product as a generic boutique or AI image generator.
3. Inspect existing code and reuse it; change only needed files. Shared schemas belong to a single owner.
4. Prefer vertical slices, tiny modules and deterministic business logic.
5. Branch before implementation; no force-push, no unreviewed main merge.
6. Make each specialist own disjoint files. Parallel agents may review but not concurrently modify shared modules.
7. Validate server boundaries, protect customer measurements and photos, keep immutable quote snapshots.
8. Never invent price, turnaround, capacity, legal compliance or branch participation.
9. Test changes before marking done; label unavailable integrations BLOCKED instead of PASS.
10. Report commit IDs and exact verification outcomes.
