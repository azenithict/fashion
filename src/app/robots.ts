import type { MetadataRoute } from "next";

// Static export requires metadata routes to declare static generation.
export const dynamic = "force-static";

export default function robots(): MetadataRoute.Robots {
  const launched = process.env.NEXT_PUBLIC_PUBLIC_LAUNCH === "true";
  return {
    rules: launched ? { userAgent: "*", allow: "/" } : { userAgent: "*", disallow: "/" },
  };
}
