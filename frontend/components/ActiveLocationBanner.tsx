"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getActiveLocation, ActiveLoc } from "@/lib/activeLocation";

/**
 * Prominent "you are managing THIS location" indicator for every GBP module
 * (Business Info, Automation, SEO, AI Studio, Brand Kit). Reads the shared
 * active location and updates live when it changes in another tab/page.
 */
export function ActiveLocationBanner({ module }: { module?: string }) {
  const [loc, setLoc] = useState<ActiveLoc>(null);

  useEffect(() => {
    setLoc(getActiveLocation());
    const refresh = () => setLoc(getActiveLocation());
    window.addEventListener("eydia:active-location", refresh);
    window.addEventListener("storage", refresh);
    return () => {
      window.removeEventListener("eydia:active-location", refresh);
      window.removeEventListener("storage", refresh);
    };
  }, []);

  if (!loc) {
    return (
      <div style={{ display: "flex", alignItems: "center", gap: 10, padding: "10px 14px", borderRadius: 12, background: "#fff6f5", border: "1px solid #f3d6d2", marginBottom: 14, flexWrap: "wrap" }}>
        <span style={{ fontSize: 13, color: "#a93226", flex: 1, minWidth: 180 }}>
          No active location selected. Pick one on the Reviews dashboard — this module manages that location only.
        </span>
        <Link href="/gbp" className="btn-mini" style={{ background: "var(--accent)", color: "#fff", border: "none", textDecoration: "none" }}>Choose location →</Link>
      </div>
    );
  }

  return (
    <div style={{ display: "flex", alignItems: "center", gap: 12, padding: "11px 16px", borderRadius: 12, marginBottom: 14, flexWrap: "wrap", background: "var(--brand-grad, var(--accent))", color: "#fff", boxShadow: "0 6px 18px color-mix(in srgb, var(--accent) 25%, transparent)" }}>
      <span style={{ fontSize: 18 }}>📍</span>
      <div style={{ flex: 1, minWidth: 160 }}>
        <div style={{ fontSize: 10.5, fontWeight: 700, opacity: .85, letterSpacing: ".4px" }}>ACTIVE LOCATION{module ? ` · ${module.toUpperCase()}` : ""}</div>
        <div style={{ fontSize: 15, fontWeight: 800 }}>{loc.name || "Selected location"}</div>
      </div>
      <Link href="/gbp" style={{ background: "rgba(255,255,255,.18)", border: "1px solid rgba(255,255,255,.4)", borderRadius: 10, padding: "7px 14px", fontSize: 12.5, fontWeight: 800, color: "#fff", textDecoration: "none", whiteSpace: "nowrap" }}>🔁 Switch location</Link>
    </div>
  );
}
