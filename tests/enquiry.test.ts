import { describe, expect, it } from "vitest";
import { enquirySchema, makeEnquirySummary } from "../src/lib/enquiry";
const valid = {category:"Menswear",garment:"Wedding suit",preferences:"Deep green fabric, classic lapels",fit:"Regular",location:"Toronto",contactName:"Alex Example",email:"alex@example.com"};
describe("non-binding custom tailoring enquiry",()=>{
 it("accepts a valid design",()=>expect(enquirySchema.safeParse(valid).success).toBe(true));
 it("rejects unsupported categories",()=>expect(enquirySchema.safeParse({...valid,category:"anything"}).success).toBe(false));
 it("rejects invalid contact information",()=>expect(enquirySchema.safeParse({...valid,email:"not-email"}).success).toBe(false));
 it("labels summaries as non-binding",()=>expect(makeEnquirySummary(valid as Parameters<typeof makeEnquirySummary>[0])).toContain("No quote, payment or production confirmed."));
});
