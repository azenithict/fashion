import contract from "../../shared/contract.json";
import type { ProductDetail } from "./catalog";

export const STUDIO_KEY = "azf-studio-draft";
export type StudioPath = "" | "catalog" | "concept";
export type StudioDraft = {
  v: 1; path: StudioPath; designId: string; name: string; slug: string;
  garment: string; shade: string; finish: string; fit: string;
  materials: Record<string, string>; options: Record<string, string>;
  occasion: string; deadline: string; quantity: string; notes: string; measurementId: string;
  step: number; maxStep: number; saved: string;
};
export const contentKey = (d: StudioDraft) => JSON.stringify([d.path, d.name, d.slug, d.garment, d.shade, d.finish, d.fit, d.materials, d.options, d.occasion, d.deadline, d.quantity, d.notes]);
export function emptyDraft(): StudioDraft {
  const d: StudioDraft = { v: 1, path: "", designId: "", name: "", slug: "", garment: "suit", shade: "", finish: "", fit: "", materials: {}, options: {}, occasion: "", deadline: "", quantity: "", notes: "", measurementId: "", step: 1, maxStep: 1, saved: "" };
  return { ...d, saved: contentKey(d) };
}
export const STEP_TITLES = ["Choose a garment", "Materials", "Construction", "Personal requirements", "Measurements", "Review and submit"];
const rec = (v: unknown): Record<string, unknown> => (typeof v === "object" && v !== null && !Array.isArray(v) ? v as Record<string, unknown> : {});
const strMap = (v: unknown): Record<string, string> => Object.fromEntries(Object.entries(rec(v)).map(([k, x]) => [k, typeof x === "string" ? x : typeof rec(x).id === "string" ? String(rec(x).id) : ""]).filter(([, x]) => x));

export function readDraft(): StudioDraft | null {
  try {
    const raw = window.localStorage.getItem(STUDIO_KEY); if (!raw) return null;
    const p = rec(JSON.parse(raw)); if (p.v !== 1) return null;
    const base = emptyDraft(); const s = (k: string) => (typeof p[k] === "string" ? p[k] as string : "");
    const n = (k: string, fallback: number) => (typeof p[k] === "number" && p[k] >= 1 && p[k] <= 6 ? p[k] as number : fallback);
    const path = p.path === "catalog" || p.path === "concept" ? p.path : "";
    return { ...base, path, designId: s("designId"), name: s("name"), slug: s("slug"), garment: s("garment") || "suit", shade: s("shade"), finish: s("finish"), fit: s("fit"), materials: strMap(p.materials), options: strMap(p.options), occasion: s("occasion"), deadline: s("deadline"), quantity: s("quantity"), notes: s("notes"), measurementId: s("measurementId"), step: n("step", 1), maxStep: n("maxStep", 1), saved: s("saved") };
  } catch { return null; }
}
export function writeDraft(d: StudioDraft | null) {
  try { if (d) window.localStorage.setItem(STUDIO_KEY, JSON.stringify(d)); else window.localStorage.removeItem(STUDIO_KEY); } catch { /* storage unavailable */ }
}
/** Build a draft from a GET /designs/:id record (draft shape: ids in selections, labels alongside). */
export function draftFromRecord(record: Record<string, unknown>): StudioDraft {
  const spec = rec(record.specification); const base = emptyDraft(); const name = typeof record.name === "string" ? record.name : "";
  const s = (k: string) => (typeof spec[k] === "string" ? spec[k] as string : "");
  const sel = rec(spec.selections);
  const d: StudioDraft = spec.mode === "catalog"
    ? { ...base, path: "catalog", slug: s("product_slug"), garment: s("garment") || "suit", materials: strMap(sel.materials), options: strMap(sel.options), fit: s("fit"), occasion: s("occasion"), deadline: s("deadline"), quantity: spec.quantity ? String(spec.quantity) : "", notes: s("notes") }
    : { ...base, path: "concept", garment: s("garment") || "suit", shade: s("shade"), finish: s("finish"), fit: s("fit"), notes: s("notes") };
  const full = { ...d, designId: typeof record.id === "string" ? record.id : "", name, step: 2, maxStep: 6 };
  return { ...full, saved: contentKey(full) };
}
export const needsQuantity = (product: ProductDetail | null) => product?.category === "corporate_uniform";
export const todayIso = () => { const t = new Date(); return t.getFullYear() + "-" + String(t.getMonth() + 1).padStart(2, "0") + "-" + String(t.getDate()).padStart(2, "0"); };
export const kindOrder = (kinds: string[]) => [...new Set(kinds)].sort((a, b) => (contract.materialKinds.indexOf(a) + 1 || 99) - (contract.materialKinds.indexOf(b) + 1 || 99));

