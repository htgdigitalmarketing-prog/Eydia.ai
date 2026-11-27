"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";
import { api, PublicPlan, PublicClient, PublicWorkItem, SiteRatings } from "@/lib/api";
import { LandingHeader, LandingFooter } from "@/components/landing-kit";
import { STEPS, PILLARS, FEATURES, MODULES, LP_FEATURE_LABELS, FALLBACK_PLANS } from "@/lib/landing-data";

// ── Brand logos for the "Trusted by" rating cards (full colour) ──────────────
const AppleLogo = () => (
  <svg viewBox="0 0 24 24" width="20" height="20" fill="#111" aria-hidden>
    <path d="M16.365 1.43c0 1.14-.46 2.17-1.2 2.93-.9.92-2.36 1.63-3.56 1.53-.14-1.12.44-2.3 1.14-3.03.84-.88 2.34-1.55 3.62-1.6.01.06.01.11.01.17zM20.8 17.1c-.56 1.3-.83 1.88-1.55 3.03-1 1.6-2.42 3.6-4.17 3.6-1.55.02-1.95-1.01-4.05-1-2.1.01-2.54 1.02-4.1.99-1.75-.03-3.1-1.83-4.1-3.43-2.8-4.5-3.1-9.77-1.37-12.58 1.23-2 3.17-3.17 5-3.17 1.86 0 3.03 1.02 4.57 1.02 1.5 0 2.4-1.02 4.56-1.02 1.63 0 3.36.89 4.59 2.42-4.03 2.21-3.38 7.97.62 9.52z"/>
  </svg>
);
const PlayLogo = () => (
  <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden>
    <path fill="#00D7FE" d="M3.6 2.1c-.3.3-.4.7-.4 1.2v17.4c0 .5.1.9.4 1.2l.06.06 9.75-9.75v-.23L3.66 2.04z"/>
    <path fill="#FFCE00" d="M17 15.33l-3.25-3.25v-.23L17 8.6l.08.05 3.86 2.19c1.1.63 1.1 1.65 0 2.28l-3.86 2.2z"/>
    <path fill="#FF3A44" d="M17.08 15.28L13.75 11.96 3.6 22.1c.36.39.96.44 1.64.05l11.84-6.87"/>
    <path fill="#00F076" d="M17.08 8.65L5.24 1.9c-.68-.4-1.28-.35-1.64.04l10.15 10.02z"/>
  </svg>
);
const GoogleGLogo = () => (
  <svg viewBox="0 0 48 48" width="20" height="20" aria-hidden>
    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
  </svg>
);
const TrustpilotStar = () => (
  <svg viewBox="0 0 24 24" width="20" height="20" fill="#00B67A" aria-hidden>
    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>
  </svg>
);
const MetaLogo = () => (
  <svg viewBox="0 0 36 24" width="26" height="18" fill="#0866ff" aria-hidden>
    <path d="M10.3 3.2C6.4 3.2 4 7.2 4 11.9c0 3.9 1.7 6.9 4.6 6.9 2 0 3.3-1.4 5-4.3.6-1 1.4-2.5 2.2-4l-1.4-2.6c-1.6 3-2.1 3.9-3 5.2-.8 1.2-1.4 1.6-2.2 1.6-1.2 0-2-1.3-2-3.3 0-3 1.3-5.2 3-5.2 1 0 1.8.7 2.9 2.4.8-1.3 1.3-2 1.9-2.7-1.4-1.7-2.7-2.4-4.5-2.4zm15.2 0c-2.3 0-4.1 1.8-5.7 4.3-1.1-1.6-2.1-2.6-3.3-3.4l1.4 2.6c.3.2.6.6 1 1.1-.4.7-.9 1.5-1.4 2.4l-.1.3 1.4 2.6c.3-.6.7-1.3 1-1.9 1.5 2 2.8 3.3 4.8 3.3 3 0 4.8-3.1 4.8-7 0-4.8-2.3-6.9-4.6-6.9zm-.2 3c1.5 0 2.5 2 2.5 4.6 0 2.1-.7 3.4-1.9 3.4-.9 0-1.6-.6-2.7-2.4.8-1.5 1.3-2.3 1.4-2.6 1-1.9 1.9-3 2.6-3z"/>
  </svg>
);

