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

// ---- Guest enquiry form (POST /enquiries) ----
export const enquiryServices = ["custom_tailoring", "corporate_group", "consultation", "design_question", "alterations", "general"] as const;
export type EnquiryService = (typeof enquiryServices)[number];
export const enquiryServiceLabels: Record<EnquiryService, string> = {
  custom_tailoring: "Custom tailoring", corporate_group: "Corporate & group order", consultation: "Consultation",
  design_question: "Question about a design", alterations: "Alterations & fitting", general: "General question",
};
export const enquiryStatuses = ["new", "awaiting_details", "reviewing", "quotation_ready", "converted", "closed"] as const;
export const enquiryStatusLabels: Record<string, string> = {
  new: "New", awaiting_details: "Awaiting details", reviewing: "Reviewing", quotation_ready: "Quotation ready", converted: "Converted", closed: "Closed",
};
export const isEnquiryService = (v: string | null): v is EnquiryService => !!v && (enquiryServices as readonly string[]).includes(v);
export type EnquiryForm = {
  service: EnquiryService; name: string; email: string; contact_method: "email" | "phone"; phone: string;
  garment: string; quantity: string; deadline: string; location: string; requirements: string; consent: boolean;
};
export type EnquiryErrors = Partial<Record<keyof EnquiryForm, string>>;
const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
export function validateEnquiry(f: EnquiryForm): EnquiryErrors {
  const e: EnquiryErrors = {};
  if (!f.name.trim()) e.name = "Enter your name."; else if (f.name.trim().length > 100) e.name = "Use 100 characters or fewer.";
  if (!emailRe.test(f.email.trim())) e.email = "Enter a valid email address.";
  if (f.contact_method === "phone" && f.phone.replace(/\D/g, "").length < 7) e.phone = "Enter a phone number we can reach you on.";
  if (f.service === "corporate_group") {
    const q = Number(f.quantity);
    if (!Number.isInteger(q) || q < 1 || q > 10000) e.quantity = "Enter a whole number from 1 to 10,000.";
  }
  const r = f.requirements.trim().length;
  if (r < 10) e.requirements = "Please write at least 10 characters."; else if (r > 3000) e.requirements = "Use 3,000 characters or fewer.";
  if (!f.consent) e.consent = "Please agree so we can respond to your request.";
  return e;
}
export function buildEnquiryPayload(f: EnquiryForm, honeypot: string, source: Record<string, string>) {
  const p: Record<string, unknown> = { service: f.service, name: f.name.trim(), email: f.email.trim(), contact_method: f.contact_method, requirements: f.requirements.trim(), consent: true, website: honeypot, source };
  if (f.contact_method === "phone" && f.phone.trim()) p.phone = f.phone.trim();
  if (f.garment.trim()) p.garment = f.garment.trim();
  if (f.service === "corporate_group" && f.quantity) p.quantity = Number(f.quantity);
  if (f.deadline) p.deadline = f.deadline;
  if (f.location.trim()) p.location = f.location.trim();
  return p;
}
