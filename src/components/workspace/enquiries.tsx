"use client";
import { useState } from "react";
import { api, label, row, rows, text, type Row } from "@/lib/api";
import { enquiryServiceLabels, enquiryStatusLabels, enquiryStatuses } from "@/lib/enquiry";
import { ActionForm, Field, Notice, ResourceState, useAction, useResource, value } from "./common";

const sLabel = (s: unknown) => enquiryStatusLabels[text(s, "")] ?? text(s);
const svcLabel = (s: unknown) => (enquiryServiceLabels as Record<string, string>)[text(s, "")] ?? text(s);
const kindLabels: Record<string, string> = { created: "Enquiry received", status: "Status change", note: "Internal note", assigned: "Assignment", customer_message: "Sent to customer", customer_reply: "Customer reply" };
const date = (v: unknown) => { const d = new Date(text(v, "")); return Number.isNaN(d.getTime()) ? text(v) : d.toLocaleString("en-CA", { dateStyle: "medium", timeStyle: "short" }); };

function Detail({ id, onChanged, onBack }: { id: string; onChanged: () => void; onBack: () => void }) {
  const resource = useResource<Row>("/enquiries/" + id);
  const assign = useAction();
  const data = resource.data ? row(resource.data) : null;
  const e = data ? row(data.enquiry) : null;
  const events = data ? rows(data.events) : [];
  const candidates = data ? rows(data.candidate_designs) : [];
  const transitions = data && Array.isArray(data.transitions) ? data.transitions.map(String) : [];
  const [status, setStatus] = useState("");
  const changed = () => { resource.refresh(); onChanged(); };
  const current = status && transitions.includes(status) ? status : transitions[0] ?? "";
  return <section className="portal-card enq-detail">
    <button className="button button-outline" type="button" onClick={onBack}>Back to list</button>
    <ResourceState loading={resource.loading} error={resource.error} empty={false} onRetry={resource.refresh} />
    {e && <>
      <div className="portal-section-heading"><h3>{text(e.reference)}</h3><span className="status-chip">{sLabel(e.status)}</span></div>
      <dl className="record-facts">
        {[["Service", svcLabel(e.service)], ["Name", text(e.name)], ["Contact method", text(e.contact_method)], ["Garment / occasion", text(e.garment)], ["Quantity", text(e.quantity)], ["Deadline", text(e.deadline)], ["Location", text(e.location)], ["Assigned to", text(e.assigned_name, "Unassigned")], ["Received", date(e.created_at)], ["Updated", date(e.updated_at)], ...(e.closed_reason ? [["Close reason", text(e.closed_reason)]] : [])].map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}
        <div><dt>Email</dt><dd>{e.email ? <a href={"mailto:" + text(e.email)}>{text(e.email)}</a> : "—"}</dd></div>
        <div><dt>Phone</dt><dd>{e.phone ? <a href={"tel:" + text(e.phone).replace(/[^+\d]/g, "")}>{text(e.phone)}</a> : "—"}</dd></div>
      </dl>
      {e.design_id ? <p className="portal-notice success">Linked design: <code>{text(e.design_id)}</code></p> : null}
      <h4>Requirements</h4><p className="enq-pre">{text(e.requirements)}</p>
      <div className="portal-actions"><button className="button button-outline" disabled={assign.pending} onClick={() => assign.run(() => api("/enquiries/" + id + "/assign", "POST", { assign: "me" }).then(changed), "Assigned to you.")}>Assign to me</button><button className="button button-outline" disabled={assign.pending} onClick={() => assign.run(() => api("/enquiries/" + id + "/assign", "POST", { assign: "none" }).then(changed), "Unassigned.")}>Unassign</button></div>
      <Notice error={assign.error} message={assign.message} />
      <div className="portal-two-columns">
        {transitions.length > 0 ? <ActionForm title="Change status" submitLabel="Update status" success="Status updated." onDone={changed} onSubmit={d => { const next = value(d, "status"); const note = value(d, "note"); if (next === "closed" && !note) throw new Error("Enter a close reason."); return api("/enquiries/" + id + "/status", "POST", { status: next, note }); }}>
          <Field title="New status" name="status" value={current} onChange={setStatus}>{transitions.map(t => <option key={t} value={t}>{sLabel(t)}</option>)}</Field>
          <Field title={current === "closed" ? "Close reason" : "Note"} name="note" type="textarea" required={false} />
        </ActionForm> : <p className="empty-state">No further status changes are available.</p>}
        <ActionForm title="Add a note" submitLabel="Add note" success="Note added." onDone={changed} onSubmit={d => { const note = value(d, "note"); if (!note) throw new Error("Enter a note."); return api("/enquiries/" + id + "/notes", "POST", { note }); }}>
          <Field title="Internal note" name="note" type="textarea" />
        </ActionForm>
      </div>
      <ActionForm title="Message the customer" submitLabel="Send message" success="Message sent." onDone={changed} onSubmit={d => { const message = value(d, "message"); if (!message) throw new Error("Enter a message."); return api("/enquiries/" + id + "/message", "POST", { message, ...(d.get("request_details") === "on" ? { request_details: true } : {}) }); }}>
        <p className="hint">This message is emailed to the customer&apos;s address and shown in their account under My requests.</p>
        <Field title="Message" name="message" type="textarea" max={3000} />
        <label className="portal-check"><input type="checkbox" name="request_details" /><span>Ask for missing details (moves to Awaiting details)</span></label>
      </ActionForm>
      {e.status !== "converted" && <div><h4>Link customer design</h4>
        {candidates.length === 0 ? <p className="empty-state">No design yet from a verified account using this email. Invite the customer to create an account with this email and start a design in the studio.</p> : <ul className="enq-list">{candidates.map(c => <li key={text(c.id)}><span className="enq-list-top"><strong>{text(c.name, "Untitled design")}</strong><span className="status-chip">{label(text(c.status, ""))}</span></span><span className="enq-list-meta">Created {date(c.created_at)}</span><button className="button button-outline" type="button" disabled={assign.pending} onClick={() => assign.run(() => api("/enquiries/" + id + "/link-design", "POST", { design_id: text(c.id) }).then(changed), "Design linked and enquiry converted.")}>Link and convert</button></li>)}</ul>}
      </div>}
      <h4>Activity</h4>
      {events.length === 0 ? <p className="empty-state">No activity yet.</p> : <ol className="enq-timeline">{events.map(ev => <li key={text(ev.id)}><strong>{ev.kind === "status" ? (ev.from_status ? sLabel(ev.from_status) + " → " : "") + sLabel(ev.to_status) : kindLabels[text(ev.kind, "")] ?? text(ev.kind)}</strong><span> · {text(ev.actor_name, "System")} · {date(ev.created_at)}</span>{ev.note ? <p className="enq-pre">{text(ev.note)}</p> : null}</li>)}</ol>}
    </>}
  </section>;
}

