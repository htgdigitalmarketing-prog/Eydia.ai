"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";

// Eydia.ai unifies three products into one workspace. The landing page leads
// with the three pillars so a visitor immediately sees the full scope.
const PILLARS = [
  {
    icon: "💬",
    tag: "WhatsApp",
    title: "WhatsApp Marketing & Inbox",
    desc: "Team inbox, bulk campaigns, templates, AI chatbot and social publishing.",
    points: ["Shared team inbox with agent assignment", "Bulk campaigns with delivery & read reports", "Template builder + Meta approval", "AI chatbot + social publishing"],
  },
  {
    icon: "⭐",
    tag: "Google Reviews / GBP",
    title: "Google Business Profile & Reviews",
    desc: "Monitor locations, read & reply to Google reviews, rating analytics.",
    points: ["All locations & ratings in one view", "Reply to reviews (pushed to Google)", "Rating distribution & unreplied alerts", "GBP posts & photos management"],
  },
  {
    icon: "🎯",
    tag: "Lead Management",
    title: "Lead Management CRM",
    desc: "Capture leads from Google / Meta / manual, work them through a Kanban pipeline.",
    points: ["Leads from Google, Meta & manual", "Kanban pipeline with drag-to-stage", "Lead score & estimated pipeline value", "Assign to agents & track follow-ups"],
  },
];

const STATS = [
  { value: "3-in-1", label: "WhatsApp · Reviews · Leads" },
  { value: "1 Login", label: "One workspace, every channel" },
  { value: "Plan-based", label: "Access unlocked by your plan" },
  { value: "24×7", label: "AI automated replies" },
];

type PlanCard = { name: string; price: string; period: string; popular?: boolean; bullets: string[] };
const PLANS: PlanCard[] = [
  { name: "Starter", price: "Free", period: "mo", bullets: ["WhatsApp dashboard", "2 team agents", "1,000 contacts", "Reports", "Email support"] },
  { name: "Growth", price: "₹2,999", period: "mo", popular: true, bullets: ["All 3 dashboards unlocked", "10 agents · 5 GBP locations", "25,000 contacts & leads", "AI chatbot, automations & social", "Priority support"] },
  { name: "Enterprise", price: "₹9,999", period: "mo", bullets: ["All 3 dashboards, unlimited", "Unlimited agents & locations", "Unlimited contacts & leads", "API access & advanced reports", "Dedicated manager"] },
];

