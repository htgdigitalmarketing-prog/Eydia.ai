"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";
import { api, PublicPlan, PublicClient, PublicWorkItem } from "@/lib/api";

// Feature keys → friendly labels for the pricing cards.
const LP_FEATURE_LABELS: Record<string, string> = {
  dash_whatsapp: "WhatsApp inbox, broadcasts & templates",
  dash_gbp: "Google Reviews / GBP management",
  dash_leads: "Leads CRM & automations",
  chatbot: "AI chatbot auto-replies",
  automations: "Workflow automations",
  social: "Social posting",
  seo: "SEO tools",
  ads: "Ads reporting",
  invoicing: "Invoicing",
  advanced_reports: "Advanced reports",
  export: "Export",
  api_access: "API access",
};

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
  const [livePlans, setLivePlans] = useState<PublicPlan[] | null>(null);
  const [clients, setClients] = useState<PublicClient[]>([]);
  const [work, setWork] = useState<PublicWorkItem[]>([]);
  const [workCat, setWorkCat] = useState<string>("All");
  useEffect(() => { setLoggedIn(!!getToken()); }, []);
  useEffect(() => { api.publicPlans().then((r) => setLivePlans(r.plans)).catch(() => setLivePlans(null)); }, []);
  useEffect(() => { api.publicClients().then((r) => setClients(r.clients)).catch(() => setClients([])); }, []);
  useEffect(() => { api.publicWork().then((r) => setWork(r.items)).catch(() => setWork([])); }, []);
  const year = new Date().getFullYear();
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
        cta: p.price > 0 ? "Start Free Trial" : "Get Started",
        feats: Object.keys(LP_FEATURE_LABELS).filter((k) => p.features?.[k]).slice(0, 5).map((k) => LP_FEATURE_LABELS[k]),
      }))
    : PLANS;

  return (
    <div className="lph">
      {/* ===== Header ===== */}
      <header className="lph-head">
        <div className="lph-head-in">
          <Link href="/" className="lph-brand"><img src="/eydia-logo.png" alt="Eydia.ai" /></Link>
          <nav className="lph-nav">
            <a href="#how">How it works</a>
            <a href="#features">Features</a>
            <a href="#work">Our Work</a>
            <a href="#clients">Our Clients</a>
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
                {shown.map((w, i) => {
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