export function validate(d: StudioDraft, product: ProductDetail | null, step: number): Record<string, string> {
  const e: Record<string, string> = {};
  if (step === 1 && !d.path) e.path = "Choose a garment or start from your own idea.";
  if (step === 2) {
    if (d.path === "catalog" && product?.materials.some(m => m.kind === "fabric") && !d.materials.fabric) e.fabric = "Choose a fabric to continue.";
    if (d.path === "concept") { if (!contract.garments.includes(d.garment)) e.garment = "Choose a garment."; if (!d.shade) e.shade = "Choose a colour direction."; }
  }
  if (step === 3) {
    if (d.path === "concept") { if (!d.fit) e.fit = "Choose a fit direction."; if (!d.finish) e.finish = "Choose a finishing detail."; }
    if (d.path === "catalog" && d.fit && !contract.fits.includes(d.fit)) e.fit = "Choose a listed fit.";
  }
  if (step === 4) {
    if (!d.name.trim()) e.name = "Give this design a name."; else if (d.name.trim().length > 120) e.name = "Use 120 characters or fewer.";
    if (d.occasion.length > 160) e.occasion = "Use 160 characters or fewer.";
    if (d.notes.length > 800) e.notes = "Use 800 characters or fewer.";
    if (d.deadline && (!/^\d{4}-\d{2}-\d{2}$/.test(d.deadline) || Number.isNaN(Date.parse(d.deadline)) || d.deadline < todayIso())) e.deadline = "Choose a valid date that is today or later.";
    if (d.path === "catalog" && needsQuantity(product)) { const q = Number(d.quantity); if (!/^\d+$/.test(d.quantity) || q < 1 || q > 10000) e.quantity = "Enter a whole number from 1 to 10,000."; }
  }
  return e;
}
export function firstInvalidStep(d: StudioDraft, product: ProductDetail | null): number {
  for (const step of [1, 2, 3, 4]) if (Object.keys(validate(d, product, step)).length) return step;
  return 0;
}
export function buildPayload(d: StudioDraft, product: ProductDetail | null): Record<string, unknown> {
  const name = d.name.trim();
  if (d.path === "concept") return { name, garment: d.garment, shade: d.shade, fit: d.fit, finish: d.finish, notes: d.notes.trim() };
  if (!product) throw new Error("The selected design is not loaded.");
  return { name, product_id: product.id, selections: { materials: d.materials, options: d.options },
    ...(d.fit ? { fit: d.fit } : {}), ...(d.occasion.trim() ? { occasion: d.occasion.trim() } : {}), ...(d.notes.trim() ? { notes: d.notes.trim() } : {}),
    ...(needsQuantity(product) && d.quantity ? { quantity: Number(d.quantity) } : {}), ...(d.deadline ? { deadline: d.deadline } : {}) };
}
/** Drop selections that the live product no longer offers. */
export function reconcile(d: StudioDraft, product: ProductDetail): { draft: StudioDraft; removed: { kind: "material" | "option"; label: string }[] } {
  const removed: { kind: "material" | "option"; label: string }[] = []; const materials: Record<string, string> = {}; const options: Record<string, string> = {};
  for (const [kind, id] of Object.entries(d.materials)) { if (product.materials.some(m => m.id === id && m.kind === kind)) materials[kind] = id; else removed.push({ kind: "material", label: kind }); }
  for (const [group, id] of Object.entries(d.options)) { if (product.options.some(o => o.id === id && o.group === group)) options[group] = id; else removed.push({ kind: "option", label: group }); }
  return { draft: removed.length ? { ...d, materials, options } : d, removed };
}
/** Same-origin relative paths only. */
export function safeNext(value: string | null | undefined): string | null {
  if (!value || !value.startsWith("/") || value.startsWith("//") || value.includes("\\") || /[\u0000-\u001f]/.test(value)) return null;
  return value;
}
