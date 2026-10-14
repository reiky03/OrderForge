import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  experimental: { cpus: 1 },
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