// Eydia.ai marketing homepage — the full overview across the three pillars
// (Google Reviews / GBP, WhatsApp and Leads). Each section also has its own
// dedicated page (/features, /our-work, /pricing, /whatsapp-marketing,
// /google-business-profile) for focused ad campaigns.

export default function Landing() {
  const [loggedIn, setLoggedIn] = useState(false);
  const [livePlans, setLivePlans] = useState<PublicPlan[] | null>(null);
  const [clients, setClients] = useState<PublicClient[]>([]);
  const [work, setWork] = useState<PublicWorkItem[]>([]);
  const [workCat, setWorkCat] = useState<string>("All");
  const [ratings, setRatings] = useState<SiteRatings | null>(null);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);
  useEffect(() => { api.publicPlans().then((r) => setLivePlans(r.plans)).catch(() => setLivePlans(null)); }, []);
  useEffect(() => { api.publicClients().then((r) => setClients(r.clients)).catch(() => setClients([])); }, []);
  useEffect(() => { api.publicWork().then((r) => setWork(r.items)).catch(() => setWork([])); }, []);
  useEffect(() => { api.publicSiteSettings().then((r) => setRatings(r.site_settings.ratings ?? null)).catch(() => {}); }, []);
  const cta = loggedIn ? "/dashboard" : "/register";

  const inr0 = (n: number) => "₹" + Math.round(n).toLocaleString("en-IN");
  // Real plans (from the backend) → pricing cards. Falls back to the static set
  // only until the fetch returns, so the prices always match the admin's plans.
  const pricingCards = (livePlans && livePlans.length > 0)
    ? livePlans.map((p, i) => ({
        name: p.name,
        price: p.price > 0 ? inr0(p.price) : "Free",
        per: p.price > 0 ? "/mo" : "forever",
        credits: `${Number(p.limits?.ai_credits ?? 0).toLocaleString("en-IN")} AI credits / month`,
        popular: livePlans.length >= 3 ? i === Math.floor(livePlans.length / 2) : i === 0,
        cta: p.price > 0 ? "Get Started Now" : "Get Started",
        feats: Object.keys(LP_FEATURE_LABELS).filter((k) => p.features?.[k]).slice(0, 5).map((k) => LP_FEATURE_LABELS[k]),
      }))
    : FALLBACK_PLANS;

  return (
    <div className="lph">
      <LandingHeader active="/" />

      {/* ===== Hero ===== */}
      <section className="lph-hero">
        <div className="lph-hero-in">
          <div className="lph-hero-copy">
            <span className="lph-pill">Reviews · WhatsApp · Leads</span>
            <h1 className="lph-h1">Your customers.<br /><span className="lph-grad-text">Your growth.</span></h1>
            <p className="lph-lead">
              Eydia helps local & multi-location businesses manage Google reviews, WhatsApp
              conversations and leads from one AI-powered dashboard — so you get found, reply
              fast and convert more.
            </p>
            <div className="lph-hero-cta">
              <Link href={cta} className="lph-btn lph-btn-grad lph-btn-lg">Get Started Now</Link>
              <Link href="/login" className="lph-btn lph-btn-outline lph-btn-lg">Sign In</Link>
            </div>
            <div className="lph-hero-trust">
              <span>⭐ 4.8/5 avg client rating</span>
              <span>💬 98% AI reply rate</span>
              <span>✓ No credit card required</span>
            </div>
          </div>

          <div className="lph-hero-visual">
            <div className="lph-hero-glow" />
            <div className="lph-stage">
              <DashboardMock />

              {/* 3D floating channel cards — Google · Meta · Leads */}
              <div className="lph-chip lph-chip-google">
                <span className="lph-chip-ic" style={{ background: "#fff" }}>
                  <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden><path fill="#4285F4" d="M22.5 12.3c0-.8-.1-1.4-.2-2H12v3.8h5.9a5 5 0 0 1-2.2 3.3v2.7h3.5c2-1.9 3.3-4.7 3.3-7.8Z"/><path fill="#34A853" d="M12 23c3 0 5.4-1 7.2-2.7l-3.5-2.7c-1 .7-2.2 1.1-3.7 1.1-2.9 0-5.3-1.9-6.2-4.5H2.2v2.8A11 11 0 0 0 12 23Z"/><path fill="#FBBC05" d="M5.8 14.2a6.6 6.6 0 0 1 0-4.2V7.2H2.2a11 11 0 0 0 0 9.8l3.6-2.8Z"/><path fill="#EA4335" d="M12 5.4c1.6 0 3 .6 4.2 1.7l3.1-3.1A11 11 0 0 0 2.2 7.2l3.6 2.8C6.7 7.3 9.1 5.4 12 5.4Z"/></svg>
                </span>
                <div><div className="lph-chip-t">Google Reviews</div><div className="lph-chip-s">4.8★ · +126 this month</div></div>
              </div>

              <div className="lph-chip lph-chip-meta">
                <span className="lph-chip-ic" style={{ background: "linear-gradient(135deg,#25D366,#128C7E)" }}>
                  <svg viewBox="0 0 24 24" width="17" height="17" fill="#fff" aria-hidden><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.4-.7-2.9-1.1-4.7-4-4.9-4.2-.1-.2-1.1-1.5-1.1-2.8 0-1.3.7-2 .9-2.3.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.4.6c-.2.2-.3.4-.2.7.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.4 2.4 1.5.2.1.4.1.6-.1l.8-1c.2-.2.4-.2.6-.1l1.9.9c.3.1.5.2.5.4.1.2.1.9-.1 1.5Z"/></svg>
                </span>
                <div><div className="lph-chip-t">Meta &amp; WhatsApp</div><div className="lph-chip-s">98% auto-reply rate</div></div>
              </div>

              <div className="lph-chip lph-chip-leads">
                <span className="lph-chip-ic" style={{ background: "linear-gradient(135deg,#7b2ff7,#b000f0)" }}>🎯</span>
                <div><div className="lph-chip-t">Leads captured</div><div className="lph-chip-s">1,240 · +34% this week</div></div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ===== Trusted by: client slider + ratings + partner badges ===== */}
      <section style={{ padding: "48px 0 44px", background: "linear-gradient(180deg,#f4f6ff 0%,#eef2ff 100%)", borderTop: "1px solid #eef1f6" }}>
        <div className="lph-wrap" style={{ textAlign: "center" }}>
          <div className="lph-eyebrow">BUSINESSES GROWING WITH EYDIA</div>
          <h2 className="lph-h2" style={{ fontSize: 34, margin: "8px 0 10px" }}>Trusted by growing businesses.</h2>
          <p className="lph-sub" style={{ margin: "0 auto 26px" }}>From neighbourhood businesses to familiar brands, Eydia helps teams reach customers across India, the UAE, the UK, the USA and beyond.</p>
        </div>

        <div className="lph-wrap" style={{ maxWidth: 1040 }}>
          {clients.length > 0 && (
            <div style={{ background: "#fff", border: "1px solid #eef1f6", borderRadius: 18, padding: "22px 10px", boxShadow: "0 10px 30px rgba(20,25,60,.06)", marginBottom: 20 }}>
              <div className="lph-marquee">
                <div className="lph-marquee-track">
                  {[...clients, ...clients].map((c, i) => {
                    const inner = (
                      <>
                        {c.logo_url
                          ? <img src={c.logo_url} alt={c.name} />
                          : <div style={{ height: 50, display: "grid", placeItems: "center", fontWeight: 800, color: "#5b6bf5", padding: "0 8px" }}>{c.name}</div>}
                        <b>{c.name}</b>
                      </>
                    );
                    return c.website
                      ? <a key={i} href={c.website} target="_blank" rel="noopener noreferrer" className="lph-client" style={{ textDecoration: "none" }}>{inner}</a>
                      : <div key={i} className="lph-client">{inner}</div>;
                  })}
                </div>
              </div>
            </div>
          )}

          {/* Rating cards — real brand logos, numbers managed by Super Admin */}
          <div className="lph-ratings" style={{ display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 16 }}>
            {[
              { key: "app_store" as const, label: "App Store", logo: <AppleLogo />, score: "4.8", count: "247 ratings" },
              { key: "google_play" as const, label: "Google Play", logo: <PlayLogo />, score: "4.6", count: "676 ratings" },
              { key: "google" as const, label: "Google", logo: <GoogleGLogo />, score: "4.8", count: "2,347 reviews" },
              { key: "trustpilot" as const, label: "Trustpilot", logo: <TrustpilotStar />, score: "4.6", count: "176 reviews" },
            ].map((r) => {
              const score = ratings?.[r.key]?.score || r.score;
              const count = ratings?.[r.key]?.count || r.count;
              return (
                <div key={r.key} style={{ background: "#fff", border: "1px solid #eef1f6", borderRadius: 16, padding: "16px 18px", boxShadow: "0 6px 20px rgba(20,25,60,.06)", textAlign: "left" }}>
                  <div style={{ display: "flex", alignItems: "center", gap: 9, marginBottom: 9, fontWeight: 800, fontSize: 14.5 }}>
                    <span style={{ display: "grid", placeItems: "center", width: 32, height: 32, borderRadius: 9, background: "#f5f7ff" }}>{r.logo}</span>
                    {r.label}
                  </div>
                  <div style={{ display: "flex", alignItems: "baseline", gap: 5, flexWrap: "wrap" }}>
                    <span style={{ fontSize: 27, fontWeight: 900 }}>{score}</span>
                    <span className="muted" style={{ fontSize: 12.5 }}>/5</span>
                    <span style={{ color: "#f5a623", marginLeft: 2, letterSpacing: -1 }}>★★★★★</span>
                  </div>
                  <div className="muted" style={{ fontSize: 12.5, marginTop: 3 }}>{count}</div>
                </div>
              );
            })}
          </div>

          {/* Partner badges */}
          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 16, flexWrap: "wrap", borderTop: "1px solid #e3e8f5", marginTop: 26, paddingTop: 20 }}>
            <span className="muted" style={{ fontSize: 14 }}>Eydia is an official <strong style={{ color: "var(--text)" }}>Meta Business Partner</strong> and <strong style={{ color: "var(--text)" }}>Google Partner</strong>.</span>
            <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
              <div style={{ background: "#fff", border: "1px solid #eef1f6", borderRadius: 12, padding: "9px 16px", display: "flex", alignItems: "center", gap: 8, boxShadow: "0 4px 14px rgba(20,25,60,.05)" }}>
                <MetaLogo /><span style={{ fontWeight: 900, color: "#0866ff", fontSize: 15 }}>Meta</span>
                <span className="muted" style={{ fontSize: 11 }}>Business Partner</span>
              </div>
              <div style={{ background: "#fff", border: "1px solid #eef1f6", borderRadius: 12, padding: "9px 16px", display: "flex", alignItems: "center", gap: 8, boxShadow: "0 4px 14px rgba(20,25,60,.05)" }}>
                <span style={{ fontWeight: 900, fontSize: 15 }}><span style={{ color: "#4285F4" }}>G</span><span style={{ color: "#ea4335" }}>o</span><span style={{ color: "#fbbc05" }}>o</span><span style={{ color: "#4285F4" }}>g</span><span style={{ color: "#34a853" }}>l</span><span style={{ color: "#ea4335" }}>e</span></span>
                <span className="muted" style={{ fontSize: 11 }}>Partner</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ===== Three pillars: Google · WhatsApp · Leads ===== */}
      <section id="pillars" className="lph-sec">
        <div className="lph-wrap">
          <div className="lph-eyebrow">WHAT YOU GET</div>
          <h2 className="lph-h2">Three channels, one AI dashboard</h2>
          <p className="lph-sub">Google reviews, WhatsApp conversations and leads — captured, automated and converted together.</p>
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
          <div style={{ textAlign: "center", marginTop: 28, display: "flex", gap: 12, justifyContent: "center", flexWrap: "wrap" }}>
            <Link href="/whatsapp-marketing" className="lph-btn lph-btn-outline">Explore WhatsApp →</Link>
            <Link href="/google-business-profile" className="lph-btn lph-btn-outline">Explore Google Reviews →</Link>
          </div>
        </div>
      </section>

      {/* ===== How it works ===== */}
      <section id="how" className="lph-sec lph-sec-soft">
        <div className="lph-wrap">
          <div className="lph-eyebrow">HOW IT WORKS</div>
          <h2 className="lph-h2">Live in three simple steps</h2>
          <p className="lph-sub">Connect once, and Eydia keeps your Google presence and conversations running on autopilot.</p>
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

      {/* ===== Features ===== */}
      <section id="features" className="lph-sec">
        <div className="lph-wrap">
          <div className="lph-eyebrow">FEATURES</div>
          <h2 className="lph-h2">Everything you need to grow locally</h2>
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
          <div style={{ textAlign: "center", marginTop: 28 }}>
            <Link href="/features" className="lph-btn lph-btn-outline">See all features →</Link>
          </div>
        </div>
      </section>

      {/* ===== One platform ===== */}
      <section className="lph-sec lph-sec-soft">
        <div className="lph-wrap">
          <div className="lph-eyebrow">ONE PLATFORM</div>
          <h2 className="lph-h2">All your local marketing, unified</h2>
          <p className="lph-sub">Twelve powerful modules working together under one login.</p>
          <div className="lph-mods">
            {MODULES.map((m) => (
              <div key={m.label} className="lph-mod"><span className="lph-mod-ic">{m.icon}</span>{m.label}</div>
            ))}
          </div>
        </div>
      </section>

      {/* ===== Our Work ===== */}
      {work.length > 0 && (() => {
        const cats = ["All", ...Array.from(new Set(work.map((w) => w.category)))];
        const shown = workCat === "All" ? work : work.filter((w) => w.category === workCat);
        return (
          <section id="work" className="lph-sec" style={{ background: "#f7f9ff" }}>
            <div className="lph-wrap">
              <div className="lph-eyebrow">OUR WORK</div>
              <h2 className="lph-h2">What we&apos;ve built</h2>
              <p className="lph-sub">Websites, apps, Google &amp; Meta ads, and Google Business Profiles — delivered for real businesses.</p>
              <div style={{ display: "flex", gap: 8, flexWrap: "wrap", justifyContent: "center", margin: "22px 0 28px" }}>
                {cats.map((c) => (
                  <button key={c} onClick={() => setWorkCat(c)}
                    style={{ cursor: "pointer", padding: "8px 16px", borderRadius: 999, fontSize: 13.5, fontWeight: 700,
                      border: `1.5px solid ${workCat === c ? "transparent" : "#dde3f0"}`,
                      background: workCat === c ? "var(--brand-grad, #5b6bf5)" : "#fff",
                      color: workCat === c ? "#fff" : "#334" }}>{c}</button>
                ))}
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(260px, 1fr))", gap: 20 }}>
                {shown.slice(0, 6).map((w, i) => {
                  const card = (
                    <div style={{ background: "#fff", border: "1px solid #eef1f6", borderRadius: 16, overflow: "hidden", boxShadow: "0 6px 20px rgba(20,25,60,.06)", height: "100%", display: "flex", flexDirection: "column" }}>
                      <div style={{ aspectRatio: "16 / 10", background: "#eef1ff", display: "grid", placeItems: "center", overflow: "hidden" }}>
                        {w.image_url
                          // eslint-disable-next-line @next/next/no-img-element
                          ? <img src={w.image_url} alt={w.title} style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                          : <span style={{ fontWeight: 800, color: "#5b6bf5" }}>{w.category}</span>}
                      </div>
                      <div style={{ padding: "14px 16px", flex: 1, display: "flex", flexDirection: "column", gap: 6 }}>
                        <span style={{ alignSelf: "flex-start", fontSize: 11, fontWeight: 800, color: "#5b6bf5", background: "#eef0ff", padding: "2px 9px", borderRadius: 999 }}>{w.category}</span>
                        <div style={{ fontWeight: 800, fontSize: 16, color: "#1f2430" }}>{w.title}</div>
                        {w.description && <p style={{ margin: 0, fontSize: 13.5, color: "#667", lineHeight: 1.5 }}>{w.description}</p>}
                      </div>
                    </div>
                  );
                  return w.link
                    ? <a key={i} href={w.link} target="_blank" rel="noopener noreferrer" style={{ textDecoration: "none" }}>{card}</a>
                    : <div key={i}>{card}</div>;
                })}
              </div>
              <div style={{ textAlign: "center", marginTop: 28 }}>
                <Link href="/our-work" className="lph-btn lph-btn-outline">See all our work →</Link>
              </div>
            </div>
          </section>
        );
      })()}

      {/* ===== Our Clients ===== */}
      {clients.length > 0 && (
        <section id="clients" className="lph-sec">
          <div className="lph-wrap">
            <div className="lph-eyebrow">OUR CLIENTS</div>
            <h2 className="lph-h2">Trusted by growing businesses</h2>
            <p className="lph-sub">Brands and local businesses that grow with Eydia.</p>
            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(150px, 1fr))", gap: 18, marginTop: 32, alignItems: "center" }}>
              {clients.map((c, i) => {
                const inner = c.logo_url
                  // eslint-disable-next-line @next/next/no-img-element
                  ? <img src={c.logo_url} alt={c.name} title={c.name} style={{ maxHeight: 46, maxWidth: "80%", objectFit: "contain", filter: "grayscale(1)", opacity: .85, transition: "all .2s" }} onMouseOver={(e) => { e.currentTarget.style.filter = "none"; e.currentTarget.style.opacity = "1"; }} onMouseOut={(e) => { e.currentTarget.style.filter = "grayscale(1)"; e.currentTarget.style.opacity = ".85"; }} />
                  : <span style={{ fontWeight: 800, fontSize: 16, color: "#334" }}>{c.name}</span>;
                return (
                  <div key={i} style={{ height: 90, display: "grid", placeItems: "center", background: "#fff", border: "1px solid #eef1f6", borderRadius: 14, padding: 14, boxShadow: "0 4px 14px rgba(20,25,60,.04)" }}>
                    {c.website ? <a href={c.website} target="_blank" rel="noopener noreferrer" style={{ display: "grid", placeItems: "center", width: "100%", height: "100%" }}>{inner}</a> : inner}
                  </div>
                );
              })}
            </div>
          </div>
        </section>
      )}

      {/* ===== Pricing ===== */}
      <section id="pricing" className="lph-sec">
        <div className="lph-wrap">
          <div className="lph-eyebrow">PRICING</div>
          <h2 className="lph-h2">Simple, transparent plans</h2>
          <p className="lph-sub">Start free, upgrade when you grow. Every plan includes monthly AI credits.</p>
          <div className="lph-plans">
            {pricingCards.map((p) => (
              <div key={p.name} className={`lph-plan ${p.popular ? "pop" : ""}`}>
                {p.popular && <span className="lph-plan-tag">Most Popular</span>}
                <div className="lph-plan-name">{p.name}</div>
                <div className="lph-plan-price">{p.price}<span>{p.per}</span></div>
                <div className="lph-plan-credits">{p.credits}</div>
                <ul className="lph-plan-feats">
                  {p.feats.map((x) => <li key={x}>✓ {x}</li>)}
                </ul>
                <Link href={cta} className={`lph-btn ${p.popular ? "lph-btn-grad" : "lph-btn-outline"} lph-btn-block`}>{p.cta}</Link>
              </div>
            ))}
          </div>
          <div style={{ textAlign: "center", marginTop: 28 }}>
            <Link href="/pricing" className="lph-btn lph-btn-outline">See full pricing →</Link>
          </div>
        </div>
      </section>

      {/* ===== CTA band ===== */}
      <section className="lph-cta-wrap">
        <div className="lph-wrap">
          <div className="lph-cta">
            <h2>Ready to grow your local business?</h2>
            <p>Join thousands of businesses & agencies managing their Google, WhatsApp and leads with Eydia.</p>
            <Link href={cta} className="lph-btn lph-btn-white lph-btn-lg">Get Started Now</Link>
          </div>
        </div>
      </section>

      <LandingFooter />
    </div>
  );
}

/* Hero dashboard mockup — "Advanced Reports" card reflecting Google, WhatsApp & Leads. */
function DashboardMock() {
  const bars = [38, 46, 42, 58, 66, 72, 68, 82];
  return (
    <div className="lph-mock">
      <div className="lph-mock-head">
        <div className="lph-mock-dots"><span /><span /><span /></div>
        <span className="lph-mock-title">Advanced Reports</span>
      </div>
      <div className="lph-mock-stats">
        <div className="lph-mock-stat"><span className="k">AVG RATING</span><b>4.83</b></div>
        <div className="lph-mock-stat"><span className="k">REVIEWS</span><b>1,862</b></div>
        <div className="lph-mock-stat"><span className="k">REPLY RATE</span><b className="pos">98%</b></div>
        <div className="lph-mock-stat"><span className="k">NEW LEADS</span><b className="pos">+34%</b></div>
      </div>
      <div className="lph-mock-trend">
        <span className="lbl">RATINGS &amp; LEADS TREND</span>
        <div className="lph-mock-bars">
          {bars.map((h, i) => <div key={i} style={{ height: `${h}%` }} />)}
        </div>
      </div>
    </div>
  );
}
