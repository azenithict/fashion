import type { MetadataRoute } from "next";

export default function robots(): MetadataRoute.Robots {
  const launched = process.env.NEXT_PUBLIC_PUBLIC_LAUNCH === "true";
  return {
    rules: launched ? { userAgent: "*", allow: "/" } : { userAgent: "*", disallow: "/" },
  };
}
