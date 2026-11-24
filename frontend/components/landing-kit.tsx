"use client";

import { useEffect, useState } from "react";
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
        <span className="lph-foot-copy">© {year} Eydia.ai. All rights reserved.</span>
      </div>
    </footer>
  );
}
