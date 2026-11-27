"use client";

import { useEffect, useState, type CSSProperties } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";
import { api } from "@/lib/api";

// Shared header + footer for every public landing page, so the navigation is
// identical across Home, WhatsApp, Google, Features, Our Work and Pricing.

// ─── Site contact details — UPDATE THESE with your real values ───────────────
const SITE = {
  email: "heltogtechnologies@gmail.com",
  phone: "+91 93119 15615",            // ← update with your real phone
  whatsapp: "919311915615",            // ← update: WhatsApp number (no +, country code first)
  address: "Nehru Place, New Delhi, 110019, India", // ← update
  facebook: "https://www.facebook.com/eydia.ai",     // ← update
  instagram: "https://www.instagram.com/eydia.ai",   // ← update
};

type SiteInfo = typeof SITE;

// Live footer/contact details (Super Admin → Settings). Cached across the
// session; falls back to the SITE defaults above until/if the fetch returns.
let siteCache: SiteInfo | null = null;
function useSite(): SiteInfo {
  const [site, setSite] = useState<SiteInfo>(siteCache ?? SITE);
  useEffect(() => {
    if (siteCache) return;
    let on = true;
    api.publicSiteSettings().then((res) => {
      const s = res.site_settings || ({} as Partial<SiteInfo>);
      const merged: SiteInfo = {
        email: s.email || SITE.email,
        phone: s.phone || SITE.phone,
        whatsapp: s.whatsapp || SITE.whatsapp,
        address: s.address || SITE.address,
        facebook: s.facebook || SITE.facebook,
        instagram: s.instagram || SITE.instagram,
      };
      siteCache = merged;
      if (on) setSite(merged);
    }).catch(() => {});
    return () => { on = false; };
  }, []);
  return site;
}

const NAV = [
  { href: "/", label: "Home" },
  { href: "/whatsapp-marketing", label: "WhatsApp" },
  { href: "/google-business-profile", label: "GBP AI Master" },
  { href: "/features", label: "Features" },
  { href: "/our-work", label: "Our Work" },
  { href: "/pricing", label: "Pricing" },
];

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

/** Floating WhatsApp chat button — bottom-right of every landing page. */
export function WhatsAppWidget() {
  const site = useSite();
  return (
    <a
      href={`https://wa.me/${site.whatsapp}?text=${encodeURIComponent("Hi Eydia, I'd like to know more.")}`}
      target="_blank" rel="noopener noreferrer" aria-label="Chat on WhatsApp"
      style={{
        position: "fixed", right: 20, bottom: 20, zIndex: 300, width: 58, height: 58, borderRadius: "50%",
        background: "linear-gradient(135deg,#25D366,#128C7E)", display: "grid", placeItems: "center",
        boxShadow: "0 10px 26px rgba(18,140,126,.45)", animation: "eydiaWaPulse 2.4s ease-in-out infinite",
      }}
    >
      <svg viewBox="0 0 24 24" width="30" height="30" fill="#fff" aria-hidden><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.4-.7-2.9-1.1-4.7-4-4.9-4.2-.1-.2-1.1-1.5-1.1-2.8 0-1.3.7-2 .9-2.3.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.4.6c-.2.2-.3.4-.2.7.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.4 2.4 1.5.2.1.4.1.6-.1l.8-1c.2-.2.4-.2.6-.1l1.9.9c.3.1.5.2.5.4.1.2.1.9-.1 1.5Z"/></svg>
    </a>
  );
}

export function LandingHeader({ active }: { active?: string }) {
  const [loggedIn, setLoggedIn] = useState(false);
  const [open, setOpen] = useState(false);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);

  return (
    <header className="lph-head" style={{ position: "sticky", top: 0, zIndex: 200, background: "#fff", backdropFilter: "saturate(180%) blur(6px)", borderBottom: "1px solid #eef1f6" }}>
      <div className="lph-head-in">
        <Link href="/" className="lph-brand"><img src="/eydia-logo.png" alt="Eydia.ai" /></Link>
        <nav className="lph-nav">
          {NAV.map((n) => (
            <Link key={n.href} href={n.href}
              style={active === n.href ? { color: "var(--primary, #2160ef)", fontWeight: 700 } : undefined}>
              {n.label}
            </Link>
          ))}
          <span className="lph-nav-badges"><StoreBadges compact /></span>
          <Link href={loggedIn ? "/dashboard" : "/login"} className="lph-btn lph-btn-grad lph-btn-sm">
            {loggedIn ? "Open app" : "Login"}
          </Link>
          <button className="lph-burger" aria-label="Menu" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
            {open ? "✕" : "☰"}
          </button>
        </nav>
      </div>
      <nav className={`lph-mnav ${open ? "open" : ""}`}>
        {NAV.map((n) => (
          <Link key={n.href} href={n.href} onClick={() => setOpen(false)}
            style={active === n.href ? { color: "var(--primary, #2160ef)" } : undefined}>
            {n.label}
          </Link>
        ))}
        <div style={{ paddingTop: 10 }}><StoreBadges /></div>
      </nav>
    </header>
  );
}

