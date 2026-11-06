import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "A Zenith Fashion | Custom Tailoring",
  description: "Custom tailoring, from personal design to professional craftsmanship.",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="en"><body>{children}</body></html>;
}
