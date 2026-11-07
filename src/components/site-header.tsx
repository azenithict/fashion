import Link from "next/link";
import MobileNav from "./mobile-nav";
export const navLinks = [
  ["Explore Designs", "/#categories"], ["Custom Tailoring", "/custom-tailoring/"], ["Design Studio", "/studio/"],
  ["How It Works", "/how-it-works/"], ["Corporate & Group", "/corporate-uniforms/"], ["Account", "/workspace/"],
] as const;
export const ctaHref = "/enquiry/?service=custom_tailoring";
export default function SiteHeader(){
 return <header className="site-header"><div className="nav-shell">
  <Link href="/" className="wordmark" aria-label="A Zenith Fashion, home"><span className="wordmark-symbol">AZ<span className="wordmark-dot">.</span></span><span className="wordmark-name">A ZENITH <em>FASHION</em></span></Link>
  <nav className="az-nav" aria-label="Main navigation">{navLinks.map(([t,h])=><Link key={h} href={h}>{t}</Link>)}</nav>
  <Link className="az-cta" href={ctaHref}>Start Custom Order</Link>
  <MobileNav links={navLinks} cta={ctaHref}/>
 </div></header>;
}
