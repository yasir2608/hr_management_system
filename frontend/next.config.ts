import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // The staff console is served on admin.localhost in development (see src/proxy.ts).
  allowedDevOrigins: ["admin.localhost"],
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
