import type { Metadata } from "next";
import Link from "next/link";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";

export const metadata: Metadata = {
  title: "Custom Clothing, Designed Around You | A Zenith Fashion",
  description: "Custom tailoring for Toronto and Ontario. Explore tailoring directions, choose the details that matter, and request a written quote for clothing made for your fit and occasion.",
  alternates: { canonical: "/" },
};

const enq = (service: string, garment?: string) => "/enquiry/?service=" + service + (garment ? "&garment=" + encodeURIComponent(garment) : "");
const categories = [
  { title: "Men's Tailoring", href: "/mens-custom-clothing/", text: "Suits and individual garments, reviewed for fit and construction.", quote: enq("custom_tailoring", "Men's Tailoring") },
  { title: "Women's Custom Clothing", href: "/womens-custom-clothing/", text: "Dresses and custom pieces shaped around how you wear them.", quote: enq("custom_tailoring", "Women's Custom Clothing") },
  { title: "Occasionwear", href: "/occasionwear/", text: "Garments for celebrations and events. Dates are confirmed in your quote.", quote: enq("custom_tailoring", "Occasionwear") },
  { title: "Traditional Clothing", href: "/traditional-clothing/", text: "Heritage garments, with the details that matter to you written into the brief.", quote: enq("custom_tailoring", "Traditional Clothing") },
  { title: "Corporate & Uniform Orders", href: "/corporate-uniforms/", text: "Coordinated garments for teams and groups, scoped before pricing.", quote: enq("corporate_group", "Corporate & Uniform Orders") },
];
const personal = ["Fabric", "Fit", "Construction", "Lining", "Buttons", "Finishing", "Personal specifications"];
const steps = [
  ["Choose", "Pick a garment direction or describe your own idea."],
  ["Customize", "Tell us the details, occasion and any reference images."],
  ["Review & Measure", "We review feasibility and your measurements with you."],
  ["Quote", "You receive a written quote. Nothing is a quote until it is written."],
  ["Approve", "Nothing proceeds until you approve the quote. Payment follows approval only."],
  ["Produce", "Our contracted manufacturer makes your garment."],
  ["Deliver", "Delivery arrangements are confirmed in your quote. We do not promise dates in advance."],
];
const faqs = [
  ["How much does it cost?", "Every request is quoted individually after review. We do not publish list prices yet."],
  ["How are measurements handled?", "Measurements you enter yourself are reviewed before production. Professional measurement options are offered where available. See our measurements guide."],
  ["Who makes the garments?", "A contracted manufacturer produces them. Timelines are confirmed in your written quote."],
  ["What about shipping?", "Shipping is confirmed per quote."],
  ["Do you offer alterations?", "Only where a contracted local provider exists. None is advertised yet. You can still send a request."],
  ["Do I have to commit to anything?", "No. Nothing proceeds until you approve a written quote."],
  ["How do I pay?", "Online payment is not yet available. You are never asked to pay before you approve a quote."],
];

export default function Home() {
  return <><SiteHeader /><main id="main" className="hm">
    <section className="hm-hero"><div className="hm-wrap">
      <p className="hm-kicker">Custom tailoring · Toronto &amp; Ontario</p>
      <h1>Custom Clothing. Designed Around You.</h1>
      <p className="hm-lead">Explore real tailoring designs, choose the details that matter, and work with us to create clothing made for your fit and occasion.</p>
      <div className="hm-actions"><a className="hm-btn hm-btn-primary" href="#categories">Explore Designs</a><Link className="hm-btn hm-btn-secondary" href={enq("custom_tailoring")}>Start Custom Order</Link><Link className="hm-link" href="/how-it-works/">How it works</Link></div>
    </div></section>

    <section className="hm-section" id="categories" aria-labelledby="cat-h"><div className="hm-wrap">
      <p className="hm-kicker">Shop by Category</p><h2 id="cat-h">Where would you like to begin?</h2>
      <p className="hm-sub">Choose a direction to learn more. Each one is a custom request, not a ready-made product.</p>
      <ul className="hm-cards">{categories.map((c, i) => <li key={c.title} className="hm-card"><span className="hm-num" aria-hidden="true">{String(i + 1).padStart(2, "0")}</span><h3>{c.title}</h3><p>{c.text}</p><div className="hm-card-links"><Link href={c.href}>Learn more<span className="hm-sr"> about {c.title}</span></Link><Link href={c.quote}>Request a quote<span className="hm-sr"> for {c.title}</span></Link></div></li>)}</ul>
    </div></section>

    <section className="hm-section hm-alt" aria-labelledby="real-h"><div className="hm-wrap hm-narrow">
      <p className="hm-kicker">Explore Real Designs</p><h2 id="real-h">Our first design collection is being prepared.</h2>
      <p>We are preparing our first verified design collection with our manufacturing partner. Designs will appear here only once they have been photographed and confirmed.</p>
      <p>Until then, start from your own idea or reference images.</p>
      <Link className="hm-btn hm-btn-primary" href={enq("design_question")}>Describe your design</Link>
    </div></section>

    <section className="hm-section" aria-labelledby="pers-h"><div className="hm-wrap hm-split">
      <div><p className="hm-kicker">Personalize Your Garment</p><h2 id="pers-h">The details are yours to shape.</h2></div>
      <div><p>These details are discussed and confirmed with you for each order, against what our manufacturer can actually produce. They are not selectable options on this site.</p><ul className="hm-tags">{personal.map(p => <li key={p}>{p}</li>)}</ul></div>
    </div></section>

    <section className="hm-section hm-dark" id="how" aria-labelledby="how-h"><div className="hm-wrap">
      <p className="hm-kicker">How It Works</p><h2 id="how-h">Seven steps, no surprises.</h2>
      <ol className="hm-steps">{steps.map(([t, d]) => <li key={t}><h3>{t}</h3><p>{d}</p></li>)}</ol>
    </div></section>

    <section className="hm-section" aria-labelledby="craft-h"><div className="hm-wrap hm-split">
      <div><p className="hm-kicker">Craftsmanship &amp; Process</p><h2 id="craft-h">Specified carefully before anything is made.</h2></div>
      <div><p>Each request goes through a professional specification review. We check your brief against what can be produced, and we guide you through taking accurate measurements.</p><Link className="hm-link" href="/measurements/">Read the measurements guide</Link></div>
    </div></section>

    <section className="hm-section hm-alt" aria-labelledby="biz-h"><div className="hm-wrap hm-narrow">
      <p className="hm-kicker">Business &amp; Group Orders</p><h2 id="biz-h">Uniforms and coordinated garments.</h2>
      <p>Tell us the group size, intended use and branding needs. We scope the order with you before any pricing.</p>
      <Link className="hm-btn hm-btn-primary" href={enq("corporate_group")}>Start a group enquiry</Link>
    </div></section>

    <section className="hm-section" aria-labelledby="faq-h"><div className="hm-wrap hm-narrow">
      <p className="hm-kicker">FAQ</p><h2 id="faq-h">Common questions</h2>
      <div className="hm-faq">{faqs.map(([q, a]) => <details key={q}><summary>{q}</summary><p>{a}</p></details>)}</div>
      <div className="hm-contact"><h3>Still have a question?</h3><div className="hm-actions"><Link className="hm-btn hm-btn-primary" href={enq("consultation")}>Request a consultation</Link><Link className="hm-btn hm-btn-secondary" href={enq("general")}>General question</Link></div></div>
    </div></section>
  </main><SiteFooter /></>;
}
