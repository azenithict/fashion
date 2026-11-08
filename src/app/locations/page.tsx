"use client";
import Link from "next/link";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
import {label,rows,text,type Row} from "@/lib/api";
import {useResource} from "@/components/workspace/common";
function groupByCity(list:Row[]){const groups=new Map<string,Row[]>();for(const item of list){const city=text(item.city,"Other");groups.set(city,[...(groups.get(city)??[]),item]);}return [...groups.entries()].sort((a,b)=>a[0].localeCompare(b[0]));}
function Locations(){
 const resource=useResource<Row[]>("/locations");
 if(resource.loading)return <p role="status">Loading locations…</p>;
 if(resource.error)return <div className="portal-notice error" role="alert"><p>{resource.error}</p><button className="button button-outline" type="button" onClick={resource.refresh}>Try again</button></div>;
 const list=rows(resource.data);
 if(!list.length)return <div className="portal-card location-empty"><h2>No locations listed yet</h2><p>Local appointments are not currently available. We are setting up agreements with independent Ontario tailors and will list locations here only once they are confirmed.</p><div className="portal-actions"><Link className="button button-dark" href="/enquiry/?service=consultation">Request a consultation</Link><Link className="button button-outline" href="/measurements/">Measurement guide</Link></div></div>;
 return <>{groupByCity(list).map(([city,items])=><section key={city} className="location-group"><h2>{city}</h2><div className="portal-grid">{items.map((item,index)=><article className="portal-card" key={city+index}><h3>{text(item.business_name)}</h3><p className="eyebrow">{text(item.branch_name)}</p><address>{text(item.address_line)}<br/>{text(item.city)}, {text(item.province)} {text(item.postal_code)}</address><ul className="location-services" aria-label="Services">{(Array.isArray(item.services)?item.services:[]).map(service=><li key={String(service)} className="status-chip">{label(String(service))}</li>)}</ul>{item.booking_note?<p><strong>Booking:</strong> {text(item.booking_note)}</p>:null}</article>)}</div></section>)}<p className="hint">Partners are independent businesses operating under their own names. Services, rates and appointments are arranged with each business.</p></>;
}
export default function LocationsPage(){return <><SiteHeader/><main><section className="inner-hero shell"><p className="eyebrow">Local network</p><h1>Local fittings &amp;<br/><em>alterations</em></h1><p>Measurement, fitting, alteration and repair appointments with independent Ontario tailors, listed only once an agreement is confirmed.</p></section><div className="shell location-list"><Locations/></div></main><SiteFooter/></>}
