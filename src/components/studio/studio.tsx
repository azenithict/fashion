"use client";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import contract from "../../../shared/contract.json";
import GarmentPreview from "@/components/garment-preview";
import { Photo, EDITORIAL } from "@/components/designs";
import { ActionForm, Field, Notice, value as formValue } from "@/components/workspace/common";
import { SpecFacts } from "@/components/workspace/spec";
import { api, apiPath, ApiError, clearSession, loadSession, row, rows, text, type Row, type User } from "@/lib/api";
import { categoryLabel, garmentLabels, humanize, kindLabels, publicStatusLabels, type ProductCard, type ProductDetail, type PublicMaterial } from "@/lib/catalog";
import { fabricShades, finishes, fits, garmentKinds } from "@/lib/design";
import { buildPayload, contentKey, draftFromRecord, emptyDraft, firstInvalidStep, kindOrder, needsQuantity, readDraft, reconcile, STEP_TITLES, todayIso, validate, writeDraft, type StudioDraft } from "@/lib/studio";

const SKETCH = "Concept sketch — illustrative only, not a production preview";
const COLOUR_NOTE = "Screen colours are approximate; the physical material is confirmed before production.";
const enqCustom = "/enquiry/?service=custom_tailoring";
const hex = (v?: string | null) => (v && /^#[0-9a-fA-F]{6}$/.test(v) ? v : "");

function useFetch<T>(path: string | null) {
  const [state, setState] = useState<{ data: T | null; error: Error | null; loading: boolean }>({ data: null, error: null, loading: Boolean(path) });
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let active = true;
    void Promise.resolve().then(() => { if (!path) { if (active) setState({ data: null, error: null, loading: false }); return null; } if (active) setState(s => ({ ...s, error: null, loading: true })); return api<T>(path); })
      .then(data => { if (active && path) setState({ data, error: null, loading: false }); })
      .catch(cause => { if (active) setState({ data: null, error: cause instanceof Error ? cause : new Error("Unable to load."), loading: false }); });
    return () => { active = false; };
  }, [path, attempt]);
  return { ...state, retry: useCallback(() => setAttempt(n => n + 1), []) };
}

function Thumb({ media }: { media: ProductCard["featured_media"] & object }) {
  // eslint-disable-next-line @next/next/no-img-element
  return <img src={apiPath("/catalog/media/" + encodeURIComponent(media.id) + "?size=thumb")} alt={media.alt_text} width={media.width} height={media.height} loading="lazy" />;
}
function Choice({ name, checked, onChange, children }: { name: string; checked: boolean; onChange: () => void; children: ReactNode }) {
  return <label className={"sz-choice" + (checked ? " is-on" : "")}><input type="radio" name={name} checked={checked} onChange={onChange} /><span className="sz-choice-body">{children}</span></label>;
}
function Err({ id, message }: { id: string; message?: string }) { return message ? <p className="sz-error" id={id} role="alert">{message}</p> : null; }

function SignInPanel({ onSignedIn }: { onSignedIn: () => void }) {
  const [mode, setMode] = useState<"login" | "register">("login");
  async function go(path: string, payload: unknown) { await api(path, "POST", payload); clearSession(); await loadSession(); onSignedIn(); }
  return <section className="sz-signin" aria-label="Sign in or create an account">
    <h3>{mode === "login" ? "Sign in to save your design" : "Create an account to save your design"}</h3>
    <p className="hint">Your choices are kept on this device and will not be lost. Signing in lets us save them privately to your account.</p>
    <div className="segmented-options" role="group" aria-label="Account action"><button type="button" aria-pressed={mode === "login"} className={mode === "login" ? "selected" : ""} onClick={() => setMode("login")}>Sign in</button><button type="button" aria-pressed={mode === "register"} className={mode === "register" ? "selected" : ""} onClick={() => setMode("register")}>Register</button></div>
    {mode === "login"
      ? <ActionForm submitLabel="Sign in" success="Signed in. You can now save your design." onSubmit={d => go("/login", { email: formValue(d, "email"), password: String(d.get("password") ?? "") })}><Field title="Email address" name="email" type="email" /><Field title="Password" name="password" type="password" /></ActionForm>
      : <ActionForm submitLabel="Create account" success="Account created. Check your email to verify your address before submitting." onSubmit={d => go("/register", { name: formValue(d, "name"), email: formValue(d, "email"), password: String(d.get("password") ?? "") })}><Field title="Your name" name="name" /><Field title="Email address" name="email" type="email" /><Field title="Password (at least 12 characters)" name="password" type="password" /></ActionForm>}
    <p className="hint"><Link className="text-link" href={"/account/?next=" + encodeURIComponent("/studio/")}>Use the full account page</Link> (forgotten password, email verification). You will be able to return here.</p>
  </section>;
}