const FbIcon = () => (
  <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden><path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12Z"/></svg>
);
const IgIcon = () => (
  <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden><path d="M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.8.3 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.2.4.4 1 .4 2.2.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c-.1 1.2-.3 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.2-1 .4-2.2.4-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2-.1-1.8-.3-2.2-.4-.6-.2-1-.5-1.4-.9-.4-.4-.7-.8-.9-1.4-.2-.4-.4-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.9c.1-1.2.3-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.2 1-.4 2.2-.4C8.4 2.2 8.8 2.2 12 2.2Zm0 1.8c-3.1 0-3.5 0-4.7.1-1.1.1-1.7.2-2.1.4-.5.2-.9.4-1.3.8-.4.4-.6.8-.8 1.3-.2.4-.3 1-.4 2.1-.1 1.2-.1 1.6-.1 4.7s0 3.5.1 4.7c.1 1.1.2 1.7.4 2.1.2.5.4.9.8 1.3.4.4.8.6 1.3.8.4.2 1 .3 2.1.4 1.2.1 1.6.1 4.7.1s3.5 0 4.7-.1c1.1-.1 1.7-.2 2.1-.4.5-.2.9-.4 1.3-.8.4-.4.6-.8.8-1.3.2-.4.3-1 .4-2.1.1-1.2.1-1.6.1-4.7s0-3.5-.1-4.7c-.1-1.1-.2-1.7-.4-2.1-.2-.5-.4-.9-.8-1.3-.4-.4-.8-.6-1.3-.8-.4-.2-1-.3-2.1-.4-1.2-.1-1.6-.1-4.7-.1Zm0 3.1a4.9 4.9 0 1 1 0 9.8 4.9 4.9 0 0 1 0-9.8Zm0 8.1a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Zm6.3-8.3a1.15 1.15 0 1 1-2.3 0 1.15 1.15 0 0 1 2.3 0Z"/></svg>
);

export function LandingFooter() {
  const site = useSite();
  const year = new Date().getFullYear();
  const social: CSSProperties = { width: 36, height: 36, borderRadius: 10, display: "grid", placeItems: "center", background: "rgba(255,255,255,.12)", color: "#fff" };
  return (
    <>
      <WhatsAppWidget />
      <footer className="lph-foot2">
        <div className="lph-wrap lph-foot2-grid">
          {/* Brand + content + social + apps */}
          <div className="lph-foot2-brand">
            <Link href="/" style={{ display: "inline-block" }}><img src="/eydia-logo.png" alt="Eydia.ai" style={{ height: 52 }} /></Link>
            <p style={{ color: "rgba(255,255,255,.72)", fontSize: 13.5, lineHeight: 1.65, margin: "12px 0 16px", maxWidth: 320 }}>
              Eydia helps local &amp; multi-location businesses manage Google reviews, WhatsApp conversations and leads from one AI-powered dashboard — get found, reply fast and convert more.
            </p>
            <div style={{ display: "flex", gap: 10, marginBottom: 16 }}>
              <a href={site.facebook} target="_blank" rel="noopener noreferrer" aria-label="Facebook" style={social}><FbIcon /></a>
              <a href={site.instagram} target="_blank" rel="noopener noreferrer" aria-label="Instagram" style={social}><IgIcon /></a>
              <a href={`https://wa.me/${site.whatsapp}`} target="_blank" rel="noopener noreferrer" aria-label="WhatsApp" style={social}>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.4-.7-2.9-1.1-4.7-4-4.9-4.2-.1-.2-1.1-1.5-1.1-2.8 0-1.3.7-2 .9-2.3.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.4.6c-.2.2-.3.4-.2.7.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.4 2.4 1.5.2.1.4.1.6-.1l.8-1c.2-.2.4-.2.6-.1l1.9.9c.3.1.5.2.5.4.1.2.1.9-.1 1.5Z"/></svg>
              </a>
            </div>
            <StoreBadges />
          </div>

          <div className="lph-foot2-col">
            <h4>Features</h4>
            <Link href="/whatsapp-marketing">WhatsApp Marketing</Link>
            <Link href="/google-business-profile">Google Reviews</Link>
            <Link href="/features">All Features</Link>
            <Link href="/pricing">Pricing</Link>
          </div>

          <div className="lph-foot2-col">
            <h4>Company</h4>
            <Link href="/about">About Us</Link>
            <Link href="/our-work">Our Work</Link>
            <Link href="/contact">Contact Us</Link>
            <Link href="/#clients">Our Clients</Link>
          </div>

          <div className="lph-foot2-col">
            <h4>Pages</h4>
            <Link href="/terms">Terms &amp; Conditions</Link>
            <Link href="/privacy">Privacy Policy</Link>
            <Link href="/pricing-policy">Pricing Policy</Link>
            <Link href="/cancellation-policy">Cancellation / Refund</Link>
            <Link href="/shipping-policy">Shipping Policy</Link>
          </div>

          <div className="lph-foot2-col">
            <h4>Contact Us</h4>
            <a href={`mailto:${site.email}`}>✉ {site.email}</a>
            <a href={`tel:${site.phone.replace(/\s/g, "")}`}>📞 {site.phone}</a>
            <a href={`https://wa.me/${site.whatsapp}`} target="_blank" rel="noopener noreferrer">💬 WhatsApp us</a>
            <span style={{ color: "rgba(255,255,255,.72)", fontSize: 13, lineHeight: 1.6, display: "block", marginTop: 4 }}>📍 {site.address}</span>
          </div>
        </div>
        <div className="lph-foot2-bottom">
          <span>© {year} Eydia.ai · All rights reserved.</span>
          <span style={{ opacity: .75 }}>Automate · Engage · Grow</span>
        </div>
      </footer>
    </>
  );
}
