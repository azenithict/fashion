import Link from "next/link";
export default function SiteHeader(){
 return <header className="site-header"><div className="nav-shell">
  <Link href="/" className="wordmark" aria-label="A Zenith Fashion, home"><span className="wordmark-symbol">AZ<span className="wordmark-dot">.</span></span><span className="wordmark-name">A ZENITH <em>FASHION</em></span></Link>
  <nav className="nav-main" aria-label="Main navigation"><Link href="/studio/">Design Studio</Link><Link href="/how-it-works/">Our Process</Link><Link href="/partners/">For Tailors</Link><Link href="/workspace/">My account</Link></nav>
  <Link className="mobile-account button button-dark" href="/workspace/">My account</Link>
  <Link className="nav-cta" href="/studio/">Begin a design <span aria-hidden="true">↗</span></Link>
 </div></header>;
}
