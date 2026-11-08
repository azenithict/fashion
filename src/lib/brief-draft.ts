import { designSummary, type DesignSelection } from "./design";
export const briefDraftKey="azenith-brief-v1";
export type BriefDraft={name:string;selection:DesignSelection;savedAt:number};
export function encodeBriefDraft(name:string,selection:DesignSelection,now=Date.now()):string {
 designSummary(selection);
 return JSON.stringify({name:name.trim().slice(0,120),selection:{...selection,notes:selection.notes.slice(0,800)},savedAt:now});
}
export function decodeBriefDraft(raw:string|null,now=Date.now()):BriefDraft|null {
 if(!raw)return null;
 try{const data=JSON.parse(raw);if(typeof data.name!=="string"||data.name.length>120||!Number.isFinite(data.savedAt)||data.savedAt>now||now-data.savedAt>86400000||typeof data.selection?.notes!=="string"||data.selection.notes.length>800)return null;designSummary(data.selection);return {name:data.name,selection:data.selection,savedAt:data.savedAt};}catch{return null;}
}
