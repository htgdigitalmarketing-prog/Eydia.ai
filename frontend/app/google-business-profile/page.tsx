"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";
import { api, PublicPlan } from "@/lib/api";
import { LandingHeader, LandingFooter } from "@/components/landing-kit";

// Dedicated Google Business Profile landing page — Google-only content + only the
// plans that include the GBP dashboard. Reuses the shared `lph-*` styles.

// Google-relevant feature keys → friendly labels for the pricing cards.
const GBP_FEATURE_LABELS: Record<string, string> = {
  dash_gbp: "Google Reviews & GBP management",
  seo: "Local SEO & rank tracking",
  social: "Google posts & photos scheduling",
  advanced_reports: "Insights & performance reports",
  export: "Export reviews & reports",
  api_access: "API access",
};

const STATS = [
  { v: "⭐ 4.8", l: "Avg client rating" },
  { v: "Seconds", l: "AI review replies" },
  { v: "Multi", l: "Location management" },
  { v: "Geo-grid", l: "Local rank tracking" },
];

const STEPS = [
  { n: 1, t: "Connect your profile", d: "Link your Google Business Profile in one click. Eydia imports every location, review and rating automatically." },
  { n: 2, t: "Reply & post with AI", d: "AI drafts on-brand replies to every review and publishes Google posts & photos across all your locations." },
  { n: 3, t: "Track rank & grow", d: "Watch your rating, sentiment and local rank climb on a live dashboard — and spot competitor gaps in real time." },
];

const FEATURES = [
  { icon: "⭐", bg: "#eef1ff", fg: "#2160ef", title: "AI Review Replies", d: "Reply to every Google review in seconds with on-brand, sentiment-aware AI — never leave a review unanswered again." },
  { icon: "🖼️", bg: "#eaf6ff", fg: "#2b6fe8", title: "Google Posts & Photos", d: "Create and schedule Google posts, offers and photos across every location, right from one content calendar." },
  { icon: "📊", bg: "#fff4e6", fg: "#d97706", title: "Local Rank Tracking", d: "Track your keyword rankings on a real geo-grid so you always know exactly where you stand in local search." },
  { icon: "🩺", bg: "#e7f7ef", fg: "#0a7d47", title: "Profile Health Score", d: "A live score of your GBP completeness and activity, with clear, prioritised fixes to climb the Google Map Pack." },
  { icon: "💬", bg: "#f3eeff", fg: "#8b3df0", title: "Sentiment Analysis", d: "Every review is analysed as it lands, so you instantly see what customers love — and what needs attention." },
  { icon: "🏁", bg: "#fdeef4", fg: "#db2777", title: "Competitor Analysis", d: "Benchmark your rating, review velocity and rankings against local competitors to always stay a step ahead." },
];

const MODULES = [
  { icon: "⭐", label: "Reviews & Replies" },
  { icon: "🖼️", label: "Posts & Photos" },
  { icon: "✨", label: "AI Content Studio" },
  { icon: "🩺", label: "Google Audit" },
  { icon: "📊", label: "Local Rank Checker" },
  { icon: "🏁", label: "Competitor Analysis" },
  { icon: "🔎", label: "Local SEO" },
  { icon: "🏢", label: "Multi-Location" },
];

// Fallback cards until live plans load (or if none are tagged for GBP).
const FALLBACK_PLANS = [
  { name: "Starter", price: "Free", per: "forever", credits: "100 AI credits / month", popular: false, cta: "Get Started", feats: ["Reviews dashboard", "1 location", "Basic insights"] },
  { name: "Growth", price: "₹2,999", per: "/mo", credits: "1,000 AI credits / month", popular: true, cta: "Start Free Trial", feats: ["AI review replies (5 locations)", "Google posts & photos", "Local SEO & rank tracking"] },
  { name: "Enterprise", price: "₹9,999", per: "/mo", credits: "50,000 AI credits / month", popular: false, cta: "Contact Sales", feats: ["Unlimited locations", "Competitor analysis", "API access & priority support"] },
];

