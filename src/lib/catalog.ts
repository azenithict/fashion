import contract from "../../shared/contract.json";
import { label } from "./api";

export const categories = contract.catalogCategories;
export const categoryLabels: Record<string, string> = { mens_tailoring: "Men's Tailoring", womens_custom: "Women's Custom Clothing", occasionwear: "Occasionwear", traditional: "Traditional Clothing", corporate_uniform: "Corporate & Uniform Orders" };
export const garmentLabels: Record<string, string> = { suit: "Suit", dress: "Dress", traditional: "Traditional garment", uniform: "Uniform" };
export const kindLabels: Record<string, string> = { fabric: "Fabric", lining: "Lining", button: "Button", trim: "Trim", embroidery: "Embroidery", closure: "Closure", thread: "Thread" };
export const sourceLabels: Record<string, string> = { original_photography: "Original photography", supplier_authorized: "Supplier authorized", manufacturer_catalog: "Manufacturer catalogue", licensed_editorial: "Licensed editorial", produced_sample: "Produced sample" };
export const publicStatusLabels: Record<string, string> = { available_to_order: "Available to order", inquiry_only: "Enquire — quote only" };
export const humanize = (value: string) => label(value.replaceAll("-", " "));
export const categoryLabel = (key: string) => categoryLabels[key] ?? humanize(key);
export const enquiryHref = (category: string, name: string) => "/enquiry/?service=" + (category === "corporate_uniform" ? "corporate_group" : "design_question") + "&garment=" + encodeURIComponent(name);

export type PublicMedia = { id: string; alt_text: string; width: number; height: number; featured: boolean; editorial: boolean };
export type ProductCard = { slug: string; name: string; category: string; garment: string; status: string; summary: string; featured_media: PublicMedia | null };
export type PublicMaterial = { id: string; kind: string; name: string; composition?: string | null; colour_name?: string | null; colour_hex?: string | null; pattern?: string | null; weight_gsm?: number | null; weave?: string | null; finish?: string | null; stretch?: string | null; care?: string | null; media: PublicMedia[] };
export type ProductDetail = { id: string; slug: string; name: string; category: string; garment: string; status: string; orderable: boolean; description: string; faq: { q: string; a: string }[]; min_order_quantity: number | null; pricing: string; measurement_requirements: string[]; media: PublicMedia[]; materials: PublicMaterial[]; options: { id: string; group: string; label: string; description?: string | null; media: PublicMedia[] }[] };
