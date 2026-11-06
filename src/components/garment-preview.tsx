import type { FabricShade, Finish, GarmentKind } from "@/lib/design";
import { fabricShades } from "@/lib/design";
type Props = { garment:GarmentKind; shade:FabricShade; finish:Finish; hero?:boolean };
export default function GarmentPreview({garment,shade,finish,hero=false}:Props){
 const base=fabricShades.find(x=>x.id===shade)?.hex??"#253747";
 const edge=finish==="Contrast edge"?"#c7a77b":"#bfaaa0";
 const decorative=finish==="Decorative detail";
 return <svg className={"garment-art "+(hero?"garment-art--hero":"")} viewBox="0 0 520 640" role="img" aria-label={"Illustrated "+garment+" design concept. Fabric colour and finish are indicative only."} xmlns="http://www.w3.org/2000/svg">
  <defs>
   <linearGradient id="fabricLit" x1="0" y1="0" x2="1" y2="1"><stop stopColor="#fff" stopOpacity=".23"/><stop offset=".52" stopColor="#fff" stopOpacity="0"/><stop offset="1" stopColor="#000" stopOpacity=".22"/></linearGradient>
   <pattern id="finestripe" width="7" height="7" patternUnits="userSpaceOnUse"><path d="M0 0V7" stroke="#fff" strokeOpacity=".08" strokeWidth="1"/></pattern>
  </defs>
  <circle cx="260" cy="277" r="218" fill="none" stroke="#d3c1aa" strokeWidth="1" strokeDasharray="2 12"/>
  <circle cx="260" cy="277" r="178" fill="none" stroke="#d3c1aa" strokeOpacity=".45"/>
  <path d="M260 44v44 M260 496v44 M32 277h44 M444 277h44" stroke="#bda889" strokeOpacity=".7"/>
  <g strokeLinejoin="round" strokeLinecap="round">
   {garment==="suit"&&<>
    <path d="M196 130l-38 28-49 116 45 20 38-72 3 209h132l3-209 38 72 45-20-49-116-39-28-62 34z" fill={base} stroke="#4d4b46" strokeWidth="2"/>
    <path d="M196 130l64 61 65-61-26 128-39 41-38-41z" fill="#eee7da" stroke="#7b7267"/>
    <path d="M196 130l18 70 46 87-40 31-34-111 10-77 M325 130l-18 70-47 87 40 31 34-111-9-77" fill={base} stroke="#4d4b46" strokeWidth="1.5"/>
    <path d="M223 321l74 0 M195 384l48 0M277 384l48 0" stroke={edge} strokeWidth={finish==="Contrast edge"?3:1.5} fill="none"/>
    <circle cx="261" cy="349" r="3.5" fill="#d5c3a8"/><circle cx="261" cy="379" r="3.5" fill="#d5c3a8"/>
    <path d="M196 430l-9 127h66l7-125 7 125h66l-9-127z" fill={base} stroke="#4d4b46" strokeWidth="2"/>
    {decorative&&<path d="M184 212l-32 67 M336 212l32 67 M197 378h35 M287 378h35" stroke="#dbba87" strokeWidth="4"/>}
   </>}
   {garment==="dress"&&<>
    <path d="M215 132l-36 22-47 123 43 19 42-69 9 89-85 240h239l-85-240 9-89 42 69 43-19-47-123-36-22-47 45z" fill={base} stroke="#4d4b46" strokeWidth="2"/>
    <path d="M215 132q45 72 91 0l-46 80z" fill="#eee6da" stroke="#8d7d6a"/>
    <path d="M227 317q32 13 66 0 M141 556q119-19 239 0" stroke={edge} strokeWidth={finish==="Contrast edge"?4:2} fill="none"/>
    <path d="M176 460q85 28 168 0" stroke="#fff" strokeOpacity=".13" strokeWidth="2" fill="none"/>
    {decorative&&<><path d="M221 205q38 15 77 0 M209 349q51 20 102 0" stroke="#e4c092" strokeWidth="4" fill="none"/><circle cx="260" cy="359" r="7" fill="#e4c092"/></>}
   </>}
   {garment==="traditional"&&<>
    <path d="M215 123l-42 23-53 130 49 21 36-76-7 334h124l-7-334 36 76 49-21-53-130-42-23-45 44z" fill={base} stroke="#4d4b46" strokeWidth="2"/>
    <path d="M215 123l45 65 45-65-33 102h-24z" fill="#f0e7d8" stroke="#a08c75"/>
    <path d="M260 213v331 M204 543h112" stroke={edge} strokeWidth={finish==="Contrast edge"?6:3}/>
    <path d="M231 548l-8 37h74l-8-37" fill="#e8ddcc" stroke="#a08c75"/>
    {decorative&&<><path d="M238 234v159 M282 234v159 M219 503h82" stroke="#d8b37b" strokeWidth="5" strokeDasharray="7 9"/><circle cx="260" cy="411" r="8" fill="#d8b37b"/></>}
   </>}
   {garment==="uniform"&&<>
    <path d="M200 138l-50 20-43 118 47 20 40-78v212h132V218l40 78 47-20-43-118-50-20-60 37z" fill={base} stroke="#4d4b46" strokeWidth="2"/>
    <path d="M200 138l60 73 60-73-30 6-30 30-30-30z" fill="#e1d4c1" stroke="#776b5c"/>
    <path d="M194 377h132 M241 227h38" stroke={edge} strokeWidth={finish==="Contrast edge"?5:2}/>
    <rect x="274" y="252" width="40" height="32" rx="3" fill="#eee1cf" fillOpacity=".65"/>
    <path d="M194 430l-8 128h67l7-124 7 124h67l-8-128" fill={base} stroke="#4d4b46" strokeWidth="2"/>
    {decorative&&<path d="M205 310h111 M211 323h100" stroke="#d9b681" strokeWidth="4"/>}
   </>}
   <path d="M80 90h360" stroke="#d1bfa6" strokeOpacity=".4"/>
   <rect x="0" y="0" width="520" height="640" fill="url(#fabricLit)" style={{mixBlendMode:"soft-light"}} pointerEvents="none"/>
  </g>
  <text x="28" y="609" fill="#a28d73" fontSize="11" letterSpacing="3">A ZENITH / ATELIER</text>
  <text x="491" y="609" textAnchor="end" fill="#a28d73" fontSize="11">01 — CONCEPT</text>
 </svg>;
}