export default function GoogleLanding() {
  const [loggedIn, setLoggedIn] = useState(false);
  const [livePlans, setLivePlans] = useState<PublicPlan[] | null>(null);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);
  useEffect(() => { api.publicPlans().then((r) => setLivePlans(r.plans)).catch(() => setLivePlans(null)); }, []);
  const cta = loggedIn ? "/dashboard" : "/register";

  const inr0 = (n: number) => "₹" + Math.round(n).toLocaleString("en-IN");
  // Only plans that include the Google / GBP dashboard. If none are tagged that
  // way, fall back to all live plans so the section is never empty.
  const gbpPlans = (livePlans ?? []).filter((p) => p.features?.dash_gbp);
  const sourcePlans = gbpPlans.length > 0 ? gbpPlans : (livePlans ?? []);
  const pricingCards = sourcePlans.length > 0
    ? sourcePlans.map((p, i) => ({
        name: p.name,
        price: p.price > 0 ? inr0(p.price) : "Free",
        per: p.price > 0 ? "/mo" : "forever",
        credits: `${Number(p.limits?.ai_credits ?? 0).toLocaleString("en-IN")} AI credits / month`,
        popular: sourcePlans.length >= 3 ? i === Math.floor(sourcePlans.length / 2) : i === 0,
        cta: p.price > 0 ? "Start Free Trial" : "Get Started",
        feats: Object.keys(GBP_FEATURE_LABELS).filter((k) => p.features?.[k]).slice(0, 5).map((k) => GBP_FEATURE_LABELS[k]),
      }))
    : FALLBACK_PLANS;

  return (
    <div className="lph">
      <LandingHeader active="/google-business-profile" />

      {/* ===== Hero ===== */}
      <section className="lph-hero">
        <div className="lph-hero-in">
          <div className="lph-hero-copy">
            <span className="lph-pill">⭐ Google Business Profile</span>
            <h1 className="lph-h1">Own your local<br /><span className="lph-grad-text">reputation on Google</span></h1>
            <p className="lph-lead">
              Reply to every Google review in seconds with AI, publish posts & photos across
              all your locations, and track your local rank on a live geo-grid — so you get
              found, trusted and chosen.
            </p>
            <div className="lph-hero-cta">
              <Link href={cta} className="lph-btn lph-btn-grad lph-btn-lg">Get Started Free</Link>
              <Link href="/login" className="lph-btn lph-btn-outline lph-btn-lg">Sign In</Link>
            </div>
            <div className="lph-hero-trust">
              <span>⭐ 4.8/5 avg client rating</span>
              <span>⚡ AI replies in seconds</span>
              <span>✓ No credit card required</span>
            </div>
          </div>

          <div className="lph-hero-visual">
            <div className="lph-hero-glow" />
            <div className="lph-stage">
              <GoogleMock />
              <div className="lph-chip lph-chip-google">
                <span className="lph-chip-ic" style={{ background: "#fff" }}>
                  <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden><path fill="#4285F4" d="M22.5 12.3c0-.8-.1-1.4-.2-2H12v3.8h5.9a5 5 0 0 1-2.2 3.3v2.7h3.5c2-1.9 3.3-4.7 3.3-7.8Z"/><path fill="#34A853" d="M12 23c3 0 5.4-1 7.2-2.7l-3.5-2.7c-1 .7-2.2 1.1-3.7 1.1-2.9 0-5.3-1.9-6.2-4.5H2.2v2.8A11 11 0 0 0 12 23Z"/><path fill="#FBBC05" d="M5.8 14.2a6.6 6.6 0 0 1 0-4.2V7.2H2.2a11 11 0 0 0 0 9.8l3.6-2.8Z"/><path fill="#EA4335" d="M12 5.4c1.6 0 3 .6 4.2 1.7l3.1-3.1A11 11 0 0 0 2.2 7.2l3.6 2.8C6.7 7.3 9.1 5.4 12 5.4Z"/></svg>
                </span>
                <div><div className="lph-chip-t">Google Reviews</div><div className="lph-chip-s">4.8★ · +126 this month</div></div>
              </div>
              <div className="lph-chip lph-chip-leads">
                <span className="lph-chip-ic" style={{ background: "linear-gradient(135deg,#2160ef,#4285F4)" }}>📊</span>
                <div><div className="lph-chip-t">Local rank</div><div className="lph-chip-s">#1 for 12 keywords</div></div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ===== Stats strip ===== */}
      <section className="lph-stats">
        <div className="lph-stats-in">
          {STATS.map((s) => (
            <div key={s.l} className="lph-stat"><div className="lph-stat-v">{s.v}</div><div className="lph-stat-l">{s.l}</div></div>
          ))}
        </div>
      </section>

      {/* ===== Pricing (above How it works) ===== */}
      <section id="pricing" className="lph-sec">
        <div className="lph-wrap">
          <div className="lph-eyebrow">PRICING</div>
          <h2 className="lph-h2">Google Business plans</h2>
          <p className="lph-sub">Start free, upgrade when you grow. Every plan includes monthly AI credits for replies &amp; posts.</p>
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
        </div>
      </section>

      {/* ===== How it works ===== */}
      <section id="how" className="lph-sec lph-sec-soft">
        <div className="lph-wrap">
          <div className="lph-eyebrow">HOW IT WORKS</div>
          <h2 className="lph-h2">Rank higher on Google in 3 steps</h2>
          <p className="lph-sub">Connect once, and Eydia keeps your Google presence running on autopilot.</p>
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
          <h2 className="lph-h2">Everything for Google reviews & GBP</h2>
          <p className="lph-sub">A complete Google Business Profile toolkit — reviews, posts, rank tracking and insights in one dashboard.</p>
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
      <section className="lph-sec lph-sec-soft">
        <div className="lph-wrap">
          <div className="lph-eyebrow">ONE DASHBOARD</div>
          <h2 className="lph-h2">All your Google tools, together</h2>
          <p className="lph-sub">Powerful modules working as one under a single login.</p>
          <div className="lph-mods">
            {MODULES.map((m) => (
              <div key={m.label} className="lph-mod"><span className="lph-mod-ic">{m.icon}</span>{m.label}</div>
            ))}
          </div>
        </div>
      </section>

      {/* ===== CTA band ===== */}
      <section className="lph-cta-wrap">
        <div className="lph-wrap">
          <div className="lph-cta">
            <h2>Ready to win on Google?</h2>
            <p>Join businesses using Eydia to grow their reviews, rankings and local reputation.</p>
            <Link href={cta} className="lph-btn lph-btn-white lph-btn-lg">Start Free Trial</Link>
          </div>
        </div>
      </section>

      <LandingFooter />
    </div>
  );
}

