"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";

// Eydia.ai marketing homepage — ReviewFlow-style layout, Eydia branding/logo and
// content across the three pillars: Google Reviews / GBP, WhatsApp and Leads.

const STATS = [
  { v: "5,000+", l: "Businesses managed" },
  { v: "2M+", l: "Reviews & messages handled" },
  { v: "10K+", l: "AI replies sent" },
  { v: "99.9%", l: "Uptime" },
];

const STEPS = [
  {
    n: 1, t: "Connect your channels",
    d: "Link your Google Business Profile and WhatsApp in one click. Eydia imports every location, review and chat automatically.",
  },
  {
    n: 2, t: "Automate with AI",
    d: "AI drafts on-brand review replies, Google posts, WhatsApp messages and lead follow-ups. Sentiment is analysed as each review lands.",
  },
  {
    n: 3, t: "Track & grow",
    d: "Watch reviews, rankings and leads climb on the live dashboard — reply rate, local rank, pipeline and competitor gaps in real time.",
  },
];

const PILLARS = [
  {
    icon: "⭐", bg: "#eef1ff", fg: "#2160ef", tag: "Google",
    title: "Google Reviews & GBP",
    lead: "Own your local reputation on Google.",
    points: [
      "Reply to every review in seconds with on-brand AI",
      "Publish Google posts & photos across all locations",
      "Live rating, sentiment & profile health score",
      "Local rank tracking on a real geo-grid",
    ],
  },
  {
    icon: "💬", bg: "#e7f7ef", fg: "#0a7d47", tag: "WhatsApp",
    title: "WhatsApp Engagement",
    lead: "Talk to customers where they already are.",
    points: [
      "Official WhatsApp Business API & shared inbox",
      "Broadcasts & automated drip campaigns",
      "24/7 AI chatbot that answers & books",
      "Approved templates with delivery reports",
    ],
  },
  {
    icon: "🎯", bg: "#f3eeff", fg: "#8b3df0", tag: "Leads",
    title: "Leads CRM",
    lead: "Turn conversations into paying customers.",
    points: [
      "Capture leads from Google, WhatsApp & your site",
      "Visual pipeline with AI intent scoring",
      "Automatic follow-ups so nothing slips",
      "Track conversions & revenue by source",
    ],
  },
];

const FEATURES = [
  {
    icon: "⭐", bg: "#eef1ff", fg: "#4c57d8", title: "Google Reviews & GBP",
    d: "Monitor, reply to and analyse reviews across every location. AI responses in seconds with live sentiment — plus posts, photos and a health score.",
  },
  {
    icon: "💬", bg: "#e7f7ef", fg: "#0a7d47", title: "WhatsApp Engagement",
    d: "Official WhatsApp API: broadcasts, drip campaigns, a 24/7 AI chatbot and approved templates — so every enquiry gets an instant reply.",
  },
  {
    icon: "🎯", bg: "#f3eeff", fg: "#8b5cf6", title: "Leads CRM",
    d: "Capture leads from Google, WhatsApp and your website into one pipeline, scored by intent, with automatic follow-ups so nothing slips.",
  },
  {
    icon: "✨", bg: "#eaf6ff", fg: "#2b6fe8", title: "AI Content Studio",
    d: "Generate Google posts, captions, review replies and images with built-in Gemini AI. Simple, transparent credit-based billing.",
  },
  {
    icon: "📈", bg: "#fff4e6", fg: "#d97706", title: "Local Rank & Insights",
    d: "Track keyword rankings on a real geo-grid, performance trends and competitor velocity so you always stay a step ahead locally.",
  },
  {
    icon: "🧾", bg: "#fdeef4", fg: "#db2777", title: "Invoicing & Billing",
    d: "Professional invoices, GST support, Tally export and expense tracking — the whole back office, built right into one dashboard.",
  },
];

const MODULES = [
  { icon: "⭐", label: "Reviews & Replies" },
  { icon: "🖼️", label: "Posts & Photos" },
  { icon: "💬", label: "WhatsApp Broadcasts" },
  { icon: "🤖", label: "WhatsApp Chatbot" },
  { icon: "🎯", label: "Leads CRM" },
  { icon: "✨", label: "AI Content Studio" },
  { icon: "🎨", label: "AI Generated Media" },
  { icon: "🩺", label: "Google Audit" },
  { icon: "📊", label: "Local Rank Checker" },
  { icon: "🏁", label: "Competitor Analysis" },
  { icon: "💡", label: "AI Marketing Chat" },
  { icon: "🧾", label: "Invoicing & Tally" },
];

