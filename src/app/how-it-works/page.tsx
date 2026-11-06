import Link from "next/link";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
const steps=[
 {n:"01",name:"Express your vision",detail:"Choose a garment and share the occasion, shape, colour and details that feel like you."},
 {n:"02",name:"Feasibility and consultation",detail:"A tailoring specialist reviews the brief, available materials and production capabilities before confirming what is possible."},
 {n:"03",name:"Measurements and final quote",detail:"Measurements are confirmed through an agreed method. Your final specifications, price, terms and lead time are approved before production."},
 {n:"04",name:"Craftsmanship and quality",detail:"The approved garment is produced by our contracted manufacturer, with checkpoints and an agreed delivery plan."},
 {n:"05",name:"Fit, finish and aftercare",detail:"Final fitting and any agreed alterations are arranged; the garment's approved specifications may support easier future orders."},
];
export default function HowPage(){return <><SiteHeader/><main><section className="inner-hero shell"><p className="eyebrow">Our process</p><h1>A considered process.<br/><em>A personal result.</em></h1><p>Custom tailoring takes more than selecting a size. Every stage exists to make expectations, responsibilities and the finished garment clearer.</p><Link href="/studio/" className="button button-dark">Explore the studio ↗</Link></section><div className="shell process-lines">{steps.map(s=><div className="process-line" key={s.n}><span>{s.n}</span><h2>{s.name}</h2><p>{s.detail}</p></div>)}</div><section className="shell note-section"><span className="eyebrow">The standard</span><h2>No surprises disguised as personalisation.</h2><p>Our online concept is a starting point, not a binding quote. Available fabric, exact fit, manufacturing lead time, import logistics and the final price must be confirmed before a paid order.</p></section></main><SiteFooter/></>}
