import type { Metadata } from "next";
import "./globals.css";
import "@fontsource/dm-sans/400.css";
import "@fontsource/dm-sans/500.css";
import "@fontsource/dm-sans/600.css";
import "@fontsource/dm-sans/700.css";
import "@fontsource/playfair-display/400.css";
import "@fontsource/playfair-display/500.css";
import "@fontsource/playfair-display/600.css";
import "@fontsource/playfair-display/400-italic.css";
import "@fontsource/playfair-display/500-italic.css";

const previewPath = process.env.NEXT_PUBLIC_BASE_PATH || "";
const publicLaunch = process.env.NEXT_PUBLIC_PUBLIC_LAUNCH === "true" && !previewPath;

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL || "https://fashion.azenith.ca"),
  title: "A Zenith Fashion | Custom Tailoring",
  description: "Custom tailoring, from personal design to professional craftsmanship.",
  alternates: { canonical: "/" },
  robots: publicLaunch ? { index: true, follow: true } : { index: false, follow: false },
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="en"><body>{previewPath && <aside className="private-preview-banner" aria-label="Private preview"><span>Private preview — the public website is unchanged.</span><a href={previewPath+"/_gate/lock"}>Lock preview</a></aside>}{children}</body></html>;
}