export default function Landing() {
  const [loggedIn, setLoggedIn] = useState(false);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);

  return (
    <div className="lp">
      <header className="lp-nav">
        <div className="lp-brand brand-lockup">
          <span className="brand-mark">e</span>
          <span style={{ display: "inline-flex", flexDirection: "column", lineHeight: 1 }}>
            <span className="brand-word" style={{ fontSize: 22 }}>Eydia.ai</span>
            <span className="brand-by" style={{ marginTop: 2 }}>Automate · Engage · Grow</span>
          </span>
        </div>
        <nav className="lp-nav-links">
          <a href="#pillars">Products</a>
          <a href="#how">How it works</a>
          <a href="#pricing">Pricing</a>
          {loggedIn
            ? <Link href="/dashboard" className="lp-btn lp-btn-solid">Go to dashboard</Link>
            : <Link href="/login" className="lp-btn lp-btn-solid">Login</Link>}
        </nav>
      </header>

      <section className="lp-hero">
        <div className="lp-blob lp-blob-1" />
        <div className="lp-blob lp-blob-2" />
        <div className="lp-hero-grid">
          <div className="lp-hero-copy">
            <span className="lp-eyebrow">Eydia.ai · Automate · Engage · Grow</span>
            <h1>Three growth engines. One intelligent workspace.</h1>
            <p>
              Eydia.ai brings WhatsApp marketing, Google Reviews &amp; Business Profile, and a full
              Lead Management CRM together — so your whole team runs every customer conversation,
              every review and every lead from a single login.
            </p>
            <div className="lp-cta">
              <Link href="/login" className="lp-btn lp-btn-accent lp-btn-lg">Login</Link>
              <Link href="/register" className="lp-btn lp-btn-ghost lp-btn-lg">Get Started</Link>
            </div>
          </div>

          {/* 3D logo showcase */}
          <div className="lp-hero-visual">
            <div className="lp-logo3d-ring" />
            <div className="lp-logo3d">
              <img src="/eydia-logo-white.png" alt="Eydia.ai Automate · Engage · Grow" />
            </div>
          </div>
        </div>

        <div className="lp-stats">
          {STATS.map((s) => (
            <div key={s.label} className="lp-stat">
              <div className="lp-stat-v">{s.value}</div>
              <div className="lp-stat-l">{s.label}</div>
            </div>
          ))}
        </div>
      </section>

      {/* ---- Three pillars ---- */}
      <section id="pillars" className="lp-section">
        <h2>One platform, three powerful dashboards</h2>
        <p className="lp-sub">Each dashboard is a complete product. Your plan decides which ones open — upgrade anytime.</p>
        <div className="lp-grid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(300px, 1fr))" }}>
          {PILLARS.map((p) => (
            <div key={p.title} className="lp-card" style={{ textAlign: "left" }}>
              <div className="lp-card-icon">{p.icon}</div>
              <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: ".6px", textTransform: "uppercase", color: "var(--accent)", marginBottom: 4 }}>{p.tag}</div>
              <h3>{p.title}</h3>
              <p>{p.desc}</p>
              <ul className="lp-check-list" style={{ marginTop: 12 }}>
                {p.points.map((pt) => <li key={pt}>{pt}</li>)}
              </ul>
            </div>
          ))}
        </div>
      </section>

      {/* ---- How it works ---- */}
      <section id="how" className="lp-why">
        <div className="lp-why-inner">
          <h2>How Eydia.ai works</h2>
          <div className="lp-why-grid">
            <div><div className="lp-why-ic">①</div><h4>Pick a plan</h4><p>Choose a plan and we unlock exactly the dashboards and features it includes.</p></div>
            <div><div className="lp-why-ic">②</div><h4>Connect channels</h4><p>Link WhatsApp, your Google Business Profile and lead sources in minutes.</p></div>
            <div><div className="lp-why-ic">③</div><h4>Grow with your team</h4><p>Agents reply, reviews get answered and leads convert — all tracked in one place.</p></div>
          </div>
        </div>
      </section>

      {/* ---- Pricing ---- */}
      <section id="pricing" className="lp-pricing">
        <h2>Simple, transparent pricing</h2>
        <p className="lp-sub">Plans control which dashboards and features you get. Upgrade anytime.</p>
        <div className="lp-price-grid">
          {PLANS.map((plan) => (
            <div key={plan.name} className={`lp-price-card ${plan.popular ? "lp-price-popular" : ""}`}>
              {plan.popular && <div className="lp-price-tag">Most popular</div>}
              <h3>{plan.name}</h3>
              <div className="lp-price-amount">
                <span className="lp-price-value">{plan.price}</span>
                <span className="lp-price-per">/{plan.period}</span>
              </div>
              <ul className="lp-check-list">
                {plan.bullets.map((b) => <li key={b}>{b}</li>)}
              </ul>
              <Link href="/register" className={`lp-btn lp-btn-lg ${plan.popular ? "lp-btn-accent" : "lp-btn-outline"}`} style={{ width: "100%", justifyContent: "center" }}>
                Get started
              </Link>
            </div>
          ))}
        </div>
      </section>

      <section className="lp-cta-band">
        <h2>Ready to see beyond and create impact?</h2>
        <p>Sign in to your workspace, or create a new account in minutes.</p>
        <div className="lp-cta" style={{ justifyContent: "center" }}>
          <Link href="/login" className="lp-btn lp-btn-accent lp-btn-lg">Login</Link>
          <Link href="/register" className="lp-btn lp-btn-outline lp-btn-lg">Create account</Link>
        </div>
      </section>

      <footer className="lp-footer">
        © {new Date().getFullYear()} Eydia.ai — Automate · Engage · Grow · Automate · Engage · Grow
      </footer>
    </div>
  );
}
