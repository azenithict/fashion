"use client";
import { useState } from "react";
import contract from "../../../shared/contract.json";
import { api, apiPath, row, rows, text, type Row } from "@/lib/api";
import { categories, categoryLabel, garmentLabels, humanize, kindLabels, sourceLabels } from "@/lib/catalog";
import { ActionForm, Field, Notice, RecordFacts, ResourceState, Status, useAction, useResource, value } from "./common";

type Kind = "products" | "materials" | "options";
type Spec = { key: string; title: string; kind?: "text" | "textarea" | "select" | "int" | "hex" | "garments" | "faq"; identity?: boolean; required?: boolean; options?: [string, string][]; max?: number };
const pairs = (keys: readonly string[], labels: Record<string, string>) => keys.map(k => [k, labels[k] ?? humanize(k)] as [string, string]);
const garmentOptions = pairs(contract.garments, garmentLabels);
const specs: Record<Kind, Spec[]> = {
  products: [
    { key: "sku", title: "SKU", identity: true, required: true, max: 60 },
    { key: "slug", title: "Slug (web address)", identity: true, required: true, max: 80 },
    { key: "garment", title: "Garment type", kind: "select", identity: true, required: true, options: garmentOptions },
    { key: "name", title: "Name", required: true, max: 160 },
    { key: "category", title: "Category", kind: "select", required: true, options: categories.map(c => [c, categoryLabel(c)] as [string, string]) },
    { key: "description", title: "Description", kind: "textarea", required: true, max: 4000 },
    { key: "faq", title: "FAQ", kind: "faq" },
    { key: "supplier_reference", title: "Supplier reference (private)", max: 160 },
    { key: "min_order_quantity", title: "Minimum order quantity", kind: "int" },
  ],
  materials: [
    { key: "kind", title: "Kind", kind: "select", identity: true, required: true, options: pairs(contract.materialKinds, kindLabels) },
    { key: "supplier_code", title: "Supplier code", identity: true, required: true, max: 80 },
    { key: "name", title: "Name", required: true, max: 160 },
    { key: "suitable_garments", title: "Suitable garments", kind: "garments", required: true },
    { key: "composition", title: "Composition", max: 200 },
    { key: "colour_name", title: "Colour name", max: 80 },
    { key: "colour_hex", title: "Approximate colour", kind: "hex" },
    { key: "pattern", title: "Pattern", max: 80 },
    { key: "weight_gsm", title: "Weight (gsm)", kind: "int" },
    { key: "weave", title: "Weave", max: 80 },
    { key: "finish", title: "Finish", max: 80 },
    { key: "stretch", title: "Stretch", max: 80 },
    { key: "care", title: "Care", max: 500 },
    { key: "supplier_reference", title: "Supplier reference (private)", max: 160 },
    { key: "private_cost_minor", title: "Private cost (CAD cents, never public)", kind: "int" },
  ],
  options: [
    { key: "garment", title: "Garment type", kind: "select", identity: true, required: true, options: garmentOptions },
    { key: "group_key", title: "Group key (e.g. lapel_style)", identity: true, required: true, max: 40 },
    { key: "label", title: "Label", required: true, max: 120 },
    { key: "description", title: "Description", max: 500 },
  ],
};
const typeNames: Record<Kind, string> = { products: "design", materials: "material", options: "option" };
const date = (v: unknown) => { const d = new Date(text(v, "")); return Number.isNaN(d.getTime()) ? text(v) : d.toLocaleString("en-CA", { dateStyle: "medium", timeStyle: "short" }); };
const str = (v: unknown) => (typeof v === "string" || typeof v === "number" ? String(v) : "");
const ids = (v: unknown) => (Array.isArray(v) ? v.map(String) : []);
const recordName = (type: Kind, r: Row) => text(type === "options" ? r.label : r.name);

