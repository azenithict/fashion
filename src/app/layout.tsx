import type { Metadata } from "next";
import "./globals.css";

const publicLaunch = process.env.NEXT_PUBLIC_PUBLIC_LAUNCH === "true";

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL || "https://fashion.azenith.ca"),
  title: "A Zenith Fashion | Custom Tailoring",
  description: "Custom tailoring, from personal design to professional craftsmanship.",
  alternates: { canonical: "/" },
  robots: publicLaunch ? { index: true, follow: true } : { index: false, follow: false },
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="en"><body>{children}</body></html>;
}
