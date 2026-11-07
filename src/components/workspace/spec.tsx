"use client";
import { label, row, text, type Row } from "@/lib/api";
import { humanize, kindLabels } from "@/lib/catalog";
import { RecordFacts } from "./common";

const LEGACY = ["name", "garment", "shade", "fit", "finish", "notes"];
const asText = (v: unknown) => (typeof v === "string" || typeof v === "number" ? String(v) : "");

/** Readable catalog specification: handles the draft shape (ids + labels) and the submitted snapshot shape (objects). Legacy concept specs fall back to plain facts. */
export function isCatalogSpec(spec: Row) { return spec.mode === "catalog"; }
export function SpecFacts({ spec, staff = false }: { spec: Row; staff?: boolean }) {
  if (!isCatalogSpec(spec)) return <RecordFacts record={spec} fields={LEGACY} />;
  const sel = row(spec.selections); const labels = row(spec.labels);
  const materials = Object.entries(row(sel.materials)); const options = Object.entries(row(sel.options));
  const rowsOut: [string, string, string][] = [];
  for (const [kind, value] of materials) {
    const m = row(value); const name = asText(m.name) || asText(row(labels.materials)[kind]) || "Selected (name unavailable)";
    const detail = [m.composition, m.colour_name, m.pattern, m.weight_gsm ? m.weight_gsm + " gsm" : "", m.weave, m.finish, staff && m.supplier_code ? "Supplier code " + asText(m.supplier_code) : ""].map(asText).filter(Boolean).join(" · ");
    rowsOut.push([kindLabels[kind] ?? humanize(kind), name, detail]);
  }
  for (const [group, value] of options) {
    const o = row(value); const name = asText(o.label) || asText(row(labels.options)[group]) || "Selected (label unavailable)";
    rowsOut.push([humanize(group), name, asText(o.description)]);
  }
  const facts: [string, string][] = [["Design", asText(spec.product_name)], ["Garment", spec.garment ? label(text(spec.garment)) : ""], ["Fit", asText(spec.fit)], ["Occasion", asText(spec.occasion)], ["Deadline", asText(spec.deadline)], ["Quantity", asText(spec.quantity)], ["Notes", asText(spec.notes)]];
  return <dl className="record-facts">
    {facts.slice(0, 2).filter(([, v]) => v).map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}
    {rowsOut.map(([k, v, d]) => <div key={k}><dt>{k}</dt><dd>{v}{d ? <small className="sz-sub">{d}</small> : null}</dd></div>)}
    {facts.slice(2).filter(([, v]) => v).map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}
  </dl>;
}
