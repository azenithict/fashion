"use client";
import {useMemo,useState} from "react";
import Link from "next/link";
import SiteHeader from "@/components/site-header";
import SiteFooter from "@/components/site-footer";
import GarmentPreview from "@/components/garment-preview";
import {designSummary,fabricShades,finishes,fits,garmentKinds,type DesignSelection,type FabricShade,type Finish,type Fit,type GarmentKind} from "@/lib/design";
export default function StudioPage(){
 const [design,setDesign]=useState<DesignSelection>({garment:"suit",shade:"forest",fit:"Tailored",finish:"Minimal",notes:""});
 const [copied,setCopied]=useState(false);
 const brief=useMemo(()=>designSummary(design),[design]);
 const set=<K extends keyof DesignSelection>(key:K,val:DesignSelection[K])=>{setDesign(p=>({...p,[key]:val}));setCopied(false);};
 const mailto="mailto:sales@azenith.ca?subject="+encodeURIComponent("A Zenith Fashion — custom tailoring enquiry")+"&body="+encodeURIComponent(brief);
 async function copy(){try{await navigator.clipboard.writeText(brief);setCopied(true);}catch{setCopied(false);}}
 return <><SiteHeader/><main>
  <div className="studio-intro shell"><Link href="/" className="text-link">← Back to home</Link><p className="eyebrow">A Zenith / Digital Atelier</p><h1>Make it <em>yours.</em></h1><p>Start with an idea, explore a silhouette and create a thoughtful brief for a professional tailoring consultation.</p></div>
  <div className="shell studio-layout">
   <section className="studio-panel" aria-label="Garment design options">
    <div className="studio-step"><span>01 / 04</span><h2>Choose your garment</h2><div className="category-grid">{garmentKinds.map(g=><button type="button" key={g.id} onClick={()=>set("garment",g.id as GarmentKind)} aria-pressed={design.garment===g.id} className={"option-tile "+(design.garment===g.id?"selected":"")}><small>{g.group}</small><strong>{g.title}</strong></button>)}</div></div>
    <div className="studio-step"><span>02 / 04</span><h2>Explore a colour</h2><p className="hint">Visual references only; final fabrics and colourways require sampling.</p><div className="swatch-grid">{fabricShades.map(s=><button type="button" key={s.id} className={"swatch-button "+(design.shade===s.id?"selected":"")} aria-label={s.label} aria-pressed={design.shade===s.id} onClick={()=>set("shade",s.id as FabricShade)}><span className="swatch" style={{background:s.hex}}/><span>{s.label}</span></button>)}</div></div>
    <div className="studio-step"><span>03 / 04</span><h2>Personalise the fit</h2><div className="segmented-options">{fits.map(s=><button type="button" className={design.fit===s?"selected":""} aria-pressed={design.fit===s} key={s} onClick={()=>set("fit",s as Fit)}>{s}</button>)}</div><p className="hint">Fit preferences are guidance, not a replacement for professional measurements.</p></div>
    <div className="studio-step"><span>04 / 04</span><h2>Finishing detail</h2><div className="segmented-options">{finishes.map(s=><button type="button" className={design.finish===s?"selected":""} aria-pressed={design.finish===s} key={s} onClick={()=>set("finish",s as Finish)}>{s}</button>)}</div><label htmlFor="design-notes" className="notes-label">Anything else we should know? <small>(optional)</small></label><textarea id="design-notes" maxLength={800} rows={4} value={design.notes} onChange={e=>set("notes",e.target.value)} placeholder="Occasion, inspiration, special requirements... No body measurements or sensitive information." /></div>
   </section>
   <aside className="studio-preview"><div className="preview-header"><span className="eyebrow">Design study / not a product photograph</span><span>01—01</span></div><div className="studio-preview-figure"><GarmentPreview garment={design.garment} shade={design.shade} finish={design.finish}/></div><div className="preview-caption"><div><span>YOUR CONCEPT</span><strong>{garmentKinds.find(x=>x.id===design.garment)?.title}</strong></div><div><span>COLOUR</span><strong>{fabricShades.find(x=>x.id===design.shade)?.label}</strong></div></div><div className="brief-panel"><div className="brief-title"><h2>Your design brief</h2><span>Ready to share</span></div><p className="hint">A consultation is required to confirm fabric, feasibility, measurements, price and delivery time. No order or payment is created here.</p><div className="studio-actions"><button type="button" className="button button-outline" onClick={copy}>{copied?"Copied ✓":"Copy brief"}</button><a className="button button-dark" href={mailto}>Email enquiry ↗</a></div><p className="hint small">Email enquiry opens your email app and requires you to send the message. The design is not stored or submitted automatically.</p></div></aside>
  </div>
  <section className="shell studio-under"><div className="eyebrow">What happens next</div><h2>From a design direction to a garment that fits.</h2><p>Professional review, confirmed measurements, an approved quote, production and final fitting — without promising an unverified price or lead time.</p><Link href="/how-it-works/" className="text-link">See the complete process ↗</Link></section>
 </main><SiteFooter/></>;
}
