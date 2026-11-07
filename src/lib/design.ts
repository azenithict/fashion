import contract from "../../shared/contract.json";
export const garmentKinds = [
  { id: "suit", title: "Tailored Suit", group: "Menswear", desc: "Jacket and trousers, made for a considered fit." },
  { id: "dress", title: "Custom Dress", group: "Womenswear", desc: "A personal silhouette for everyday or occasion." },
  { id: "traditional", title: "Traditional Wear", group: "Heritage", desc: "Details and craftsmanship shaped by your brief." },
  { id: "uniform", title: "Uniform & Team", group: "Business", desc: "An enquiry pathway for coordinated garments." },
] as const;
export type GarmentKind = (typeof garmentKinds)[number]["id"];
export const fabricShades = [
  { id:"midnight", label:"Midnight", hex:"#253747" },
  { id:"forest", label:"Forest", hex:"#2f5b4c" },
  { id:"oxblood", label:"Oxblood", hex:"#713a3d" },
  { id:"stone", label:"Stone", hex:"#a79782" },
  { id:"black", label:"Black", hex:"#2d2d32" },
  { id:"ivory", label:"Ivory", hex:"#d9cec0" },
] as const;
export type FabricShade = (typeof fabricShades)[number]["id"];
export const fits = ["Structured", "Tailored", "Relaxed"] as const;
export type Fit = (typeof fits)[number];
export const finishes = ["Minimal", "Contrast edge", "Decorative detail"] as const;
export type Finish = (typeof finishes)[number];
export type DesignSelection = {
  garment: GarmentKind;
  shade: FabricShade;
  fit: Fit;
  finish: Finish;
  notes: string;
};
export function designSummary(input: DesignSelection) {
  const garment = garmentKinds.find(g=>g.id===input.garment);
  const shade = fabricShades.find(s=>s.id===input.shade);
  if (!garment || !shade || !contract.garments.includes(input.garment) || !contract.shades.includes(input.shade) || !contract.fits.includes(input.fit) || !contract.finishes.includes(input.finish)) {
    throw new Error("Unsupported design selection");
  }
  const cleanNotes = input.notes.trim().slice(0,800);
  return [
    "A ZENITH FASHION — CUSTOM TAILORING ENQUIRY",
    "",
    "Garment: " + garment.title,
    "Colour direction: " + shade.label,
    "Fit direction: " + input.fit,
    "Design detail: " + input.finish,
    "Additional notes: " + (cleanNotes || "To discuss"),
    "",
    "This is a design brief, not a production-approved specification or quote.",
    "Fabric, measurements, availability, pricing and lead time require professional review.",
  ].join("\n");
}