export default function Enquiries() {
  const resource = useResource<Row[]>("/enquiries");
  const [filter, setFilter] = useState("all");
  const [open, setOpen] = useState("");
  const list = (resource.data ? rows(resource.data) : []).slice().sort((a, b) => text(b.created_at, "").localeCompare(text(a.created_at, "")));
  const counts = (s: string) => s === "all" ? list.length : list.filter(r => r.status === s).length;
  const shown = filter === "all" ? list : list.filter(r => r.status === filter);
  if (open) return <Detail id={open} onChanged={resource.refresh} onBack={() => setOpen("")} />;
  return <section><h2>Enquiries</h2><p className="portal-lead">Guest requests from the website. Replying or sending a quote is a separate step.</p>
    <div className="enq-chips" role="group" aria-label="Filter by status">{["all", ...enquiryStatuses].map(s => <button key={s} type="button" aria-pressed={filter === s} onClick={() => setFilter(s)}>{s === "all" ? "All" : sLabel(s)} ({counts(s)})</button>)}</div>
    <ResourceState loading={resource.loading} error={resource.error} empty={false} onRetry={resource.refresh} />
    {!resource.loading && !resource.error && shown.length === 0 && <p className="empty-state">{list.length === 0 ? "No enquiries yet" : "No enquiries with this status."}</p>}
    <ul className="enq-list">{shown.map(r => <li key={text(r.id)}><button type="button" onClick={() => setOpen(text(r.id))}><span className="enq-list-top"><strong>{text(r.reference)}</strong><span className="status-chip">{sLabel(r.status)}</span></span><span>{text(r.name)} · {svcLabel(r.service)}</span><span className="enq-list-meta">{date(r.created_at)} · {text(r.assigned_name, "Unassigned")}</span></button></li>)}</ul>
  </section>;
}
