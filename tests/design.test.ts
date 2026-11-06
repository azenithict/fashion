import { describe,expect,it } from "vitest";
import {designSummary,garmentKinds,fabricShades,fits,finishes} from "../src/lib/design";
describe("design studio commercial boundaries",()=>{
 const design={garment:garmentKinds[0].id,shade:fabricShades[0].id,fit:fits[0],finish:finishes[0],notes:""};
 it("produces a usable garment brief",()=>expect(designSummary(design)).toContain("Tailored Suit"));
 it("does not represent a binding quote",()=>expect(designSummary(design)).toContain("not a production-approved specification or quote"));
 it("rejects unsupported options",()=>expect(()=>designSummary({...design,garment:"invalid" as typeof design.garment})).toThrow());
 it("limits user notes",()=>expect(designSummary({...design,notes:"x".repeat(1000)})).not.toContain("x".repeat(801)));
});
