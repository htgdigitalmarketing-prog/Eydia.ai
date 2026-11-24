"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";
import { api, PublicPlan } from "@/lib/api";
import { LandingHeader, LandingFooter } from "@/components/landing-kit";

// Dedicated WhatsApp Marketing landing page — WhatsApp-only content + only the
// plans that include the WhatsApp dashboard. Reuses the shared `lph-*` styles.

// WhatsApp-relevant feature keys → friendly labels for the pricing cards.
const WA_FEATURE_LABELS: Record<string, string> = {
  dash_whatsapp: "WhatsApp inbox, broadcasts & templates",
  chatbot: "24/7 AI chatbot auto-replies",
  automations: "Drip campaigns & workflow automations",
  social: "Social posting",
  advanced_reports: "Delivery & engagement reports",
  export: "Export contacts & reports",
  api_access: "WhatsApp API access",
};

const STATS = [
  { v: "98%", l: "Message open rate" },
  { v: "24/7", l: "AI chatbot replies" },
  { v: "Official", l: "Meta WhatsApp API" },
  { v: "1-2s", l: "Auto-reply speed" },
];

const STEPS = [
  { n: 1, t: "Connect WhatsApp", d: "Link your official WhatsApp Business number in minutes. Eydia imports your chats into one shared team inbox." },
  { n: 2, t: "Automate with AI", d: "A 24/7 AI chatbot greets, answers FAQs and qualifies leads instantly — even when your team is offline." },
  { n: 3, t: "Broadcast & convert", d: "Send approved-template broadcasts and drip campaigns, then track delivery, reads and replies in real time." },
];

const FEATURES = [
  { icon: "✉️", bg: "#e7f7ef", fg: "#0a7d47", title: "Shared Team Inbox", d: "Every WhatsApp chat in one place. Assign conversations to agents, add notes, tag hot leads and never miss a message." },
  { icon: "📣", bg: "#eaf6ff", fg: "#2b6fe8", title: "Broadcasts & Campaigns", d: "Reach thousands at once with approved templates. Schedule campaigns and see delivered / read / replied for every contact." },
  { icon: "🤖", bg: "#f3eeff", fg: "#8b3df0", title: "24/7 AI Chatbot", d: "An AI bot that answers questions, shares your offers and books enquiries automatically — replies land in 1-2 seconds." },
  { icon: "▧", bg: "#fff4e6", fg: "#d97706", title: "Approved Templates", d: "Create and manage Meta-approved message templates with media headers, buttons and variables — ready to send." },
  { icon: "⚙️", bg: "#eef1ff", fg: "#4c57d8", title: "Drip Automations", d: "Auto-follow-up quiet leads, trigger flows on keywords and keep conversations warm without lifting a finger." },
  { icon: "📍", bg: "#fdeef4", fg: "#db2777", title: "Rich Media & Location", d: "Send images, videos, documents, voice notes and your business location as a tappable map card, right from the inbox." },
];

const MODULES = [
  { icon: "✉️", label: "Shared Inbox" },
  { icon: "📣", label: "Broadcasts" },
  { icon: "🤖", label: "AI Chatbot" },
  { icon: "▧", label: "Templates" },
  { icon: "⚙️", label: "Automations" },
  { icon: "🔥", label: "Hot-Lead Detection" },
  { icon: "🎧", label: "Agent Assignment" },
  { icon: "📊", label: "Delivery Reports" },
];

// Fallback cards until live plans load (or if none are tagged for WhatsApp).
const FALLBACK_PLANS = [
  { name: "Starter", price: "Free", per: "forever", credits: "100 AI credits / month", popular: false, cta: "Get Started", feats: ["WhatsApp inbox & templates", "1,000 contacts", "Basic reports"] },
  { name: "Growth", price: "₹2,999", per: "/mo", credits: "1,000 AI credits / month", popular: true, cta: "Start Free Trial", feats: ["WhatsApp broadcasts + AI chatbot", "Drip automations", "Delivery & engagement reports"] },
  { name: "Enterprise", price: "₹9,999", per: "/mo", credits: "50,000 AI credits / month", popular: false, cta: "Contact Sales", feats: ["Unlimited contacts", "WhatsApp API access", "Priority support"] },
];

