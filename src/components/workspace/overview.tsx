"use client";
import { row, rows, text, type Row } from "@/lib/api";
import { enquiryServiceLabels } from "@/lib/enquiry";
import { ResourceState, useResource } from "./common";

const cards: [string, string, string, string, boolean][] = [
  ["enquiries_new", "New enquiries", "enquiries", "Open enquiries", true],
  ["enquiries_open", "Open enquiries in progress", "enquiries", "Open enquiries", false],
  ["enquiries_unassigned", "Unassigned enquiries", "enquiries", "Open enquiries", false],
  ["designs_awaiting_review", "Designs awaiting review", "reviews", "Open review queue", true],
  ["measurements_unverified_on_submitted_designs", "Unverified measurements on submitted designs", "reviews", "Open review queue", false],
  ["quotes_awaiting_customer", "Quotes awaiting customer", "quotes", "Open quotations", false],
  ["quotes_expired_unanswered", "Quotes expired without an answer", "quotes", "Open quotations", true],
  ["orders_awaiting_payment", "Orders awaiting payment", "orders", "Open orders", false],
  ["orders_in_production", "Orders in production", "orders", "Open orders", false],
  ["orders_in_delivery", "Orders in delivery", "orders", "Open orders", false],
  ["orders_disputed", "Disputed orders", "orders", "Open orders", true],
  ["support_open", "Open support requests", "support", "Open support", false],
  ["partner_tasks_awaiting_approval", "Partner tasks awaiting approval", "tasks", "Open partner tasks", false],
  ["partner_applications_pending", "Partner applications pending", "partners", "Open partner network", false],
  ["catalog_pending_verification", "Catalog items pending verification", "catalog", "Open catalog", false],
  ["enquiries_awaiting_details_over_7_days", "Awaiting customer details for over 7 days", "enquiries", "Open enquiries", true],
  ["quotes_expiring_within_3_days", "Quotes expiring within 3 days", "quotes", "Open quotations", true],
  ["locations_listed", "Public locations listed", "", "", false],
  ["email_pending", "Emails waiting to send", "", "", false],
  ["email_failed", "Emails that failed to send", "", "", true],
];
const stages: [string, string][] = [["enquiries", "Enquiries"], ["enquiries_converted", "Converted enquiries"], ["registered_customers", "Registered customers"], ["designs_submitted", "Submitted designs"], ["quotes_issued", "Quotes issued"], ["quotes_accepted", "Quotes accepted"], ["orders_with_verified_payment", "Orders with verified payment"], ["orders_completed", "Completed orders"], ["repeat_customers", "Repeat customers"]];
const svc = (s: unknown) => (enquiryServiceLabels as Record<string, string>)[text(s, "")] ?? text(s);
const stamp = (v: unknown) => { const d = new Date(text(v, "").replace(" ", "T") + (text(v, "").endsWith("Z") ? "" : "Z")); return Number.isNaN(d.getTime()) ? text(v) : d.toLocaleString("en-CA", { dateStyle: "medium", timeStyle: "short" }); };

function Breakdown({ title, head, records, name }: { title: string; head: string; records: Row[]; name: (v: unknown) => string }) {
  return <div className="table-scroll"><table><caption>{title}</caption><thead><tr><th>{head}</th><th>Enquiries</th><th>Converted</th></tr></thead><tbody>{records.length === 0 ? <tr><td colSpan={3}>No data yet.</td></tr> : records.map((r, i) => <tr key={i}><td>{name(r.source ?? r.service)}</td><td>{text(r.enquiries, "0")}</td><td>{text(r.converted, "0")}</td></tr>)}</tbody></table></div>;
}

export default function Overview({ go }: { go: (tab: string) => void }) {
  const overview = useResource<Row>("/operations/overview");
  const funnel = useResource<Row>("/operations/funnel");
  const refresh = () => { void overview.refresh(); void funnel.refresh(); };

  const o = overview.data ? row(overview.data) : null;
  const f = funnel.data ? row(funnel.data) : null;
  const stageData = f ? row(f.stages) : {};
  return <section>
    <div className="portal-section-heading"><div><h2>Overview</h2>{o && <p className="hint">Updated {stamp(o.generated_at)}</p>}</div><button className="button button-outline" type="button" onClick={refresh}>Refresh</button></div>
    <ResourceState loading={overview.loading} error={overview.error} empty={false} onRetry={overview.refresh} />
    {o && <div className="portal-grid">{cards.map(([key, title, tab, cta, attention]) => { const n = Number(o[key]) || 0; const flag = attention && n > 0; return <article key={key} className={"portal-card ov-card" + (flag ? " ov-attention" : "")}><p className="ov-count">{n}</p><h3>{title}</h3>{flag && <p className="ov-flag">Needs attention</p>}{tab && <button className="plain-button" type="button" onClick={() => go(tab)}>{cta} →</button>}</article>; })}</div>}
    <h2>Sales funnel</h2>
    <ResourceState loading={funnel.loading} error={funnel.error} empty={false} onRetry={funnel.refresh} />
    {f && <>
      <ol className="ov-funnel">{stages.map(([key, title]) => <li key={key}><span>{title}</span><strong>{text(stageData[key], "0")}</strong></li>)}</ol>
      <div className="portal-two-columns"><Breakdown title="Enquiries by source" head="Source" records={rows(f.by_source)} name={v => text(v)} /><Breakdown title="Enquiries by service" head="Service" records={rows(f.by_service)} name={svc} /></div>
      {f.note ? <p className="hint">{text(f.note)}</p> : null}
    </>}
  </section>;
}
