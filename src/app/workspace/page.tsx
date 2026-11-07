import type { Metadata } from "next";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
import Workspace from "@/components/workspace/workspace";
export const metadata: Metadata = { title:"Your workspace | A Zenith Fashion", alternates:{ canonical:"/workspace/" }, robots:{index:false,follow:false} };
export default function WorkspacePage() { return <><SiteHeader/><main className="shell portal-shell"><Workspace/></main><SiteFooter/></>; }