export default function WhatsAppLanding() {
  const [loggedIn, setLoggedIn] = useState(false);
  const [livePlans, setLivePlans] = useState<PublicPlan[] | null>(null);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);
  useEffect(() => { api.publicPlans().then((r) => setLivePlans(r.plans)).catch(() => setLivePlans(null)); }, []);
  const cta = loggedIn ? "/dashboard" : "/register";

  const inr0 = (n: number) => "₹" + Math.round(n).toLocaleString("en-IN");
  // Only plans that include the WhatsApp dashboard. If none are tagged that way,
  // fall back to all live plans so the section is never empty.
  const waPlans = (livePlans ?? []).filter((p) => p.features?.dash_whatsapp);
  const sourcePlans = waPlans.length > 0 ? waPlans : (livePlans ?? []);
  const pricingCards = sourcePlans.length > 0
    ? sourcePlans.map((p, i) => ({
        name: p.name,
        price: p.price > 0 ? inr0(p.price) : "Free",
        per: p.price > 0 ? "/mo" : "forever",
        credits: `${Number(p.limits?.ai_credits ?? 0).toLocaleString("en-IN")} AI credits / month`,
        popular: sourcePlans.length >= 3 ? i === Math.floor(sourcePlans.length / 2) : i === 0,
        cta: p.price > 0 ? "Start Free Trial" : "Get Started",
        feats: Object.keys(WA_FEATURE_LABELS).filter((k) => p.features?.[k]).slice(0, 5).map((k) => WA_FEATURE_LABELS[k]),
      }))
    : FALLBACK_PLANS;

  return (
    <div className="lph">
      <LandingHeader active="/whatsapp-marketing" />

      {/* ===== Hero ===== */}
      <section className="lph-hero">
        <div className="lph-hero-in">
          <div className="lph-hero-copy">
            <span className="lph-pill">💬 Official WhatsApp Business API</span>
            <h1 className="lph-h1">Turn WhatsApp into<br /><span className="lph-grad-text">your #1 sales channel</span></h1>
            <p className="lph-lead">
              Manage every WhatsApp conversation from one shared inbox. Broadcast approved
              templates, run drip campaigns and let a 24/7 AI chatbot reply in seconds — so no
              enquiry is ever missed.
            </p>
            <div className="lph-hero-cta">
              <Link href={cta} className="lph-btn lph-btn-grad lph-btn-lg">Get Started Free</Link>
              <Link href="/login" className="lph-btn lph-btn-outline lph-btn-lg">Sign In</Link>
            </div>
            <div className="lph-hero-trust">
              <span>💬 98% message open rate</span>
              <span>🤖 24/7 AI replies</span>
              <span>✓ No credit card required</span>
            </div>
          </div>

          <div className="lph-hero-visual">
            <div className="lph-hero-glow" />
            <div className="lph-stage">
              <WhatsAppMock />
              <div className="lph-chip lph-chip-meta">
                <span className="lph-chip-ic" style={{ background: "linear-gradient(135deg,#25D366,#128C7E)" }}>
                  <svg viewBox="0 0 24 24" width="17" height="17" fill="#fff" aria-hidden><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.4-.7-2.9-1.1-4.7-4-4.9-4.2-.1-.2-1.1-1.5-1.1-2.8 0-1.3.7-2 .9-2.3.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.4.6c-.2.2-.3.4-.2.7.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.4 2.4 1.5.2.1.4.1.6-.1l.8-1c.2-.2.4-.2.6-.1l1.9.9c.3.1.5.2.5.4.1.2.1.9-.1 1.5Z"/></svg>
                </span>
                <div><div className="lph-chip-t">Auto-reply</div><div className="lph-chip-s">98% in 1-2 seconds</div></div>
              </div>
              <div className="lph-chip lph-chip-leads">
                <span className="lph-chip-ic" style={{ background: "linear-gradient(135deg,#0a7d47,#25D366)" }}>📣</span>
                <div><div className="lph-chip-t">Broadcast sent</div><div className="lph-chip-s">4,210 delivered · 94% read</div></div>
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

      {/* ===== How it works ===== */}
      <section id="how" className="lph-sec lph-sec-soft">
        <div className="lph-wrap">
          <div className="lph-eyebrow">HOW IT WORKS</div>
          <h2 className="lph-h2">WhatsApp on autopilot in 3 steps</h2>
          <p className="lph-sub">Connect once, and Eydia keeps your WhatsApp conversations and campaigns running for you.</p>
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
          <h2 className="lph-h2">Everything for WhatsApp marketing</h2>
          <p className="lph-sub">A complete WhatsApp Business toolkit — inbox, broadcasts, chatbot and automations in one dashboard.</p>
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
          <div className="lph-eyebrow">ONE INBOX</div>
          <h2 className="lph-h2">All your WhatsApp tools, together</h2>
          <p className="lph-sub">Powerful modules working as one under a single login.</p>
          <div className="lph-mods">
            {MODULES.map((m) => (
              <div key={m.label} className="lph-mod"><span className="lph-mod-ic">{m.icon}</span>{m.label}</div>
            ))}
          </div>
        </div>
      </section>

      {/* ===== Pricing ===== */}
      <section id="pricing" className="lph-sec">
        <div className="lph-wrap">
          <div className="lph-eyebrow">PRICING</div>
          <h2 className="lph-h2">WhatsApp plans</h2>
          <p className="lph-sub">Start free, upgrade when you grow. Every plan includes monthly AI credits for chatbot &amp; content.</p>
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

      {/* ===== CTA band ===== */}
      <section className="lph-cta-wrap">
        <div className="lph-wrap">
          <div className="lph-cta">
            <h2>Ready to grow on WhatsApp?</h2>
            <p>Join businesses using Eydia to turn WhatsApp into their best-performing sales channel.</p>
            <Link href={cta} className="lph-btn lph-btn-white lph-btn-lg">Start Free Trial</Link>
          </div>
        </div>
      </section>

      <LandingFooter />
    </div>
  );
}

