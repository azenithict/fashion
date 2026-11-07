import { describe, expect, it } from "vitest";
import { buildPayload, emptyDraft, reconcile, safeNext, validate } from "../src/lib/studio";
import type { ProductDetail } from "../src/lib/catalog";
const product = { id: "p1", slug: "s", name: "Suit", category: "mens_tailoring", garment: "suit", materials: [{ id: "m1", kind: "fabric", name: "Wool", media: [] }], options: [{ id: "o1", group: "lapel", label: "Notch", media: [] }] } as unknown as ProductDetail;
describe("studio helpers", () => {
  it("accepts only same-origin relative return paths", () => {
    expect(safeNext("/studio/")).toBe("/studio/");
    for (const bad of ["//evil.example", "https://evil.example", "studio", "/\\evil", "", null]) expect(safeNext(bad as string | null)).toBeNull();
  });
  it("requires a fabric when one is offered and sends only chosen selections", () => {
    const d = { ...emptyDraft(), path: "catalog" as const, slug: "s", name: "Mine" };
    expect(validate(d, product, 2).fabric).toBeTruthy();
    const ok = { ...d, materials: { fabric: "m1" }, notes: " hi " };
    expect(validate(ok, product, 2)).toEqual({});
    expect(buildPayload(ok, product)).toEqual({ name: "Mine", product_id: "p1", selections: { materials: { fabric: "m1" }, options: {} }, notes: "hi" });
  });
  it("drops selections the catalogue no longer offers", () => {
    const d = { ...emptyDraft(), path: "catalog" as const, materials: { fabric: "gone" }, options: { lapel: "o1" } };
    const r = reconcile(d, product);
    expect(r.removed).toHaveLength(1); expect(r.draft.materials).toEqual({}); expect(r.draft.options).toEqual({ lapel: "o1" });
  });
});
