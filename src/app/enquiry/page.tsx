import Link from "next/link";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
export default function EnquiryRedirect(){
 return <><SiteHeader/><main id="main-content" className="shell redirect-page"><p className="eyebrow">A Zenith / Atelier</p><h1>Every piece begins with a conversation.</h1><p>Our custom-tailoring design brief now lives in the Digital Atelier, where you can explore garments, colours and details before requesting a consultation.</p><Link className="button button-dark" href="/studio/">Open Design Studio ↗</Link></main><SiteFooter/></>;
}
