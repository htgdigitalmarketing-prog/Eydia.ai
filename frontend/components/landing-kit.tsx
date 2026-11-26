"use client";

import { useEffect, useState, type CSSProperties } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";

// Shared header + footer for every public landing page, so the navigation is
// identical across Home, WhatsApp, Google, Features, Our Work and Pricing —
// important when running ads that drop visitors onto any one of them.

const NAV = [
  { href: "/", label: "Home" },
  { href: "/whatsapp-marketing", label: "WhatsApp" },
  { href: "/google-business-profile", label: "Google Reviews" },
  { href: "/features", label: "Features" },
  { href: "/our-work", label: "Our Work" },
  { href: "/pricing", label: "Pricing" },
];

// Eydia mobile apps.
const APP_IOS = "https://apps.apple.com/in/app/eydia/id6834357608";
const APP_ANDROID = "https://play.google.com/store/apps/details?id=com.heltog.review_flow";

export function StoreBadges({ compact }: { compact?: boolean }) {
  const base: CSSProperties = {
    display: "inline-flex", alignItems: "center", gap: 7, textDecoration: "none",
    color: "#fff", borderRadius: 10, fontWeight: 700, whiteSpace: "nowrap",
    padding: compact ? "6px 11px" : "9px 15px", fontSize: compact ? 12 : 13.5,
  };
  const sz = compact ? 14 : 17;
  return (
    <div style={{ display: "inline-flex", gap: 8, flexWrap: "wrap" }}>
      <a href={APP_IOS} target="_blank" rel="noopener noreferrer" style={{ ...base, background: "#111" }} aria-label="Download Eydia on the App Store">
        <svg width={sz} height={sz} viewBox="0 0 384 512" fill="#fff" aria-hidden><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>
        {compact ? "iOS App" : "App Store"}
      </a>
      <a href={APP_ANDROID} target="_blank" rel="noopener noreferrer" style={{ ...base, background: "#0f9d58" }} aria-label="Get Eydia on Google Play">
        <svg width={sz} height={sz} viewBox="0 0 512 512" fill="#fff" aria-hidden><path d="M325.3 234.3 104.6 13l280.8 161.2-60.1 60.1zM47 0C34 6.8 25.3 19.2 25.3 35.3v441.3c0 16.1 8.7 28.5 21.7 35.3l256.6-256L47 0zm425.2 225.6-58.9-34.1-65.7 64.5 65.7 64.5 60.1-34.1c18-14.3 18-46.5-1.2-60.8zM104.6 499l220.7-221.3 60.1 60.1L104.6 499z"/></svg>
        {compact ? "Android" : "Google Play"}
      </a>
    </div>
  );
}

export function LandingHeader({ active }: { active?: string }) {
  const [loggedIn, setLoggedIn] = useState(false);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);

  return (
    <header className="lph-head">
      <div className="lph-head-in">
        <Link href="/" className="lph-brand"><img src="/eydia-logo.png" alt="Eydia.ai" /></Link>
        <nav className="lph-nav">
          {NAV.map((n) => (
            <Link key={n.href} href={n.href}
              style={active === n.href ? { color: "var(--primary, #2160ef)", fontWeight: 700 } : undefined}>
              {n.label}
            </Link>
          ))}
          <StoreBadges compact />
          <Link href={loggedIn ? "/dashboard" : "/login"} className="lph-btn lph-btn-grad lph-btn-sm">
            {loggedIn ? "Open app" : "Login"}
          </Link>
        </nav>
      </div>
    </header>
  );
}

export function LandingFooter() {
  const year = new Date().getFullYear();
  return (
    <footer className="lph-foot">
      <div className="lph-wrap lph-foot-in">
        <Link href="/" className="lph-foot-brand"><img src="/eydia-logo.png" alt="Eydia.ai" /></Link>
        <nav className="lph-foot-links">
          <Link href="/whatsapp-marketing">WhatsApp</Link>
          <Link href="/google-business-profile">Google Reviews</Link>
          <Link href="/features">Features</Link>
          <Link href="/our-work">Our Work</Link>
          <Link href="/pricing">Pricing</Link>
          <Link href="/pricing-policy">Pricing Policy</Link>
          <Link href="/terms">Terms &amp; Conditions</Link>
          <Link href="/privacy">Privacy Policy</Link>
          <Link href="/cancellation-policy">Cancellation / Refund</Link>
        </nav>
        {/* Get the Eydia mobile app */}
        <div style={{ display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap", justifyContent: "center" }}>
          <span style={{ fontSize: 13, fontWeight: 700, opacity: 0.85 }}>📱 Get the Eydia app:</span>
          <StoreBadges />
        </div>
        <span className="lph-foot-copy">© {year} Eydia.ai. All rights reserved.</span>
      </div>
    </footer>
  );
}