export default function Studio() {
  const params = useSearchParams(); const router = useRouter();
  const designParam = params.get("design") ?? ""; const productParam = params.get("product") ?? "";
  const [draft, setDraft] = useState<StudioDraft>(emptyDraft); const draftRef = useRef(draft);
  const [ready, setReady] = useState(false); const [locked, setLocked] = useState<Row | null>(null); const [reloads, setReloads] = useState(0);
  const [user, setUser] = useState<User | null>(null); const [needSignIn, setNeedSignIn] = useState(false);
  const [attempted, setAttempted] = useState<number[]>([]); const [busy, setBusy] = useState(false); const [error, setError] = useState(""); const [message, setMessage] = useState(""); const [unverified, setUnverified] = useState(false);
  const [issues, setIssues] = useState<string[]>([]); const [uploads, setUploads] = useState<Row[]>([]); const [done, setDone] = useState(false); const [notice, setNotice] = useState("");
  const headingRef = useRef<HTMLHeadingElement>(null); const focusReady = useRef(false);
  const update = (patch: Partial<StudioDraft>) => setDraft(d => ({ ...d, ...patch }));
  const step = draft.step;
  const dirty = ready && !done && !locked && draft.path !== "" && contentKey(draft) !== draft.saved;

  const productPath = draft.path === "catalog" && draft.slug ? "/catalog/products/" + encodeURIComponent(draft.slug) : null;
  const productRes = useFetch<ProductDetail>(productPath); const product = productRes.data;
  const listRes = useFetch<ProductCard[]>(step === 1 ? "/catalog/products" : null);
  const garment = product?.garment ?? draft.garment;
  const measurementsRes = useFetch<Row[]>(user && step >= 5 ? "/measurements" : null);
  const profiles = (Array.isArray(measurementsRes.data) ? measurementsRes.data : []).filter(m => m.garment === garment);

  useEffect(() => { draftRef.current = draft; });
  // Initial load: server design, ?product=, or the draft kept on this device.
  useEffect(() => {
    let active = true;
    void Promise.resolve().then(async () => {
      const local = readDraft(); const cur = draftRef.current;
      try { const s = await loadSession(); if (active) setUser(s.user); } catch { /* offline: keep working locally */ }
      if (designParam) {
        if (designParam === cur.designId && !locked) return;
        if (local?.designId === designParam && contentKey(local) !== local.saved) { if (active) { setDraft(local); setLocked(null); } return; }
        const record = await api<Row>("/designs/" + encodeURIComponent(designParam));
        if (!active) return;
        setUploads(rows(record.uploads)); setIssues(Array.isArray(record.availability_issues) ? record.availability_issues.map(i => text(i)) : []);
        if (["draft", "revision_requested"].includes(text(record.status))) { setLocked(null); setDraft(draftFromRecord(record)); } else setLocked(record);
      } else if (productParam) {
        if (active) { setLocked(null); setDraft(local?.path === "catalog" && local.slug === productParam ? local : { ...emptyDraft(), path: "catalog", slug: productParam, step: 2, maxStep: 2 }); }
      } else if (!ready && local && active) setDraft(local);
    }).catch(cause => { if (!active) return; if (cause instanceof ApiError && cause.status === 401) { setNeedSignIn(true); setError("Sign in to open this design."); } else setError(cause instanceof Error ? cause.message : "Unable to open this design."); })
      .finally(() => { if (active) setReady(true); });
    return () => { active = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [designParam, productParam, reloads]);
  // Keep the draft on this device (guest-safe).
  useEffect(() => { if (ready && !locked && !done) writeDraft(draft); }, [draft, ready, locked, done]);
  // Tidy selections against the live catalogue and default the design name.
  useEffect(() => {
    if (!product) return; let active = true;
    void Promise.resolve().then(() => {
      if (!active) return; const r = reconcile(draftRef.current, product);
      if (r.removed.length) setNotice("Some earlier choices are no longer available and were removed: " + r.removed.map(x => humanize(x.label)).join(", ") + ". Please review them.");
      setDraft(d => { const base = r.removed.length ? { ...d, materials: r.draft.materials, options: r.draft.options } : d; return base.name ? base : { ...base, name: product.name }; });
    });
    return () => { active = false; };
  }, [product]);
  useEffect(() => { if (!focusReady.current) { focusReady.current = true; return; } headingRef.current?.focus(); }, [step, done]);
  useEffect(() => {
    if (!dirty) return; const warn = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = ""; };
    window.addEventListener("beforeunload", warn); return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  const errors = validate(draft, product, step); const show = attempted.includes(step);
  const goto = (n: number) => { setError(""); setMessage(""); update({ step: n, maxStep: Math.max(draft.maxStep, n) }); };
  const next = () => { if (Object.keys(errors).length) { setAttempted(a => [...new Set([...a, step])]); return; } goto(step + 1); };
  const afterAuth = async () => { setNeedSignIn(false); setError(""); setMessage("Signed in. Continue below."); try { setUser((await loadSession()).user); } catch { /* ignore */ } setReloads(n => n + (designParam ? 1 : 0)); };

  function fail(cause: unknown) {
    const code = cause instanceof ApiError ? cause.code : ""; const msg = cause instanceof Error ? cause.message : "The request could not be completed.";
    if (cause instanceof ApiError && cause.status === 401) { setNeedSignIn(true); setError("Sign in to save your design. Your choices are kept on this device."); }
    else if (code === "verification_required") { setUnverified(true); setError("Verify your email first. Your draft is saved; once your email is verified you can submit it."); }
    else setError(msg);
  }
  async function recoverUnavailable(msg: string) {
    setIssues([msg]);
    try {
      const fresh = await api<ProductDetail>("/catalog/products/" + encodeURIComponent(draft.slug)); const r = reconcile(draft, fresh);
      const target = r.removed.some(x => x.kind === "material") ? 2 : r.removed.length ? 3 : 6;
      setDraft({ ...r.draft, step: target, maxStep: Math.max(draft.maxStep, target) }); productRes.retry();
      setError(msg + (r.removed.length ? " The unavailable choice has been removed; please choose another." : ""));
    } catch { setError(msg); }
  }
  async function save(): Promise<string> {
    const bad = firstInvalidStep(draft, product); if (bad) { setAttempted(a => [...new Set([...a, bad])]); goto(bad); throw new Error("Complete the highlighted details before saving."); }
    if (draft.designId && contentKey(draft) === draft.saved) return draft.designId;
    if (!(await loadSession(true)).user) { setNeedSignIn(true); throw new ApiError("Sign in to save your design. Your choices are kept on this device.", 401); }
    const record = await api<Row>(draft.designId ? "/designs/" + draft.designId : "/designs", draft.designId ? "PATCH" : "POST", buildPayload(draft, product));
    const id = text(record.id); const key = contentKey(draft);
    setDraft(d => ({ ...d, designId: id, saved: key })); setIssues(Array.isArray(record.availability_issues) ? record.availability_issues.map(i => text(i)) : []); setUploads(rows(record.uploads));
    if (id !== designParam) router.replace("/studio/?design=" + encodeURIComponent(id));
    return id;
  }
  async function run(task: () => Promise<void>) { setBusy(true); setError(""); setMessage(""); setUnverified(false); try { await task(); } catch (cause) { fail(cause); } finally { setBusy(false); } }
  const saveDraft = () => run(async () => { await save(); setMessage("Draft saved privately to your account."); });
  const submit = () => run(async () => {
    if (!draft.measurementId) throw new Error("Choose a measurement profile to submit, or save the draft and complete measurements later.");
    const id = await save();
    try { await api("/designs/" + id + "/submit", "POST", { measurement_id: draft.measurementId }); }
    catch (cause) { if (cause instanceof ApiError && cause.code === "selection_unavailable") { await recoverUnavailable(cause.message); return; } throw cause; }
    writeDraft(null); setDone(true);
  });
  async function refreshUploads(id: string) { try { setUploads(rows((await api<Row>("/designs/" + id)).uploads)); } catch { /* list refresh is best effort */ } }

  if (!ready) return <p role="status" className="sz-status">Opening your studio…</p>;
  if (done) return <section className="sz-done" aria-labelledby="sz-h"><h2 id="sz-h" ref={headingRef} tabIndex={-1}>Your design has been submitted</h2><p>It is now with our team for professional review. Nothing has been priced or confirmed yet.</p><ol><li>We review feasibility, your reference images and your measurements.</li><li>If approved, you receive a written quote with price and timing to review.</li><li>Production starts only after you approve the quote.</li></ol><div className="portal-actions"><Link className="button button-dark" href="/workspace/?tab=designs">View in your workspace</Link><Link className="button button-outline" href="/designs/">Browse designs</Link></div></section>;
  if (locked) return <section className="sz-locked"><h2 ref={headingRef} tabIndex={-1}>{text(locked.name, "Submitted design")}</h2><p className="portal-notice">This design has been submitted and can no longer be edited. Duplicate it to start a new draft from the same choices.</p><SpecFacts spec={row(locked.specification)} /><Notice error={error} /><div className="portal-actions"><button type="button" className="button button-dark" disabled={busy} onClick={() => run(async () => { const copy = await api<Row>("/designs/" + text(locked.id) + "/duplicate", "POST"); setLocked(null); router.push("/studio/?design=" + encodeURIComponent(text(copy.id))); })}>{busy ? "Duplicating…" : "Duplicate to edit"}</button><Link className="button button-outline" href="/workspace/?tab=designs">Back to your designs</Link></div></section>;
  if (error && designParam && !draft.designId) return <section className="sz-locked"><h2>We could not open this design</h2><Notice error={error} />{needSignIn && <SignInPanel onSignedIn={() => { setError(""); setReloads(n => n + 1); }} />}<Link className="button button-outline" href="/studio/">Start a new design</Link></section>;

  const steps = STEP_TITLES.map((t, i) => (draft.path === "concept" && i === 1 ? "Concept" : draft.path === "concept" && i === 2 ? "Fit and finish" : t));
  const matLabel = (kind: string) => product?.materials.find(m => m.id === draft.materials[kind] && m.kind === kind)?.name ?? "";
  const optLabel = (g: string) => product?.options.find(o => o.id === draft.options[g] && o.group === g)?.label ?? "";
  const profile = profiles.find(p => text(p.id) === draft.measurementId);
  const blocked = issues.length > 0;

  const chooseProduct = (p: ProductCard) => update(draft.slug === p.slug && draft.path === "catalog" ? { step: 2, maxStep: Math.max(draft.maxStep, 2) } : { path: "catalog", slug: p.slug, garment: p.garment, designId: "", name: "", materials: {}, options: {}, fit: "", occasion: "", deadline: "", quantity: "", measurementId: "", step: 2, maxStep: 2, saved: "" });
  const chooseConcept = () => update(draft.path === "concept" ? { step: 2, maxStep: Math.max(draft.maxStep, 2) } : { path: "concept", slug: "", designId: "", materials: {}, options: {}, occasion: "", deadline: "", quantity: "", measurementId: "", name: "", step: 2, maxStep: 2, saved: "" });

  const MaterialCard = ({ m }: { m: PublicMaterial }) => {
    const facts = [m.composition, m.colour_name, m.weight_gsm ? m.weight_gsm + " gsm" : "", m.weave, m.pattern].filter(Boolean).join(" · "); const sw = hex(m.colour_hex); const photo = m.media[0];
    return <Choice name={"mat-" + m.kind} checked={draft.materials[m.kind] === m.id} onChange={() => update({ materials: { ...draft.materials, [m.kind]: m.id } })}>
      {photo ? <Photo media={photo} size="thumb" /> : <span className="dz-noimg">Photograph in preparation</span>}
      <strong>{m.name}</strong>{facts && <span className="sz-sub">{facts}</span>}
      {sw && <span className="sz-sub"><span className="dz-chip" style={{ background: sw }} aria-hidden="true" /> Approximate on-screen colour</span>}
      {photo?.editorial && <span className="dz-editorial">{EDITORIAL}</span>}
    </Choice>;
  };

  const stepBody = () => {
    if (step === 1) return <>
      {listRes.loading && <p role="status">Loading designs…</p>}
      {listRes.error && <div className="portal-notice error" role="alert"><p>We could not load our designs just now.</p><button type="button" className="button button-outline" onClick={listRes.retry}>Try again</button></div>}
      {!listRes.loading && !listRes.error && (listRes.data?.length ?? 0) === 0 && <p className="portal-notice">No designs are published yet — our first verified designs are still being prepared. You can start from your own idea below, or <Link className="text-link" href={enqCustom}>send a custom tailoring enquiry</Link>.</p>}
      <ul className="sz-products">
        {(Array.isArray(listRes.data) ? listRes.data : []).map(p => <li key={p.slug} className="dz-card"><div className="dz-card-media">{p.featured_media ? <Thumb media={p.featured_media} /> : <span className="dz-noimg">Photograph in preparation</span>}</div><div className="dz-card-body"><p className="dz-cat">{categoryLabel(p.category)}</p><h3>{p.name}</h3><p><span className={"dz-badge" + (p.status === "available_to_order" ? " dz-badge-on" : "")}>{publicStatusLabels[p.status] ?? humanize(p.status)}</span></p><button type="button" className="button button-dark" onClick={() => chooseProduct(p)} aria-label={"Customize " + p.name}>Customize this design</button></div></li>)}
        <li className="dz-card"><div className="dz-card-body"><p className="dz-cat">Your own idea</p><h3>Start from your own idea</h3><p>Describe a garment of your own. This path is a concept sketch only; a professional will review feasibility.</p><button type="button" className="button button-outline" onClick={chooseConcept}>Start from your own idea</button></div></li>
      </ul><Err id="e-path" message={show ? errors.path : ""} /></>;

    if (draft.path === "concept" && step === 2) return <>
      <p className="sz-sketch" role="note">{SKETCH}</p>
      <fieldset className="sz-group"><legend>Garment</legend><div className="sz-choices">{garmentKinds.map(g => <Choice key={g.id} name="cg" checked={draft.garment === g.id} onChange={() => update({ garment: g.id, measurementId: "" })}><strong>{g.title}</strong><span className="sz-sub">{g.desc}</span></Choice>)}</div><Err id="e-garment" message={show ? errors.garment : ""} /></fieldset>
      <fieldset className="sz-group"><legend>Colour direction</legend><p className="hint">Visual references only; final fabrics and colourways require sampling.</p><div className="sz-choices">{fabricShades.map(s => <Choice key={s.id} name="cs" checked={draft.shade === s.id} onChange={() => update({ shade: s.id })}><span className="dz-chip" style={{ background: s.hex }} aria-hidden="true" /> <strong>{s.label}</strong></Choice>)}</div><Err id="e-shade" message={show ? errors.shade : ""} /></fieldset>
      <div className="studio-preview-figure sz-sketch-figure"><GarmentPreview garment={draft.garment as never} shade={(draft.shade || "forest") as never} finish={(draft.finish || "Minimal") as never} /><p className="sz-sketch">{SKETCH}</p></div></>;
    if (draft.path === "concept" && step === 3) return <>
      <p className="sz-sketch" role="note">{SKETCH}</p>
      <fieldset className="sz-group"><legend>Fit direction</legend><div className="sz-choices">{fits.map(f => <Choice key={f} name="cf" checked={draft.fit === f} onChange={() => update({ fit: f })}><strong>{f}</strong></Choice>)}</div><p className="hint">Fit preferences are guidance, not a replacement for professional measurements.</p><Err id="e-fit" message={show ? errors.fit : ""} /></fieldset>
      <fieldset className="sz-group"><legend>Finishing detail</legend><div className="sz-choices">{finishes.map(f => <Choice key={f} name="cd" checked={draft.finish === f} onChange={() => update({ finish: f })}><strong>{f}</strong></Choice>)}</div><Err id="e-finish" message={show ? errors.finish : ""} /></fieldset></>;

    if (!product) return productRes.loading ? <p role="status">Loading design…</p> : <div className="portal-notice error" role="alert"><p>{productRes.error instanceof ApiError && productRes.error.status === 404 ? "This design is no longer available." : "We could not load this design just now."}</p><button type="button" className="button button-outline" onClick={productRes.retry}>Try again</button> <button type="button" className="button button-outline" onClick={() => update({ step: 1, path: "", slug: "" })}>Choose another design</button></div>;

    if (step === 2) return <>
      <p className="hint">{COLOUR_NOTE}</p>
      {product.materials.length === 0 && <p className="portal-notice">No materials are currently listed for this design. You can continue and our team will confirm materials during review.</p>}
      {kindOrder(product.materials.map(m => m.kind)).map(kind => { const list = product.materials.filter(m => m.kind === kind); const required = kind === "fabric"; const sel = list.find(m => m.id === draft.materials[kind]); const photo = sel?.media[0];
        return <fieldset className="sz-group" key={kind} aria-describedby={required && show && errors.fabric ? "e-fabric" : undefined}><legend>{kindLabels[kind] ?? humanize(kind)}{required ? " (required)" : " (optional)"}</legend>
          <div className="sz-choices">{list.map(m => <MaterialCard key={m.id} m={m} />)}{!required && <Choice name={"mat-" + kind} checked={!draft.materials[kind]} onChange={() => { const m = { ...draft.materials }; delete m[kind]; update({ materials: m }); }}><strong>No {(kindLabels[kind] ?? humanize(kind)).toLowerCase()} selection</strong><span className="sz-sub">Decide later with our team</span></Choice>}</div>
          {required && <Err id="e-fabric" message={show ? errors.fabric : ""} />}
          {sel && photo && <figure className="sz-selected"><Photo media={photo} size="display" /><figcaption>Selected {(kindLabels[kind] ?? kind).toLowerCase()}: {sel.name}</figcaption></figure>}
        </fieldset>; })}</>;
    if (step === 3) { const groups = [...new Set(product.options.map(o => o.group))]; return <>
      {groups.length === 0 && <p className="portal-notice">No construction options are listed for this design. Continue to add your requirements.</p>}
      {groups.map(g => <fieldset className="sz-group" key={g}><legend>{humanize(g)} (optional)</legend><div className="sz-choices">{product.options.filter(o => o.group === g).map(o => <Choice key={o.id} name={"opt-" + g} checked={draft.options[g] === o.id} onChange={() => update({ options: { ...draft.options, [g]: o.id } })}>{o.media[0] && <Photo media={o.media[0]} size="thumb" />}<strong>{o.label}</strong>{o.description && <span className="sz-sub">{o.description}</span>}</Choice>)}<Choice name={"opt-" + g} checked={!draft.options[g]} onChange={() => { const m = { ...draft.options }; delete m[g]; update({ options: m }); }}><strong>No preference</strong></Choice></div></fieldset>)}
      <div className="portal-field"><label htmlFor="sz-fit"><span>Fit <small>(optional)</small></span></label><select id="sz-fit" value={draft.fit} onChange={e => update({ fit: e.target.value })}><option value="">No preference</option>{contract.fits.map(f => <option key={f} value={f}>{f}</option>)}</select><p className="hint">Fit preferences are guidance, not a replacement for professional measurements.</p></div></>; }
    return null;
  };

  const requirements = <>
    <div className="portal-field"><label htmlFor="sz-name"><span>Design name</span></label><input id="sz-name" maxLength={120} value={draft.name} aria-invalid={show && Boolean(errors.name)} aria-describedby="e-name" onChange={e => update({ name: e.target.value })} /><Err id="e-name" message={show ? errors.name : ""} /></div>
    {draft.path === "catalog" && <>
      <div className="portal-field"><label htmlFor="sz-occ"><span>Occasion <small>(optional)</small></span></label><input id="sz-occ" maxLength={160} value={draft.occasion} aria-describedby="e-occ" onChange={e => update({ occasion: e.target.value })} /><p className="hint">{draft.occasion.length}/160</p><Err id="e-occ" message={show ? errors.occasion : ""} /></div>
      <div className="portal-field"><label htmlFor="sz-date"><span>Date you need it by <small>(optional)</small></span></label><input id="sz-date" type="date" min={todayIso()} value={draft.deadline} aria-describedby="e-date" onChange={e => update({ deadline: e.target.value })} /><p className="hint">A wished-for date, not a promise. Timing is confirmed in your written quote.</p><Err id="e-date" message={show ? errors.deadline : ""} /></div>
      {needsQuantity(product) && <div className="portal-field"><label htmlFor="sz-qty"><span>Quantity</span></label><input id="sz-qty" type="number" inputMode="numeric" min={1} max={10000} step={1} value={draft.quantity} aria-describedby="e-qty" onChange={e => update({ quantity: e.target.value })} />{product?.min_order_quantity ? <p className="hint">Minimum order quantity for this design: {product.min_order_quantity}.</p> : null}<Err id="e-qty" message={show ? errors.quantity : ""} /></div>}</>}
    <div className="portal-field"><label htmlFor="sz-notes"><span>Notes <small>(optional)</small></span></label><textarea id="sz-notes" rows={4} maxLength={800} value={draft.notes} aria-describedby="e-notes" onChange={e => update({ notes: e.target.value })} placeholder="Inspiration, special requirements… No body measurements or sensitive information." /><p className="hint">{draft.notes.length}/800</p><Err id="e-notes" message={show ? errors.notes : ""} /></div>
    <section className="sz-uploads" aria-labelledby="sz-ref"><h3 id="sz-ref">Reference images</h3>
      {draft.designId ? <>
        <ActionForm submitLabel="Upload private reference" success="Reference uploaded privately." onSubmit={async d => { const f = d.get("file"); if (!(f instanceof File) || !["image/jpeg", "image/png", "application/pdf"].includes(f.type)) throw new Error("Choose a JPEG, PNG or PDF file."); if (f.size > 8 * 1024 * 1024) throw new Error("Files must be 8 MB or smaller."); d.set("design_id", draft.designId); await api("/uploads", "POST", d); await refreshUploads(draft.designId); }}><label className="portal-field"><span>JPEG, PNG or PDF, up to 8 MB</span><input required type="file" name="file" accept="image/jpeg,image/png,application/pdf" /></label><p className="hint">Use images you have permission to share. References guide the review; they do not guarantee manufacturing feasibility.</p></ActionForm>
        {uploads.length > 0 && <ul>{uploads.map(u => <li key={text(u.id)}><a className="text-link" href={apiPath("/uploads/" + encodeURIComponent(text(u.id)))} target="_blank" rel="noreferrer">{text(u.original_name, "Reference attachment")} ↗</a></li>)}</ul>}</>
        : <p className="hint">Reference images can be added once your draft is saved. Save the draft from the review step, or <button type="button" className="plain-button" disabled={busy} onClick={saveDraft}>save it now</button>.</p>}
    </section></>;

  const measurementsBody = <>
    <p className="hint">Measurements you enter yourself are marked as self-entered and are reviewed professionally before production. Nothing is cut until they are confirmed.</p>
    {!user ? <><p className="portal-notice">Sign in to choose a saved measurement profile.</p><SignInPanel onSignedIn={afterAuth} /></> : <>
      {measurementsRes.loading && <p role="status">Loading your measurement profiles…</p>}
      {measurementsRes.error && <div className="portal-notice error" role="alert"><p>We could not load your measurement profiles.</p><button type="button" className="button button-outline" onClick={measurementsRes.retry}>Try again</button></div>}
      {!measurementsRes.loading && !measurementsRes.error && profiles.length === 0 && <p className="portal-notice">You have no saved measurement profile for this garment yet.</p>}
      {profiles.length > 0 && <fieldset className="sz-group"><legend>Measurement profile for {garmentLabels[garment] ?? humanize(garment)}</legend><div className="sz-choices">{profiles.map(m => <Choice key={text(m.id)} name="meas" checked={draft.measurementId === text(m.id)} onChange={() => update({ measurementId: text(m.id) })}><strong>{text(m.name)}</strong><span className="sz-sub">{text(m.unit)} · {m.verified_at ? "Professionally verified" : "Self-entered, awaiting verification"}</span></Choice>)}</div></fieldset>}
      <p><Link className="text-link" href="/workspace/?tab=measurements" target="_blank" rel="noreferrer">Add a measurement profile ↗</Link> <button type="button" className="plain-button" onClick={measurementsRes.retry}>Refresh profiles</button></p></>}
    <p><button type="button" className="button button-outline" onClick={() => { update({ measurementId: "" }); goto(6); }}>Complete measurements later</button></p>
    <p className="hint">Your draft stays saved. You can add measurements before submitting.</p></>;

  const summary: [string, string, number][] = draft.path === "concept"
    ? [["Design name", draft.name, 4], ["Garment", garmentKinds.find(g => g.id === draft.garment)?.title ?? draft.garment, 2], ["Colour direction", fabricShades.find(s => s.id === draft.shade)?.label ?? "", 2], ["Fit", draft.fit, 3], ["Finishing detail", draft.finish, 3], ["Notes", draft.notes.trim(), 4]]
    : [["Design name", draft.name, 4], ["Design", product?.name ?? "", 1], ...Object.keys(draft.materials).map(k => [kindLabels[k] ?? humanize(k), matLabel(k), 2] as [string, string, number]), ...Object.keys(draft.options).map(g => [humanize(g), optLabel(g), 3] as [string, string, number]), ["Fit", draft.fit, 3], ["Occasion", draft.occasion.trim(), 4], ["Needed by", draft.deadline, 4], ["Quantity", needsQuantity(product) ? draft.quantity : "", 4], ["Notes", draft.notes.trim(), 4]];
  const review = <>
    {draft.path === "concept" && <p className="sz-sketch" role="note">{SKETCH}</p>}
    {blocked && <div className="portal-notice error sz-issues" role="alert"><p><strong>This design cannot be submitted yet.</strong></p><ul>{issues.map((i, k) => <li key={k}>{i}</li>)}</ul><p>Change the affected choice in <button type="button" className="plain-button" onClick={() => goto(2)}>Materials</button> or <button type="button" className="plain-button" onClick={() => goto(3)}>Construction</button>, then save again.</p></div>}
    <dl className="record-facts sz-summary">{summary.filter(([, v]) => v).map(([k, v, s]) => <div key={k}><dt>{k}</dt><dd>{v} <button type="button" className="plain-button" onClick={() => goto(s)} aria-label={"Change " + k.toLowerCase()}>Change</button></dd></div>)}
      <div><dt>Measurement profile</dt><dd>{profile ? text(profile.name) + (profile.verified_at ? " (verified)" : " (self-entered, awaiting review)") : "Not chosen yet — required to submit"} <button type="button" className="plain-button" onClick={() => goto(5)}>Change</button></dd></div>
      <div><dt>Reference images</dt><dd>{uploads.length ? uploads.map(u => text(u.original_name, "Attachment")).join(", ") : draft.designId ? "None added" : "Available once the draft is saved"}</dd></div></dl>
    <p className="hint">Submitting sends this specification for professional review. It is not an order and no price is set until you receive and approve a written quote.</p>
    {unverified && <p className="portal-notice">Verify your email first. Open the link in your verification email, or request another from <Link className="text-link" href="/workspace/">your workspace</Link>.</p>}
    <div className="portal-actions"><button type="button" className="button button-outline" disabled={busy} onClick={saveDraft}>{busy ? "Working…" : "Save draft"}</button><button type="button" className="button button-dark" disabled={busy || blocked} aria-disabled={busy || blocked} onClick={submit}>Submit for professional review</button></div>
    {!draft.measurementId && !blocked && <p className="hint">Choose a measurement profile in step 5 to enable submission, or save the draft and finish later.</p>}</>;

  return <div className="sz">
    <nav aria-label="Studio progress"><ol className="sz-steps">{steps.map((t, i) => { const n = i + 1; const open = draft.path !== "" && n <= draft.maxStep; return <li key={t}><button type="button" disabled={!open} aria-current={n === step ? "step" : undefined} className={n === step ? "is-current" : n < step ? "is-done" : ""} onClick={() => goto(n)}><span aria-hidden="true">{n < step ? "✓" : n}</span><span className="sz-step-title">{t}</span><span className="sr-only">{n < step ? " (completed)" : n === step ? " (current step)" : ""}</span></button></li>; })}</ol></nav>
    <section className="sz-panel" aria-labelledby="sz-heading">
      <p className="eyebrow">Step {step} of 6</p><h2 id="sz-heading" ref={headingRef} tabIndex={-1}>{steps[step - 1]}</h2>
      {draft.path === "catalog" && product && step > 1 && <p className="hint">Customizing: <strong>{product.name}</strong> · <button type="button" className="plain-button" onClick={() => goto(1)}>Choose a different design</button></p>}
      {notice && <p className="portal-notice" role="status">{notice}</p>}
      {step <= 3 ? stepBody() : step === 4 ? requirements : step === 5 ? measurementsBody : review}
      <Notice error={error} message={message} />
      {needSignIn && step !== 5 && <SignInPanel onSignedIn={afterAuth} />}
      {step > 1 && step < 6 && <div className="sz-nav"><button type="button" className="button button-outline" onClick={() => goto(step - 1)}>Back</button>{step !== 5 && <button type="button" className="button button-dark" onClick={next}>Next</button>}{step === 5 && <button type="button" className="button button-dark" onClick={() => goto(6)}>Next</button>}</div>}
      {step === 6 && <div className="sz-nav"><button type="button" className="button button-outline" onClick={() => goto(5)}>Back</button></div>}
      {dirty && <p className="hint" role="status">Your choices are kept on this device. {draft.designId ? "You have unsaved changes." : "Not yet saved to your account."}</p>}
    </section>
  </div>;
}
