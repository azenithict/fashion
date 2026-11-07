import type { Metadata } from "next";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
import Account from "@/components/workspace/account";
export const metadata: Metadata = { title: "Your account | A Zenith Fashion", alternates: { canonical: "/account/" }, robots: { index:false, follow:false } };
export default function AccountPage() { return <><SiteHeader/><main className="shell portal-shell"><Account/></main><SiteFooter/></>; }
