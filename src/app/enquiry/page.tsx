import type { Metadata } from "next";
import { Suspense } from "react";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
import EnquiryFormView from "@/components/enquiry-form";

export const metadata: Metadata = {
  title: "Start a Request | A Zenith Fashion",
  description: "Tell us what you would like made. We review every request and reply by your preferred contact method. This is not a quotation or an order.",
  alternates: { canonical: "/enquiry/" },
};
export default function EnquiryPage() {
  return <><SiteHeader /><main id="main" className="enq-page"><div className="enq-wrap">
    <p className="hm-kicker">Start a request</p><h1>Tell us what you would like made.</h1>
    <p className="hm-sub">We review every request and reply by your preferred contact method. Sending a request is not an order and nothing is charged.</p>
    <Suspense fallback={<p role="status">Loading the form…</p>}><EnquiryFormView /></Suspense>
  </div></main><SiteFooter /></>;
}
