import type {Metadata} from "next";
import Link from "next/link";
import {notFound} from "next/navigation";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
import {servicePages} from "@/lib/service-pages";
export const dynamicParams=false;
export function generateStaticParams(){return Object.keys(servicePages).map(slug=>({slug}));}
export async function generateMetadata({params}:{params:Promise<{slug:string}>}):Promise<Metadata>{const {slug}=await params;const page=servicePages[slug];return page?{title:page.eyebrow+" | A Zenith Fashion",description:page.intro,alternates:{canonical:"/"+slug+"/"}}:{};}
export default async function ServicePage({params}:{params:Promise<{slug:string}>}){const {slug}=await params;const page=servicePages[slug];if(!page)notFound();return <><SiteHeader/><main id="main-content" className="shell service-page"><Link className="text-link" href="/">← A Zenith Fashion</Link><p className="eyebrow">{page.eyebrow}</p><h1>{page.title}</h1><p className="service-intro">{page.intro}</p><div className="service-sections">{page.sections.map((section,i)=><section key={section.title}><span className="eyebrow">0{i+1}</span><h2>{section.title}</h2><p>{section.body}</p></section>)}</div>{page.href&&<Link className="button button-dark" href={page.href}>{page.action} →</Link>}<nav className="service-links" aria-label="Explore tailoring services">{Object.entries(servicePages).filter(([key])=>key!==slug).map(([key,value])=><Link key={key} href={"/"+key+"/"}>{value.eyebrow.replace(/ — .*$/,"")}</Link>)}</nav></main><SiteFooter/></>;}