/* Hero mockup — a mini Google reviews panel. */
function GoogleMock() {
  const reviews = [
    { n: "Anita K.", s: 5, m: "Great service, highly recommend!", r: true },
    { n: "Vikram S.", s: 5, m: "Quick response and very professional.", r: true },
    { n: "Meena R.", s: 4, m: "Good experience overall, will return.", r: false },
  ];
  return (
    <div className="lph-mock">
      <div className="lph-mock-head">
        <div className="lph-mock-dots"><span /><span /><span /></div>
        <span className="lph-mock-title">Google Reviews</span>
      </div>
      <div className="lph-mock-stats">
        <div className="lph-mock-stat"><span className="k">AVG RATING</span><b>4.83</b></div>
        <div className="lph-mock-stat"><span className="k">REVIEWS</span><b>1,862</b></div>
        <div className="lph-mock-stat"><span className="k">REPLY RATE</span><b className="pos">98%</b></div>
      </div>
      <div style={{ display: "flex", flexDirection: "column", gap: 8, marginTop: 12 }}>
        {reviews.map((rv) => (
          <div key={rv.n} style={{ background: "#fff", border: "1px solid #eef1f6", borderRadius: 12, padding: "8px 10px" }}>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
              <span style={{ fontSize: 12.5, fontWeight: 700, color: "#1f2430" }}>{rv.n}</span>
              <span style={{ fontSize: 11, color: "#f5a623", letterSpacing: 1 }}>{"★".repeat(rv.s)}<span style={{ color: "#dfe3ea" }}>{"★".repeat(5 - rv.s)}</span></span>
            </div>
            <div style={{ fontSize: 11.5, color: "#778", marginTop: 2 }}>{rv.m}</div>
            <div style={{ fontSize: 10.5, marginTop: 4, fontWeight: 700, color: rv.r ? "#0a7d47" : "#8b5cf6" }}>{rv.r ? "✓ AI replied" : "✎ Drafting reply…"}</div>
          </div>
        ))}
      </div>
    </div>
  );
}
