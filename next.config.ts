import type { NextConfig } from "next";
const basePath = process.env.NEXT_PUBLIC_BASE_PATH || "";
if (basePath && !/^\/[A-Za-z0-9_-]+$/.test(basePath)) throw new Error("Invalid public base path");
const nextConfig: NextConfig = {
  basePath,
  output: "export",
  trailingSlash: true,
  images: { unoptimized: true },
};
export default nextConfig;
