"use client";
import Link from "next/link";
import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { useSearchParams } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { buildEnquiryPayload, enquiryServiceLabels, enquiryServices, isEnquiryService, validateEnquiry, type EnquiryErrors, type EnquiryForm } from "@/lib/enquiry";

const DRAFT = "azf-enquiry-draft";
type Done = { reference: string; email: string };
const clip = (v: string | null) => (v ?? "").slice(0, 120);

export default function EnquiryFormView() {
  const params = useSearchParams();
  const initial = useMemo<EnquiryForm>(() => {
    const s = params.get("service");
    return { service: isEnquiryService(s) ? s : "custom_tailoring", name: "", email: "", contact_method: "email", phone: "", garment: clip(params.get("garment")), quantity: "", deadline: "", location: "", requirements: "", consent: false };
  }, [params]);
  const [form, setForm] = useState<EnquiryForm>(initial);
  const [errors, setErrors] = useState<EnquiryErrors>({});
  const [honey, setHoney] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");
  const [done, setDone] = useState<Done | null>(null);
  const restored = useRef(false);
  const heading = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    if (restored.current) return; restored.current = true;
    try {
      const raw = sessionStorage.getItem(DRAFT); if (!raw) return;
      const d = JSON.parse(raw) as Partial<EnquiryForm>;
      const hasService = isEnquiryService(params.get("service")); const hasGarment = !!params.get("garment");
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setForm(f => ({ ...f, ...d, consent: false, service: hasService ? f.service : isEnquiryService(d.service ?? null) ? d.service as EnquiryForm["service"] : f.service, garment: hasGarment ? f.garment : String(d.garment ?? "") }));
    } catch { /* ignore */ }
  }, [params]);
  useEffect(() => {
    if (!restored.current || done) return;
    try { const { consent: _c, ...rest } = form; void _c; sessionStorage.setItem(DRAFT, JSON.stringify(rest)); } catch { /* ignore */ }
  }, [form, done]);
  useEffect(() => { if (done) heading.current?.focus(); }, [done]);

  const set = <K extends keyof EnquiryForm>(k: K, v: EnquiryForm[K]) => setForm(f => ({ ...f, [k]: v }));
  const err = (k: keyof EnquiryForm) => errors[k] ? { "aria-invalid": true as const, "aria-describedby": "e-" + k } : {};
  const msg = (k: keyof EnquiryForm) => errors[k] ? <p className="enq-err" id={"e-" + k}>{errors[k]}</p> : null;

  async function submit(ev: FormEvent) {
    ev.preventDefault(); if (sending) return;
    const found = validateEnquiry(form); setErrors(found); setError("");
    const first = (["name", "email", "phone", "quantity", "requirements", "consent"] as const).find(k => found[k]);
    if (first) { document.getElementById("f-" + first)?.focus(); return; }
    const sp = new URLSearchParams(window.location.search); const source: Record<string, string> = {};
    for (const k of ["utm_source", "utm_medium", "utm_campaign"]) { const v = clip(sp.get(k)); if (v) source[k] = v; }
    source.landing = "/enquiry/";
    try { if (document.referrer) { const u = new URL(document.referrer); if (u.origin === window.location.origin) { const r = (u.pathname + u.search).slice(0, 120); if (r) source.referrer = r; } } } catch { /* ignore */ }
    setSending(true);
    try {
      const res = await api<{ reference: string; email?: string }>("/enquiries", "POST", buildEnquiryPayload(form, honey, source));
      try { sessionStorage.removeItem(DRAFT); } catch { /* ignore */ }
      setDone({ reference: res.reference, email: res.email || form.email.trim() });
    } catch (c) { setError(c instanceof ApiError || c instanceof Error ? c.message : "Your request could not be sent. Please try again."); }
    finally { setSending(false); }
  }

  if (done) return <section className="enq-done" role="status">
    <h2 tabIndex={-1} ref={heading}>Request received</h2>
    <p className="enq-ref"><span>Your reference</span><strong>{done.reference}</strong></p>
    <p>We&apos;ve recorded your request. A confirmation email to {done.email} has been queued. A member of our team will review it and reply by your preferred contact method. This is not a quotation or order — nothing is charged.</p>
    <div className="hm-actions"><Link className="hm-btn hm-btn-primary" href="/">Back to home</Link><button type="button" className="hm-btn hm-btn-secondary" onClick={() => { setDone(null); setForm({ ...initial, consent: false }); setErrors({}); }}>Submit another request</button></div>
  </section>;

  const count = form.requirements.length;
  return <form className="enq-form" onSubmit={submit} noValidate>
    <div className="enq-field"><label htmlFor="f-service">What can we help with?</label>
      <select id="f-service" value={form.service} onChange={e => set("service", e.target.value as EnquiryForm["service"])}>{enquiryServices.map(s => <option key={s} value={s}>{enquiryServiceLabels[s]}</option>)}</select></div>
    <div className="enq-row">
      <div className="enq-field"><label htmlFor="f-name">Name</label><input id="f-name" autoComplete="name" maxLength={100} value={form.name} onChange={e => set("name", e.target.value)} {...err("name")} />{msg("name")}</div>
      <div className="enq-field"><label htmlFor="f-email">Email</label><input id="f-email" type="email" autoComplete="email" maxLength={254} value={form.email} onChange={e => set("email", e.target.value)} {...err("email")} />{msg("email")}</div>
    </div>
    <fieldset className="enq-field enq-radios"><legend>Preferred contact method</legend>
      {(["email", "phone"] as const).map(m => <label key={m} className="enq-radio"><input type="radio" name="contact_method" value={m} checked={form.contact_method === m} onChange={() => set("contact_method", m)} />{m === "email" ? "Email" : "Phone"}</label>)}</fieldset>
    {form.contact_method === "phone" && <div className="enq-field"><label htmlFor="f-phone">Phone number</label><input id="f-phone" type="tel" autoComplete="tel" maxLength={40} value={form.phone} onChange={e => set("phone", e.target.value)} {...err("phone")} />{msg("phone")}</div>}
    <div className="enq-row">
      <div className="enq-field"><label htmlFor="f-garment">Garment or occasion <small>(optional)</small></label><input id="f-garment" maxLength={120} value={form.garment} onChange={e => set("garment", e.target.value)} /></div>
      {form.service === "corporate_group" && <div className="enq-field"><label htmlFor="f-quantity">Quantity</label><input id="f-quantity" type="number" inputMode="numeric" min={1} max={10000} value={form.quantity} onChange={e => set("quantity", e.target.value)} {...err("quantity")} />{msg("quantity")}</div>}
    </div>
    <div className="enq-row">
      <div className="enq-field"><label htmlFor="f-deadline">Deadline <small>(optional)</small></label><input id="f-deadline" type="date" value={form.deadline} onChange={e => set("deadline", e.target.value)} /></div>
      <div className="enq-field"><label htmlFor="f-location">Location <small>(optional)</small></label><input id="f-location" placeholder="Toronto, ON" maxLength={120} value={form.location} onChange={e => set("location", e.target.value)} /></div>
    </div>
    <div className="enq-field"><label htmlFor="f-requirements">Your requirements</label>
      <textarea id="f-requirements" rows={7} maxLength={3000} value={form.requirements} onChange={e => set("requirements", e.target.value)} {...err("requirements")} aria-describedby={errors.requirements ? "e-requirements c-requirements" : "c-requirements"} />
      <p className="enq-count" id="c-requirements">{count} / 3000 characters (minimum 10)</p>{msg("requirements")}</div>
    <div className="enq-hp" aria-hidden="true"><label>Leave this field empty<input name="website" tabIndex={-1} autoComplete="off" value={honey} onChange={e => setHoney(e.target.value)} /></label></div>
    <div className="enq-field"><label className="enq-check"><input id="f-consent" type="checkbox" checked={form.consent} onChange={e => set("consent", e.target.checked)} {...err("consent")} /><span>I agree that A Zenith Fashion may store and use these details to respond to my request. See the <Link href="/privacy/">privacy notice</Link>.</span></label>{msg("consent")}</div>
    {error && <p className="enq-alert" role="alert">{error}</p>}
    <button className="hm-btn hm-btn-primary" type="submit" disabled={sending}>{sending ? "Sending…" : "Send request"}</button>
  </form>;
}
