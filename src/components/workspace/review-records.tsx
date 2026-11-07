"use client";
import { useState } from "react";
import { api, row, rows, text, type Row } from "@/lib/api";
import { ActionForm, Field, RecordFacts, ResourceState, useResource, value } from "./common";
export function DesignReferences({id}:{id:string}) {
 const [open,setOpen]=useState(false);
 return <details onToggle={event=>setOpen(event.currentTarget.open)}><summary>Private reference attachments</summary>{open&&<References id={id}/>}</details>;
}
function References({id}:{id:string}) {
 const resource=useResource<Row>("/designs/"+id);
 const attachments=rows(resource.data?.uploads);
 return <><ResourceState {...resource} empty={!attachments.length} onRetry={resource.refresh}/>{attachments.map(upload=><p key={text(upload.id)}><a className="text-link" href={"/api/v1/uploads/"+encodeURIComponent(text(upload.id))} target="_blank" rel="noreferrer">{text(upload.original_name,"Reference attachment")} ↗</a></p>)}</>;
}
export function MeasurementReview({id,onDone}:{id:string;onDone:()=>void}) {
 const resource=useResource<Row>("/measurements/"+id);
 return <div><h4>Measurement review</h4><ResourceState {...resource} empty={false} onRetry={resource.refresh}/>{resource.data&&<><RecordFacts record={resource.data} fields={["name","garment","unit","provenance","consent_at","created_at","verified_at","verification_evidence"]}/><RecordFacts record={row(resource.data.values)} fields={Object.keys(row(resource.data.values))}/>{!resource.data.verified_at&&<ActionForm title="Verify measurements" submitLabel="Record verification" onSubmit={data=>api("/measurements/"+id+"/verify","POST",{evidence:value(data,"evidence")})} onDone={()=>{resource.refresh();onDone();}}><Field title="Professional measurement review evidence" name="evidence" type="textarea"/><p className="hint">Record the actual professional review, operator and source document. Do not verify measurements solely to bypass this check.</p></ActionForm>}</>}</div>;
}