const PLANS = [
  {
    name: "Starter", price: "Free", per: "forever", credits: "100 AI credits / month", popular: false,
    cta: "Get Started",
    feats: ["WhatsApp inbox & templates", "1,000 contacts", "Reviews dashboard", "Basic reports"],
  },
  {
    name: "Growth", price: "₹2,999", per: "/mo", credits: "1,000 AI credits / month", popular: true,
    cta: "Start Free Trial",
    feats: ["Google Reviews / GBP (5 locations)", "WhatsApp broadcasts + AI chatbot", "Leads CRM + automations", "Social posting & SEO"],
  },
  {
    name: "Enterprise", price: "₹9,999", per: "/mo", credits: "50,000 AI credits / month", popular: false,
    cta: "Contact Sales",
    feats: ["Unlimited clients & locations", "White-label workspace", "Ads reporting & invoicing", "API access & priority support"],
  },
];

export default function Landing() {
  const [loggedIn, setLoggedIn] = useState(false);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);
  const year = new Date().getFullYear();
  const cta = loggedIn ? "/dashboard" : "/register";

  return (
    <div className="lph">
      {/* ===== Header ===== */}
      <header className="lph-head">
        <div className="lph-head-in">
          <Link href="/" className="lph-brand"><img src="/eydia-logo.png" alt="Eydia.ai" /></Link>
          <nav className="lph-nav">
            <a href="#how">How it works</a>
            <a href="#features">Features</a>
            <a href="#pricing">Pricing</a>
            <Link href={loggedIn ? "/dashboard" : "/login"}>{loggedIn ? "Dashboard" : "Dashboard"}</Link>
            <Link href={loggedIn ? "/dashboard" : "/login"} className="lph-btn lph-btn-grad lph-btn-sm">{loggedIn ? "Open app" : "Login"}</Link>
          </nav>
        </div>
      </header>

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
              <Link href={cta} className="lph-btn lph-btn-grad lph-btn-lg">Get Started Free</Link>
              <Link href="/login" className="lph-btn lph-btn-outline lph-btn-lg">Sign In</Link>
            </div>
            <div className="lph-hero-trust">
              <span>⭐ 4.8/5 avg client rating</span>
              <span>💬 98% AI reply rate</span>
              <span>✓ No credit card required</span>
            </div>
          </div>

          <div className="lph-hero-visual">
            <div className="lph-badge lph-badge-tr">⭐ <b>4.8</b> Avg Rating<span>Across 12 locations</span></div>
            <div className="lph-badge lph-badge-bl">💬 WhatsApp Auto-Reply<span>98% response rate</span></div>
            <DashboardMock />
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

      {/* ===== Pricing ===== */}
      <section id="pricing" className="lph-sec">
        <div className="lph-wrap">
          <div className="lph-eyebrow">PRICING</div>
          <h2 className="lph-h2">Simple, transparent plans</h2>
          <p className="lph-sub">Start free, upgrade when you grow. Every plan includes monthly AI credits.</p>
          <div className="lph-plans">
            {PLANS.map((p) => (
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
            <h2>Ready to grow your local business?</h2>
            <p>Join thousands of businesses & agencies managing their Google, WhatsApp and leads with Eydia.</p>
            <Link href={cta} className="lph-btn lph-btn-white lph-btn-lg">Start Free Trial</Link>
          </div>
        </div>
      </section>

      {/* ===== Footer ===== */}
      <footer className="lph-foot">
        <div className="lph-wrap lph-foot-in">
          <Link href="/" className="lph-foot-brand"><img src="/eydia-logo.png" alt="Eydia.ai" /></Link>
          <nav className="lph-foot-links">
            <Link href="/pricing-policy">Pricing Policy</Link>
            <Link href="/shipping-policy">Shipping Policy</Link>
            <Link href="/terms">Terms &amp; Conditions</Link>
            <Link href="/privacy">Privacy Policy</Link>
            <Link href="/cancellation-policy">Cancellation / Refund</Link>
          </nav>
          <span className="lph-foot-copy">© {year} Eydia.ai. All rights reserved.</span>
        </div>
      </footer>
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
