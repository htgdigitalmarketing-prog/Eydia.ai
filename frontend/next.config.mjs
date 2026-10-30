/** @type {import('next').NextConfig} */

// On constrained shared hosting (e.g. Hostinger / CloudLinux) `next build` can
// hit the process limit (EAGAIN when spawning workers) because it forks several
// worker processes (type-check, lint, static generation). When LIGHT_BUILD=1 we
// skip the type-check/lint workers and use a single worker so the build fits
// under the nproc limit. Code is still fully type-checked on normal builds / CI.
const light = process.env.LIGHT_BUILD === "1";

const nextConfig = {
  reactStrictMode: true,
  output: "export",
  trailingSlash: true,
  images: { unoptimized: true },
  ...(light
    ? {
        eslint: { ignoreDuringBuilds: true },
        typescript: { ignoreBuildErrors: true },
        experimental: { cpus: 1 },
      }
    : {}),
};

export default nextConfig;