function build(type: Kind, edit: boolean, v: Record<string, string>, garments: string[], faq: { q: string; a: string }[]): Row {
  const body: Row = {};
  for (const s of specs[type]) {
    if (edit && s.identity) continue;
    const k = s.kind ?? "text";
    if (k === "garments") { if (!garments.length) throw new Error("Choose at least one suitable garment."); body[s.key] = garments; continue; }
    if (k === "faq") {
      const items = faq.map(i => ({ q: i.q.trim(), a: i.a.trim() })).filter(i => i.q || i.a);
      if (items.some(i => !i.q || !i.a)) throw new Error("Each FAQ entry needs both a question and an answer.");
      if (items.length || edit) body[s.key] = items;
      continue;
    }
    const raw = (v[s.key] ?? "").trim();
    if (!raw) { if (s.required) throw new Error(s.title + " is required."); if (edit) body[s.key] = null; continue; }
    if (k === "int") {
      const n = Number(raw); const min = s.key === "min_order_quantity" ? 1 : 0;
      if (!Number.isSafeInteger(n) || n < min) throw new Error("Enter a whole number of " + min + " or more for " + s.title + ".");
      body[s.key] = n; continue;
    }
    if (k === "hex" && !/^#[0-9a-fA-F]{6}$/.test(raw)) throw new Error("Colour must be written like #1a2b3c.");
    body[s.key] = raw;
  }
  return body;
}

function RecordForm({ type, record, locked, onSaved }: { type: Kind; record?: Row; locked?: boolean; onSaved: (saved: Row) => void | Promise<void> }) {
  const edit = Boolean(record); const action = useAction();
  const [v, setV] = useState<Record<string, string>>(() => Object.fromEntries(specs[type].map(s => [s.key, record ? str(record[s.key]) : ""])));
  const [garments, setGarments] = useState<string[]>(() => ids(record?.suitable_garments));
  const [faq, setFaq] = useState<{ q: string; a: string }[]>(() => rows(record?.faq).map(i => ({ q: str(i.q), a: str(i.a) })));
  const set = (key: string) => (next: string) => setV(prev => ({ ...prev, [key]: next }));
  async function submit(event: React.FormEvent) {
    event.preventDefault();
    await action.run(async () => {
      const body = build(type, edit, v, garments, faq);
      const saved = await api<Row>(edit ? "/catalog/" + type + "/" + text(record?.id) : "/catalog/" + type, edit ? "PATCH" : "POST", body);
      await onSaved(row(saved));
    }, edit ? "Changes saved." : "Created.");
  }
  return <form className="portal-form" onSubmit={submit}><fieldset disabled={action.pending || locked}>
    {specs[type].filter(s => !(edit && s.identity)).map(s => {
      const k = s.kind ?? "text";
      if (k === "select") return <Field key={s.key} title={s.title} name={s.key} value={v[s.key]} onChange={set(s.key)} required={s.required}><option value="">Choose…</option>{s.options?.map(([val, title]) => <option key={val} value={val}>{title}</option>)}</Field>;
      if (k === "garments") return <fieldset key={s.key} className="cat-checks"><legend>{s.title}</legend>{garmentOptions.map(([val, title]) => <label key={val} className="portal-check"><input type="checkbox" checked={garments.includes(val)} onChange={e => setGarments(prev => e.target.checked ? [...prev, val] : prev.filter(g => g !== val))} /><span>{title}</span></label>)}</fieldset>;
      if (k === "faq") return <fieldset key={s.key} className="cat-checks"><legend>{s.title} <small>(optional, up to 12)</small></legend>
        {faq.map((item, i) => <div key={i} className="cat-faq-row"><Field title={"Question " + (i + 1)} required={false} max={200} value={item.q} onChange={q => setFaq(prev => prev.map((p, j) => j === i ? { ...p, q } : p))} /><Field title="Answer" type="textarea" required={false} max={1500} value={item.a} onChange={a => setFaq(prev => prev.map((p, j) => j === i ? { ...p, a } : p))} /><button type="button" className="plain-button" onClick={() => setFaq(prev => prev.filter((_, j) => j !== i))}>Remove question {i + 1}</button></div>)}
        {faq.length < 12 && <button type="button" className="button button-outline" onClick={() => setFaq(prev => [...prev, { q: "", a: "" }])}>Add a question</button>}</fieldset>;
      if (k === "hex") return <div key={s.key} className="cat-colour"><Field title={s.title + " (e.g. #1a2b3c)"} required={false} max={7} value={v[s.key]} onChange={set(s.key)} /><label className="portal-field"><span>Pick</span><input type="color" aria-label="Pick approximate colour" value={/^#[0-9a-fA-F]{6}$/.test(v[s.key]) ? v[s.key] : "#000000"} onChange={e => set(s.key)(e.target.value)} /></label>{v[s.key] && <button type="button" className="plain-button" onClick={() => set(s.key)("")}>Clear colour</button>}</div>;
      if (k === "int") return <Field key={s.key} title={s.title} type="number" min={s.key === "min_order_quantity" ? 1 : 0} step="1" required={false} value={v[s.key]} onChange={set(s.key)} />;
      return <Field key={s.key} title={s.title} type={k === "textarea" ? "textarea" : "text"} max={s.max} required={s.required} value={v[s.key]} onChange={set(s.key)} />;
    })}
    <button className="button button-dark" type="submit">{action.pending ? "Saving…" : edit ? "Save changes" : "Create " + typeNames[type]}</button>
  </fieldset><Notice error={action.error} message={action.message} /></form>;
}

function StatusPanel({ type, id, transitions, onDone }: { type: Kind; id: string; transitions: Row[]; onDone: () => Promise<void> }) {
  const action = useAction(); const [refs, setRefs] = useState<Record<string, string>>({});
  if (!transitions.length) return <p className="empty-state">No further status changes are available.</p>;
  return <div className="cat-transitions">{transitions.map(t => {
    const to = text(t.status, ""); const blockers = Array.isArray(t.blockers) ? t.blockers.map(String) : []; const need = t.reference_required === true; const ref = (refs[to] ?? "").trim();
    return <div className="cat-transition" key={to}>
      <h4>Move to {humanize(to)}</h4>
      {blockers.length > 0 && <><p className="hint">This change is blocked until:</p><ul className="cat-blockers">{blockers.map(b => <li key={b}>{b}</li>)}</ul></>}
      {need && <Field title="Evidence reference — e.g. supplier confirmation" required max={200} value={refs[to] ?? ""} onChange={val => setRefs(prev => ({ ...prev, [to]: val }))} />}
      <button className="button button-outline" type="button" disabled={action.pending || blockers.length > 0 || (need && !ref)} onClick={() => {
        if (to === "archived" && !window.confirm("Archive this record? Archived records cannot be edited.")) return;
        void action.run(async () => { await api("/catalog/" + type + "/" + id + "/status", "POST", need ? { status: to, reference: ref } : { status: to }); await onDone(); }, "Status changed to " + humanize(to) + ".");
      }}>Move to {humanize(to)}</button>
    </div>;
  })}<Notice error={action.error} message={action.message} /></div>;
}

function AltEditor({ item, locked, onChanged }: { item: Row; locked: boolean; onChanged: () => Promise<void> }) {
  const [alt, setAlt] = useState(str(item.alt_text)); const action = useAction();
  return <div className="cat-alt"><Field title="Alt text" max={250} required={false} value={alt} onChange={setAlt} />
    <button type="button" className="plain-button" disabled={locked || action.pending || alt === str(item.alt_text)} onClick={() => void action.run(async () => { await api("/catalog/media/" + text(item.id), "PATCH", { alt_text: alt.trim() }); await onChanged(); }, "Alt text saved.")}>Save alt text</button>
    <Notice error={action.error} message={action.message} /></div>;
}

const imageTypes = ["image/jpeg", "image/png", "image/webp"];
function MediaManager({ type, id, media, locked, onChanged }: { type: Kind; id: string; media: Row[]; locked: boolean; onChanged: () => Promise<void> }) {
  const action = useAction(); const owner = type === "products" ? "product" : type === "materials" ? "material" : "option";
  const call = (task: () => Promise<unknown>, success: string) => void action.run(async () => { await task(); await onChanged(); }, success);
  function reorder(index: number, dir: number) {
    const next = media.slice(); const j = index + dir; if (j < 0 || j >= next.length) return;
    [next[index], next[j]] = [next[j], next[index]];
    void action.run(async () => { for (let i = 0; i < next.length; i++) if (Number(next[i].sort_order) !== i + 1) await api("/catalog/media/" + text(next[i].id), "PATCH", { sort_order: i + 1 }); await onChanged(); }, "Order saved.");
  }
  return <section className="cat-media"><h3>Photographs</h3>
    <p className="hint">Replace a photo by uploading and approving the new one, then withdrawing the old one. Only approved photographs appear on the public site, and only once the record itself is published.</p>
    {!locked && <ActionForm title="Upload a photograph" submitLabel="Upload" success="Uploaded. The photograph stays pending until you approve it." onDone={() => void onChanged()} onSubmit={data => {
      const file = data.get("file");
      if (!(file instanceof File) || !file.size) throw new Error("Choose a photograph to upload.");
      if (!imageTypes.includes(file.type)) throw new Error("Photographs must be JPEG, PNG or WebP.");
      if (file.size > 15 * 1024 * 1024) throw new Error("Photographs must be 15 MB or smaller.");
      const body = new FormData(); body.set("owner_type", owner); body.set("owner_id", id);
      for (const key of ["source", "licence_reference", "rights_holder", "alt_text"]) body.set(key, value(data, key));
      body.set("file", file); return api("/catalog/media", "POST", body);
    }}>
      <label className="portal-field"><span>Photograph (JPEG, PNG or WebP, at least 800 px, up to 15 MB)</span><input type="file" name="file" accept="image/jpeg,image/png,image/webp" required /></label>
      <Field title="Source" name="source"><option value="">Choose…</option>{pairs(contract.mediaSources, sourceLabels).map(([val, title]) => <option key={val} value={val}>{title}</option>)}</Field>
      <Field title="Licence reference" name="licence_reference" max={200} /><Field title="Rights holder" name="rights_holder" max={160} /><Field title="Alt text (describe what the photograph shows)" name="alt_text" max={250} />
    </ActionForm>}
    <Notice error={action.error} message={action.message} />
    {media.length === 0 ? <p className="empty-state">No photographs yet.</p> : <ul className="cat-media-grid">{media.map((m, i) => {
      const status = text(m.status, ""); const mid = text(m.id); const live = status !== "withdrawn";
      return <li key={mid} className="cat-media-card">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={apiPath("/catalog/media/" + mid + "?size=thumb")} alt={str(m.alt_text) || "Photograph awaiting alt text"} width={Number(m.width) || undefined} height={Number(m.height) || undefined} loading="lazy" />
        <p><Status value={status} />{m.featured ? <span className="status-chip"> Featured</span> : null}</p>
        <p className="hint">{sourceLabels[text(m.source, "")] ?? text(m.source)} · Licence: {text(m.licence_reference)} · Rights holder: {text(m.rights_holder)}</p>
        <AltEditor key={str(m.alt_text)} item={m} locked={locked} onChanged={onChanged} />
        {!locked && <div className="portal-actions">
          <button type="button" className="plain-button" disabled={action.pending || i === 0} onClick={() => reorder(i, -1)}>Move up</button>
          <button type="button" className="plain-button" disabled={action.pending || i === media.length - 1} onClick={() => reorder(i, 1)}>Move down</button>
          {live && !m.featured && <button type="button" className="plain-button" disabled={action.pending} onClick={() => call(() => api("/catalog/media/" + mid, "PATCH", { featured: true }), "Featured photograph set.")}>Set featured</button>}
          {status !== "approved" && live && <button type="button" className="button button-outline" disabled={action.pending || !str(m.alt_text).trim()} onClick={() => call(() => api("/catalog/media/" + mid + "/status", "POST", { status: "approved" }), "Photograph approved.")}>Approve</button>}
          {live && <button type="button" className="button button-outline" disabled={action.pending} onClick={() => call(() => api("/catalog/media/" + mid + "/status", "POST", { status: "withdrawn" }), "Photograph withdrawn.")}>Withdraw</button>}
        </div>}
        {!locked && status !== "approved" && live && !str(m.alt_text).trim() && <p className="hint">Add alt text before approving.</p>}
      </li>;
    })}</ul>}
  </section>;
}

function group(list: Row[], key: (r: Row) => string) { const map = new Map<string, Row[]>(); for (const r of list) { const k = key(r); map.set(k, [...(map.get(k) ?? []), r]); } return [...map.entries()]; }
function Links({ id, garment, catalog, links, onChanged }: { id: string; garment: string; catalog: Row; links: Row; onChanged: () => Promise<void> }) {
  const mats = rows(catalog.materials).filter(m => m.status !== "archived" && ids(m.suitable_garments).includes(garment));
  const opts = rows(catalog.options).filter(o => o.status !== "archived" && o.garment === garment);
  const [mSel, setM] = useState(() => ids(links.material_ids)); const [oSel, setO] = useState(() => ids(links.option_ids)); const action = useAction();
  const toggle = (list: string[], set: (v: string[]) => void, itemId: string, on: boolean) => set(on ? [...list, itemId] : list.filter(x => x !== itemId));
  const box = (r: Row, sel: string[], set: (v: string[]) => void) => <label key={text(r.id)} className="portal-check"><input type="checkbox" checked={sel.includes(text(r.id))} onChange={e => toggle(sel, set, text(r.id), e.target.checked)} /><span>{recordName(r.garment ? "options" : "materials", r)} <Status value={r.status} /></span></label>;
  return <section><h3>Compatible materials and options</h3>
    <p className="hint">Only available entries are shown to customers. Saving replaces the links for this design.</p>
    {mats.length === 0 && opts.length === 0 && <p className="empty-state">No compatible materials or options exist yet for this garment type.</p>}
    {group(mats, m => text(m.kind, "")).map(([kind, list]) => <fieldset key={kind} className="cat-checks"><legend>{kindLabels[kind] ?? humanize(kind)}</legend>{list.map(r => box(r, mSel, setM))}</fieldset>)}
    {group(opts, o => text(o.group_key, "")).map(([g, list]) => <fieldset key={g} className="cat-checks"><legend>{humanize(g)}</legend>{list.map(r => box(r, oSel, setO))}</fieldset>)}
    <button className="button button-dark" type="button" disabled={action.pending} onClick={() => void action.run(async () => { await api("/catalog/products/" + id + "/links", "POST", { material_ids: mSel.filter(x => mats.some(m => m.id === x)), option_ids: oSel.filter(x => opts.some(o => o.id === x)) }); await onChanged(); }, "Links saved.")}>{action.pending ? "Saving…" : "Save links"}</button>
    <Notice error={action.error} message={action.message} /></section>;
}

function Detail({ type, id, catalog, onChanged, onBack }: { type: Kind; id: string; catalog: Row; onChanged: () => void; onBack: () => void }) {
  const resource = useResource<Row>("/catalog/admin/" + type + "/" + id);
  const d = resource.data ? row(resource.data) : null; const record = d ? row(d.record) : null;
  const refreshAll = async () => { onChanged(); await resource.refresh(); };
  const locked = record?.status === "archived"; const preview = d && typeof d.public_preview === "string" ? d.public_preview : "";
  const identity = specs[type].filter(s => s.identity).map(s => s.key);
  return <section className="portal-card enq-detail">
    <button className="button button-outline" type="button" onClick={onBack}>Back to list</button>
    <ResourceState loading={resource.loading && !d} error={resource.error} empty={false} onRetry={resource.refresh} />
    {record && d && <>
      <div className="portal-section-heading"><h3>{recordName(type, record)}</h3><Status value={record.status} /></div>
      <RecordFacts record={record} fields={[...identity, "updated_at"]} />
      {preview && <p><a className="button button-outline" href={preview} target="_blank" rel="noopener noreferrer">Preview public page</a></p>}
      {locked && <p className="portal-notice" role="status">This record is archived and read-only.</p>}
      <h4>Details</h4>
      <RecordForm key={text(record.updated_at)} type={type} record={record} locked={locked} onSaved={refreshAll} />
      <h4>Status</h4>
      {locked ? null : <StatusPanel type={type} id={id} transitions={rows(d.transitions)} onDone={refreshAll} />}
      <MediaManager type={type} id={id} media={rows(d.media)} locked={locked} onChanged={refreshAll} />
      {type === "products" && !locked && <Links key={JSON.stringify(d.links)} id={id} garment={text(record.garment, "")} catalog={catalog} links={row(d.links)} onChanged={refreshAll} />}
    </>}
  </section>;
}

export default function Catalog() {
  const resource = useResource<Row>("/catalog/admin");
  const [kind, setKind] = useState<Kind>("products"); const [open, setOpen] = useState(""); const [creating, setCreating] = useState(false); const [flash, setFlash] = useState("");
  const catalog = resource.data ? row(resource.data) : {};
  const list = rows(catalog[kind]);
  if (open) return <Detail type={kind} id={open} catalog={catalog} onChanged={resource.refresh} onBack={() => setOpen("")} />;
  const meta = (r: Row) => kind === "products" ? text(r.sku) : kind === "materials" ? (kindLabels[text(r.kind, "")] ?? text(r.kind)) + " · " + text(r.supplier_code) : (garmentLabels[text(r.garment, "")] ?? text(r.garment)) + " · " + humanize(text(r.group_key, ""));
  return <section><h2>Catalog</h2><p className="portal-lead">Designs, materials and construction options. Nothing is public until it is verified, photographed with a licensed image and published. Pricing is always quote required.</p>
    <div className="enq-chips" role="group" aria-label="Catalog section">{(["products", "materials", "options"] as Kind[]).map(k => <button key={k} type="button" aria-pressed={kind === k} onClick={() => { setKind(k); setCreating(false); setFlash(""); }}>{k === "products" ? "Designs" : k === "materials" ? "Materials" : "Options"} ({rows(catalog[k]).length})</button>)}</div>
    <button className="button button-dark" type="button" onClick={() => { setCreating(!creating); setFlash(""); }}>{creating ? "Cancel" : "New " + typeNames[kind]}</button>
    {flash && <p className="portal-notice success" role="status">{flash}</p>}
    {creating && <div className="portal-card"><h3>New {typeNames[kind]}</h3><RecordForm type={kind} onSaved={saved => { resource.refresh(); setCreating(false); if (saved.id) setOpen(text(saved.id)); else setFlash("Created."); }} /></div>}
    <ResourceState loading={resource.loading && !resource.data} error={resource.error} empty={false} onRetry={resource.refresh} />
    {!resource.loading && !resource.error && list.length === 0 && <p className="empty-state">No {kind === "products" ? "designs" : kind} yet.</p>}
    <ul className="enq-list">{list.map(r => <li key={text(r.id)}><button type="button" onClick={() => setOpen(text(r.id))}><span className="enq-list-top"><strong>{recordName(kind, r)}</strong><Status value={r.status} /></span><span>{meta(r)}</span><span className="enq-list-meta">Updated {date(r.updated_at)}</span></button></li>)}</ul>
  </section>;
}