/* Hero mockup — a mini WhatsApp inbox. */
function WhatsAppMock() {
  const chats = [
    { n: "Rahul Sharma", m: "Yes, please send the price list 👍", t: "now", u: true },
    { n: "Priya Verma", m: "Can I book for Saturday?", t: "2m", u: true },
    { n: "Broadcast · Diwali Offer", m: "Delivered to 4,210 · 94% read", t: "1h", u: false },
    { n: "AI Chatbot", m: "Replied to 38 new enquiries", t: "today", u: false },
  ];
  return (
    <div className="lph-mock">
      <div className="lph-mock-head">
        <div className="lph-mock-dots"><span /><span /><span /></div>
        <span className="lph-mock-title">WhatsApp Inbox</span>
      </div>
      <div className="lph-mock-stats">
        <div className="lph-mock-stat"><span className="k">OPEN CHATS</span><b>14</b></div>
        <div className="lph-mock-stat"><span className="k">AUTO-REPLIED</span><b className="pos">38</b></div>
        <div className="lph-mock-stat"><span className="k">READ RATE</span><b className="pos">94%</b></div>
      </div>
      <div style={{ display: "flex", flexDirection: "column", gap: 8, marginTop: 12 }}>
        {chats.map((c) => (
          <div key={c.n} style={{ display: "flex", alignItems: "center", gap: 10, background: "#fff", border: "1px solid #eef1f6", borderRadius: 12, padding: "8px 10px" }}>
            <span style={{ width: 30, height: 30, borderRadius: "50%", background: "linear-gradient(135deg,#25D366,#128C7E)", color: "#fff", display: "grid", placeItems: "center", fontSize: 13, fontWeight: 700, flexShrink: 0 }}>{c.n[0]}</span>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 12.5, fontWeight: 700, color: "#1f2430", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{c.n}</div>
              <div style={{ fontSize: 11.5, color: "#778", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{c.m}</div>
            </div>
            <div style={{ display: "flex", flexDirection: "column", alignItems: "flex-end", gap: 3, flexShrink: 0 }}>
              <span style={{ fontSize: 10.5, color: "#99a" }}>{c.t}</span>
              {c.u && <span style={{ width: 16, height: 16, borderRadius: "50%", background: "#25D366", color: "#fff", fontSize: 10, display: "grid", placeItems: "center", fontWeight: 700 }}>1</span>}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
