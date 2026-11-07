import type { Metadata } from "next";
import { Suspense } from "react";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
import { DesignsList } from "@/components/designs";

export const metadata: Metadata = {
  title: "Designs | A Zenith Fashion",
  description: "Verified custom tailoring designs with their materials and construction options. Every design is quoted individually after review.",
  alternates: { canonical: "/designs/" },
};
export default function DesignsPage() {
  return <><SiteHeader /><main id="main" className="hm dz"><div className="hm-wrap">
    <p className="hm-kicker">Explore Designs</p><h1>Designs</h1>
    <p className="hm-sub">Each design is verified with our manufacturer before it is listed. Pricing is quoted individually after review.</p>
    <Suspense fallback={<p role="status">Loading designs…</p>}><DesignsList /></Suspense>
  </div></main><SiteFooter /></>;
}
