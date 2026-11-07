import type { Metadata } from "next";
import { Suspense } from "react";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
import { DesignDetailView } from "@/components/designs";

export const metadata: Metadata = {
  title: "Design | A Zenith Fashion",
  description: "Design details, materials, construction options and measurement guidance. Quoted individually after review.",
  alternates: { canonical: "/designs/" },
};
export default function DesignItemPage() {
  return <><SiteHeader /><main id="main" className="hm dz"><div className="hm-wrap">
    <Suspense fallback={<p role="status">Loading design…</p>}><DesignDetailView /></Suspense>
  </div></main><SiteFooter /></>;
}
