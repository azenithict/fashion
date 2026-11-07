import { Suspense } from "react";
import Link from "next/link";
import type { Metadata } from "next";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
import Studio from "@/components/studio/studio";
export const metadata: Metadata = { title: "Design studio | A Zenith Fashion", description: "Choose a design, select materials and construction options, add your requirements and submit for professional review. Quoted individually.", alternates: { canonical: "/studio/" } };
export default function StudioPage() {
  return <><SiteHeader /><main id="main">
    <div className="studio-intro shell"><Link href="/" className="text-link">← Back to home</Link><p className="eyebrow">A Zenith / Digital Atelier</p><h1>Make it <em>yours.</em></h1><p>Customize a published design step by step, or start from your own idea. Every submission is reviewed professionally before any quote; nothing is ordered or charged here.</p></div>
    <div className="shell sz-shell"><Suspense fallback={<p role="status">Opening your studio…</p>}><Studio /></Suspense></div>
    <section className="shell studio-under"><div className="eyebrow">What happens next</div><h2>From a design direction to a garment that fits.</h2><p>Professional review, confirmed measurements, an approved written quote, production and final fitting — without promising an unverified price or lead time.</p><Link href="/how-it-works/" className="text-link">See the complete process ↗</Link></section>
  </main><SiteFooter /></>;
}
