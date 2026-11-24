"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";
import { LandingHeader, LandingFooter } from "@/components/landing-kit";
import { PILLARS, FEATURES, MODULES, STEPS } from "@/lib/landing-data";

// Dedicated Features page — the full feature set in one focused, ad-ready page.

export default function FeaturesPage() {
  const [loggedIn, setLoggedIn] = useState(false);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);
  const cta = loggedIn ? "/dashboard" : "/register";

  return (
    <div className="lph">
      <LandingHeader active="/features" />

      {/* ===== Hero ===== */}
      <section className="lph-hero">
        <div className="lph-hero-in" style={{ gridTemplateColumns: "1fr", textAlign: "center", maxWidth: 820, margin: "0 auto" }}>
          <div className="lph-hero-copy" style={{ margin: "0 auto" }}>
            <span className="lph-pill">All features</span>
            <h1 className="lph-h1">Everything you need to<br /><span className="lph-grad-text">grow locally</span></h1>
            <p className="lph-lead">
              Google reviews, WhatsApp marketing, a leads CRM and an AI content studio — every
              tool your business or agency needs, together in one dashboard.
            </p>
            <div className="lph-hero-cta" style={{ justifyContent: "center" }}>
              <Link href={cta} className="lph-btn lph-btn-grad lph-btn-lg">Get Started Free</Link>
              <Link href="/pricing" className="lph-btn lph-btn-outline lph-btn-lg">See Pricing</Link>
            </div>
          </div>
        </div>
      </section>

      {/* ===== Three pillars ===== */}
      <section className="lph-sec">
        <div className="lph-wrap">
          <div className="lph-eyebrow">THREE CHANNELS</div>
          <h2 className="lph-h2">Google · WhatsApp · Leads</h2>
          <p className="lph-sub">Captured, automated and converted together under one login.</p>
          <div className="lph-pillars">
            {PILLARS.map((p) => (
              <div key={p.title} className="lph-pillar">
                <span className="lph-pillar-tag">{p.tag}</span>
                <div className="lph-pillar-ic" style={{ background: p.bg, color: p.fg }}>{p.icon}</div>
                <h3>{p.title}</h3>
                <p className="lph-pillar-lead">{p.lead}</p>
                <ul className="lph-pillar-list">
                  {p.points.map((x) => <li key={x}>{x}</li>)}
                </ul>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ===== Features grid ===== */}
      <section className="lph-sec lph-sec-soft">
        <div className="lph-wrap">
          <div className="lph-eyebrow">FEATURES</div>
          <h2 className="lph-h2">A complete local-marketing toolkit</h2>
          <p className="lph-sub">All the tools your business or agency needs, in one powerful dashboard.</p>
          <div className="lph-feats">
            {FEATURES.map((f) => (
              <div key={f.title} className="lph-feat">
                <div className="lph-feat-ic" style={{ background: f.bg, color: f.fg }}>{f.icon}</div>
                <h3>{f.title}</h3>
                <p>{f.d}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ===== Modules ===== */}
      <section className="lph-sec">
        <div className="lph-wrap">
          <div className="lph-eyebrow">ONE PLATFORM</div>
          <h2 className="lph-h2">Twelve modules, one login</h2>
          <p className="lph-sub">Everything works together — no juggling tabs, tools or logins.</p>
          <div className="lph-mods">
            {MODULES.map((m) => (
              <div key={m.label} className="lph-mod"><span className="lph-mod-ic">{m.icon}</span>{m.label}</div>
            ))}
          </div>
        </div>
      </section>

      {/* ===== How it works ===== */}
      <section className="lph-sec lph-sec-soft">
        <div className="lph-wrap">
          <div className="lph-eyebrow">HOW IT WORKS</div>
          <h2 className="lph-h2">Live in three simple steps</h2>
          <p className="lph-sub">Connect once, and Eydia runs on autopilot.</p>
          <div className="lph-steps">
            {STEPS.map((s) => (
              <div key={s.n} className="lph-step">
                <div className="lph-step-n">{s.n}</div>
                <h3>{s.t}</h3>
                <p>{s.d}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ===== CTA band ===== */}
      <section className="lph-cta-wrap">
        <div className="lph-wrap">
          <div className="lph-cta">
            <h2>Ready to see it in action?</h2>
            <p>Start free — no credit card required. Explore every feature on the free Starter plan.</p>
            <Link href={cta} className="lph-btn lph-btn-white lph-btn-lg">Start Free Trial</Link>
          </div>
        </div>
      </section>

      <LandingFooter />
    </div>
  );
}
