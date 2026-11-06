import { z } from "zod";
export const garmentCategories = ["Menswear", "Womenswear", "Occasionwear", "Traditionalwear", "Uniforms", "Other"] as const;
export const enquirySchema = z.object({
  category: z.enum(garmentCategories),
  garment: z.string().trim().min(3, "Describe the garment (at least 3 characters)").max(160),
  preferences: z.string().trim().min(10, "Please add at least 10 characters about the design").max(1500),
  fit: z.enum(["Regular", "Slim", "Relaxed", "Unsure"]),
  location: z.string().trim().min(2).max(120),
  contactName: z.string().trim().min(2).max(100),
  email: z.email(),
});
export type Enquiry = z.infer<typeof enquirySchema>;
export function makeEnquirySummary(data: Enquiry) {
 return `A Zenith Fashion — NON-BINDING ENQUIRY\nCategory: ${data.category}\nGarment: ${data.garment}\nDesign: ${data.preferences}\nFit: ${data.fit}\nLocation: ${data.location}\nContact: ${data.contactName} (${data.email})\nNo quote, payment or production confirmed.`;
}
