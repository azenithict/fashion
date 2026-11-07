import {describe,it,expect} from 'vitest';
import {decodeBriefDraft,encodeBriefDraft} from '../src/lib/brief-draft';
const selection={garment:'suit',shade:'midnight',fit:'Tailored',finish:'Minimal',notes:'Wedding in autumn'} as const;
describe('brief continuation across authentication',()=>{
 it('keeps the name and actual selections through a serialized round trip',()=>{expect(decodeBriefDraft(encodeBriefDraft(' Wedding ',selection,1000),2000)).toEqual({name:'Wedding',selection,savedAt:1000});});
 it('expires old drafts and rejects future timestamps',()=>{expect(decodeBriefDraft(encodeBriefDraft('Wedding',selection,1000),86401001)).toBeNull();expect(decodeBriefDraft(encodeBriefDraft('Wedding',selection,2000),1000)).toBeNull();});
 it('rejects corrupt or unsupported selections rather than restoring them',()=>{expect(decodeBriefDraft('{')).toBeNull();expect(decodeBriefDraft(JSON.stringify({name:'x',savedAt:1000,selection:{...selection,garment:'fake'}}),2000)).toBeNull();});
});
