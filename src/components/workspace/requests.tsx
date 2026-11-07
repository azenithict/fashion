"use client";
import Link from "next/link";
import { api, rows, text, type Row, type Session } from "@/lib/api";
import { enquiryServiceLabels, enquiryStatusLabels } from "@/lib/enquiry";
import { ActionForm, Field, ResourceState, useResource, value } from "./common";

const when = (v: unknown) => { const d = new Date(text(v, "").replace(" ", "T") + (text(v, "").endsWith("Z") ? "" : "Z")); return Number.isNaN(d.getTime()) ? text(v) : d.toLocaleString("en-CA", { dateStyle: "medium", timeStyle: "short" }); };
const excerpt = (v: unknown) => { const s = text(v, ""); return s.length > 240 ? s.slice(0, 240).trimEnd() + "…" : s; };

export default function MyRequests({ session }: { session: Session }) {
  const verified = Boolean(session.user?.email_verified_at);
  const resource = useResource<Row[]>(verified ? "/my/enquiries" : "/session");
  if (!verified) return <section><h2>My requests</h2><p className="portal-notice">Verify your email address to see the requests you have sent to A Zenith. Check your inbox for the verification message, then return to this page.</p></section>;
  const list = resource.data && Array.isArray(resource.data) ? rows(resource.data) : [];
  return <section>
    <div className="portal-section-heading"><h2>My requests</h2><button className="button button-outline" type="button" onClick={resource.refresh}>Refresh</button></div>
    <p className="portal-lead">Requests sent from the website using your verified email, and our replies.</p>
    <ResourceState loading={resource.loading} error={resource.error} empty={false} onRetry={resource.refresh} />
    {!resource.loading && !resource.error && list.length === 0 && <div className="empty-state"><p>No requests found for your verified email</p><Link className="button button-dark" href="/enquiry/">Send an enquiry</Link></div>}
    <div className="portal-grid">{list.map(r => {
      const status = text(r.status, ""); const closed = status === "converted" || status === "closed";
      return <article className="portal-card" key={text(r.id)}>
        <div className="portal-section-heading"><h3>{text(r.reference)}</h3><span className="status-chip">{enquiryStatusLabels[status] ?? text(status)}</span></div>
        <dl className="record-facts"><div><dt>Service</dt><dd>{(enquiryServiceLabels as Record<string, string>)[text(r.service, "")] ?? text(r.service)}</dd></div><div><dt>Sent</dt><dd>{when(r.created_at)}</dd></div></dl>
        <p className="enq-pre">{excerpt(r.requirements)}</p>
        {r.design_id ? <p><Link className="text-link" href={"/studio/?design=" + encodeURIComponent(text(r.design_id))}>Open your linked design →</Link></p> : null}
        {rows(r.messages).length > 0 && <ol className="message-list">{rows(r.messages).map((m, i) => <li key={text(m.id, String(i))}><strong>{m.kind === "customer_reply" ? "Your reply" : "A Zenith"}</strong><p className="enq-pre">{text(m.message)}</p><small>{when(m.created_at)}</small></li>)}</ol>}
        {closed ? <p className="hint">This request is {status === "converted" ? "converted to a design" : "closed"}. For anything further, please <Link className="text-link" href="/workspace/?tab=support">start a support request</Link>.</p>
          : <ActionForm title="Reply" submitLabel="Send reply" success="Your reply has been sent." onDone={resource.refresh} onSubmit={d => { const message = value(d, "message"); if (!message) throw new Error("Enter a message."); return api("/my/enquiries/" + text(r.id) + "/reply", "POST", { message }); }}><Field title="Your message" name="message" type="textarea" max={3000} /></ActionForm>}
      </article>;
    })}</div>
  </section>;
}
