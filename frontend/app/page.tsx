"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";

// Eydia.ai full homepage (Grexa-style): gradient hero → trust bar → "Meet your
// AI team" feature rows with phone mockups → stats → testimonials → industries
// → FAQ → CTA → dark footer. Content is Eydia's three products.

const HERO_AGENTS = [
  { icon: "⭐", name: "Google Reviews", tag: "Get found on Google" },
  { icon: "💬", name: "WhatsApp", tag: "Never miss a lead" },
  { icon: "🎯", name: "Leads CRM", tag: "Close more sales" },
];

// Industry sectors (placeholder tiles — swap for real client logos).
const SECTORS = ["Salons", "Clinics", "Gyms & Fitness", "Restaurants", "Retail", "Local Services"];

// Store/review highlights — replace with your real published ratings.
const RATINGS = [
  { ic: "", name: "App Store", score: "4.8", den: "/5", note: "iOS app" },
  { ic: "▶", name: "Google Play", score: "4.6", den: "/5", note: "Android app" },
  { ic: "G", name: "Google", score: "4.8", den: "/5", note: "Business reviews" },
  { ic: "★", name: "Trustpilot", score: "4.6", den: "/5", note: "Verified" },
];

const STATS = [
  { big: "3-in-1", label: "WhatsApp · Google Reviews · Leads, in one login" },
  { big: "24×7", label: "AI agents reply, post & follow up automatically" },
  { big: "1 workspace", label: "Your whole team, every channel, one view" },
];

// Representative customer stories — replace with your real testimonials.
const STORIES = [
  { q: "Our Google views and walk-ins jumped after Eydia started replying to reviews and posting weekly.", name: "Anvika Salon", role: "Owner" },
  { q: "The WhatsApp agent books slots on its own, in the customer's language. We stopped losing leads.", name: "Dr. Rahul's Clinic", role: "Founder" },
  { q: "All our leads land in one CRM sorted by intent. Follow-ups are automatic — lead management is hassle-free.", name: "FnH Services", role: "Director" },
];

const INDUSTRIES = [
  { icon: "💇", label: "Salons & spas" },
  { icon: "🩺", label: "Clinics & dentists" },
  { icon: "🏋️", label: "Gyms & fitness" },
  { icon: "🍽️", label: "Restaurants & bars" },
  { icon: "🧁", label: "Bakeries & cafes" },
  { icon: "🐜", label: "Pest control" },
  { icon: "🔧", label: "Car garages" },
  { icon: "🧘", label: "Yoga & wellness" },
  { icon: "🏠", label: "Home services" },
  { icon: "🏨", label: "Hotels & stays" },
  { icon: "✈️", label: "Tours & travel" },
  { icon: "🛠️", label: "Handyman" },
];

const FAQS = [
  { q: "What does Eydia do, and where do I use it?", a: "Eydia is one workspace for WhatsApp marketing, Google Reviews / Business Profile and lead management. You run it from the web dashboard and the mobile app." },
  { q: "How do the Google and WhatsApp agents work together?", a: "The GBP agent brings customers from Google; the WhatsApp agent replies and books them; the CRM tracks every lead to conversion — all from one login." },
  { q: "Will it work if I'm not tech-savvy?", a: "Yes. Agents, automations and campaigns are set up without any coding. Book a free demo and we'll set it up with you." },
  { q: "How soon will I see results?", a: "Most businesses see more Google visibility and faster WhatsApp replies within the first few weeks." },
  { q: "Is my customer data safe?", a: "Each business gets its own isolated, secure workspace. Credentials are encrypted and access is role-based." },
  { q: "Does Eydia work for all businesses?", a: "It's built for local businesses — salons, clinics, gyms, restaurants, services and more." },
];

type Feature = {
  eyebrow: string; title: string; accent: string; desc: string;
  chips: { icon: string; label: string; sub?: string }[];
  mock: React.ReactNode;
};

function GbpMock() {
  return (
    <div className="lp-screen">
      <div className="lp-screen-head" style={{ background: "linear-gradient(135deg,#f6a609,#f26f21)" }}>Google Business Profile</div>
      <div className="lp-screen-body">
        <div className="lp-mini-card">
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}><b>Your Studio — MG Road</b><span style={{ color: "#f5a623", fontWeight: 700 }}>4.8★</span></div>
          <div className="muted2">1,955 views · 135 calls this month</div>
        </div>
        <div className="lp-mini-card">
          <div style={{ fontSize: 11, fontWeight: 700, color: "#0a7d47" }}>😊 Positive · auto-replied</div>
          <div style={{ fontSize: 12, marginTop: 3 }}>&ldquo;Fantastic service, ranking jumped in weeks!&rdquo;</div>
        </div>
        <div className="lp-mini-row"><span>✍️ SEO rewrite</span><span className="ok">Done</span></div>
        <div className="lp-mini-row"><span>🖼️ Daily post</span><span className="ok">Scheduled</span></div>
      </div>
    </div>
  );
}
function WaMock() {
  return (
    <div className="lp-screen">
      <div className="lp-screen-head" style={{ background: "linear-gradient(135deg,#22c7f5,#3b82f6)" }}>WhatsApp Chat</div>
      <div className="lp-screen-body" style={{ background: "#eef2f6" }}>
        <div className="lp-bub in">Hi, do you have a slot tomorrow? 💇</div>
        <div className="lp-bub out">Yes! 11:30 AM or 4 PM works. Shall I book 11:30? 😊</div>
        <div className="lp-bub in">11:30 please</div>
        <div className="lp-bub out">Booked ✅ See you tomorrow at 11:30!</div>
      </div>
    </div>
  );
}
function LeadMock() {
  return (
    <div className="lp-screen">
      <div className="lp-screen-head" style={{ background: "linear-gradient(135deg,#8b5cf6,#a855f7)" }}>Lead Management</div>
      <div className="lp-screen-body">
        <div className="lp-mini-card"><div style={{ display: "flex", justifyContent: "space-between" }}><b>Priya Menon</b><span className="pill">New · 82</span></div><div className="muted2">Google · ₹45,000</div></div>
        <div className="lp-mini-card"><div style={{ display: "flex", justifyContent: "space-between" }}><b>Imran Khan</b><span className="pill won">Converted</span></div><div className="muted2">WhatsApp · ₹2,10,000</div></div>
        <div className="lp-mini-row"><span>🔔 Follow-up</span><span className="ok">Auto-sent</span></div>
      </div>
    </div>
  );
}

const FEATURES: Feature[] = [
  {
    eyebrow: "Google visibility", title: "Get found & chosen on Google", accent: "#f26f21",
    desc: "Your GBP AI Agent keeps your Google Business Profile optimized and replies to every review — so more customers discover and trust you.",
    chips: [
      { icon: "✍️", label: "SEO profile rewrite", sub: "with top keywords" },
      { icon: "⭐", label: "Replies to all reviews", sub: "+ AI sentiment" },
      { icon: "🖼️", label: "Daily posts & photos", sub: "auto-scheduled" },
      { icon: "📈", label: "Performance insights", sub: "views, calls, clicks" },
    ],
    mock: <GbpMock />,
  },
  {
    eyebrow: "WhatsApp", title: "Turn enquiries into bookings", accent: "#3b82f6",
    desc: "Your WhatsApp agent answers questions, nurtures leads and confirms bookings — in the customer's own language, 24/7 — then brings them back with campaigns.",
    chips: [
      { icon: "💬", label: "Answer & nurture leads" },
      { icon: "📅", label: "Confirm bookings" },
      { icon: "🔁", label: "Auto follow-ups" },
      { icon: "📣", label: "Campaigns & offers" },
    ],
    mock: <WaMock />,
  },
  {
    eyebrow: "Sales CRM", title: "Capture & convert every lead", accent: "#8b5cf6",
    desc: "Leads from Google, Meta and manual sources land in one pipeline, sorted by intent, with automatic follow-ups — so nothing ever slips.",
    chips: [
      { icon: "🎯", label: "Leads from Google / Meta" },
      { icon: "🗂️", label: "Kanban pipeline" },
      { icon: "⚡", label: "Lead score & value" },
      { icon: "🔔", label: "Automatic follow-ups" },
    ],
    mock: <LeadMock />,
  },
];

export default function Landing() {
  const [loggedIn, setLoggedIn] = useState(false);
  const [openFaq, setOpenFaq] = useState<number | null>(0);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);
  const year = new Date().getFullYear();
  const cta = loggedIn ? "/dashboard" : "/register";

  return (
    <div className="lp">
      {/* ===== Gradient hero band ===== */}
      <div className="lp-band">
        <header className="lp-topbar lp-topbar-dark">
          <div className="lp-topbar-in">
            <Link href="/" className="brand-lockup" style={{ textDecoration: "none" }}>
              <span className="brand-mark">e</span>
              <span style={{ display: "inline-flex", flexDirection: "column", lineHeight: 1 }}>
                <span className="brand-word brand-word-light" style={{ fontSize: 20 }}>Eydia.ai</span>
                <span className="brand-by" style={{ marginTop: 2, color: "rgba(255,255,255,.75)" }}>Automate · Engage · Grow</span>
              </span>
            </Link>
            <nav className="lp-topnav">
              <a href="#how">How it works</a>
              <a href="#pricing">Pricing</a>
              <a href="#faq">FAQ</a>
              <Link href={loggedIn ? "/dashboard" : "/login"} className="lp-btn lp-btn-light">{loggedIn ? "Dashboard →" : "Login →"}</Link>
            </nav>
          </div>
        </header>

        <section className="lp-band-hero lp-wrap">
          <span className="lp-eyebrow lp-eyebrow-light">One workspace · WhatsApp · Reviews · Leads</span>
          <h1 className="lp-band-h1">Your AI marketing team<br /><span className="hl">that delivers real growth.</span></h1>
          <p className="lp-band-sub">Eydia&apos;s AI agents handle the daily marketing work — Google reviews, WhatsApp conversations and leads — so your business gets found, replies fast and converts more. You stay in control from one login.</p>

          <div className="lp-agents">
            {HERO_AGENTS.map((a) => (
              <div key={a.name} className="lp-agent">
                <span className="lp-agent-ic">{a.icon}</span>
                <div><b>{a.name}</b><span>{a.tag}</span></div>
              </div>
            ))}
          </div>

          <div style={{ display: "flex", gap: 12, justifyContent: "center", flexWrap: "wrap", marginTop: 26 }}>
            <Link href={cta} className="lp-btn lp-btn-light lp-btn-lg">Book a Free Demo →</Link>
            <a href="#how" className="lp-btn lp-btn-ghost lp-btn-lg">See how it works</a>
          </div>
        </section>
      </div>

      {/* ===== Trust bar ===== */}
      <section className="lp-trust lp-section">
        <div className="lp-wrap">
          <span className="lp-eyebrow">Businesses growing with Eydia</span>
          <h2 className="lp-h2">Built for local businesses.</h2>
          <p className="lp-sub">From neighbourhood shops to multi-location brands, Eydia helps teams reach customers across India, the UAE, the UK, the USA and beyond.</p>
          <div className="lp-logos">
            {SECTORS.map((s) => (<div key={s} className="lp-logo"><div className="lp-logo-badge">{s.split(" ")[0]}</div><span>{s}</span></div>))}
          </div>
          <div className="lp-ratings">
            {RATINGS.map((r) => (
              <div key={r.name} className="lp-rating">
                <div className="lp-rating-top"><span className="lp-rating-ic">{r.ic || "⌘"}</span>{r.name}</div>
                <div className="lp-rating-score"><b>{r.score}</b><span className="den">{r.den}</span><span className="stars">★★★★★</span></div>
                <small>{r.note}</small>
              </div>
            ))}
          </div>
          <div className="lp-partners">
            <p>Eydia.ai works with the Meta &amp; Google tools your customers already use.</p>
            <div className="lp-partner-badges"><span className="lp-partner">∞ Meta Business</span><span className="lp-partner">G Google</span></div>
          </div>
        </div>
      </section>

      {/* ===== Meet your AI marketing team (feature rows) ===== */}
      <section id="how" className="lp-section" style={{ background: "var(--bg)" }}>
        <div className="lp-wrap">
          <span className="lp-eyebrow">How Eydia works</span>
          <h2 className="lp-h2">Meet your AI marketing team.</h2>
          <p className="lp-sub">Each agent handles a different part of the customer journey — all from one Eydia.ai login.</p>

          <div className="lp-features">
            {FEATURES.map((f, i) => (
              <div key={f.title} className={`lp-feature ${i % 2 === 1 ? "reverse" : ""}`}>
                <div className="lp-feature-copy">
                  <span className="lp-feature-eyebrow" style={{ color: f.accent }}>{f.eyebrow}</span>
                  <h3>{f.title}</h3>
                  <p>{f.desc}</p>
                  <div className="lp-feat-chips">
                    {f.chips.map((c) => (
                      <div key={c.label} className="lp-feat-chip">
                        <span className="lp-feat-ic">{c.icon}</span>
                        <div><b>{c.label}</b>{c.sub && <span>{c.sub}</span>}</div>
                      </div>
                    ))}
                  </div>
                  <Link href={cta} className="lp-btn lp-btn-primary">Book Free Demo →</Link>
                </div>
                <div className="lp-feature-visual">
                  <div className="lp-phone-mock">{f.mock}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ===== Stats ===== */}
      <section className="lp-section" style={{ background: "var(--card)" }}>
        <div className="lp-wrap">
          <span className="lp-eyebrow">Why Eydia</span>
          <h2 className="lp-h2">One team. Every channel. Real growth.</h2>
          <div className="lp-statsrow">
            {STATS.map((s) => (<div key={s.label} className="lp-statbig"><div className="lp-statbig-v">{s.big}</div><div className="lp-statbig-l">{s.label}</div></div>))}
          </div>
        </div>
      </section>

      {/* ===== Testimonials ===== */}
      <section id="stories" className="lp-stories lp-section">
        <div className="lp-wrap">
          <span className="lp-eyebrow">Customer stories</span>
          <h2 className="lp-h2">What business owners say about Eydia.</h2>
          <p className="lp-sub">Hear what changed when they put Eydia to work.</p>
          <div className="lp-story-grid">
            {STORIES.map((s) => (
              <div key={s.name} className="lp-story">
                <div className="q" style={{ fontSize: 30, color: "var(--accent)", lineHeight: 1, fontWeight: 800 }}>&ldquo;</div>
                <p>{s.q}</p>
                <div className="lp-story-author">
                  <span className="lp-avatar">{s.name.slice(0, 1)}</span>
                  <div><b>{s.name}</b><span>{s.role}</span></div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ===== Industries ===== */}
      <section className="lp-section" style={{ background: "var(--card)" }}>
        <div className="lp-wrap">
          <span className="lp-eyebrow">Made for your business</span>
          <h2 className="lp-h2">Built for business owners like you.</h2>
          <p className="lp-sub">Salons to clinics to restaurants — Eydia helps local businesses get found, reply fast and convert.</p>
          <div className="lp-industries">
            {INDUSTRIES.map((x) => (<div key={x.label} className="lp-ind"><span className="lp-ind-ic">{x.icon}</span>{x.label}</div>))}
          </div>
        </div>
      </section>

      {/* ===== FAQ ===== */}
      <section id="faq" className="lp-section">
        <div className="lp-wrap" style={{ maxWidth: 820 }}>
          <span className="lp-eyebrow">Know what you&apos;re getting</span>
          <h2 className="lp-h2">Questions, answered.</h2>
          <div className="lp-faq">
            {FAQS.map((f, i) => (
              <div key={f.q} className={`lp-faq-item ${openFaq === i ? "open" : ""}`}>
                <button className="lp-faq-q" onClick={() => setOpenFaq(openFaq === i ? null : i)}>
                  <span>{f.q}</span><span className="lp-faq-caret">{openFaq === i ? "–" : "+"}</span>
                </button>
                {openFaq === i && <div className="lp-faq-a">{f.a}</div>}
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ===== Pricing CTA ===== */}
      <section id="pricing" className="lp-final">
        <h2>Put the Eydia AI marketing team to work.</h2>
        <p>Book a free demo today — we&apos;ll walk you through the plans and how Eydia works for your business.</p>
        <div style={{ display: "flex", gap: 12, justifyContent: "center", flexWrap: "wrap" }}>
          <Link href={cta} className="lp-btn lp-btn-light lp-btn-lg">Book a Free Demo →</Link>
          <Link href="/login" className="lp-btn lp-btn-ghost lp-btn-lg">Login</Link>
        </div>
      </section>

      {/* ===== Footer ===== */}
      <footer className="lp-foot">
        <div className="lp-wrap lp-foot-grid">
          <div className="lp-foot-brand">
            <div className="brand-lockup"><span className="brand-mark">e</span><span className="brand-word brand-word-light" style={{ fontSize: 20 }}>Eydia.ai</span></div>
            <p>AI marketing agents for local businesses. Get discovered, turn enquiries into opportunities and bring customers back.</p>
            <div className="lp-foot-social"><a href="#" aria-label="Facebook">f</a><a href="#" aria-label="Instagram">◎</a><a href="#" aria-label="LinkedIn">in</a></div>
          </div>
          <div className="lp-foot-col">
            <h4>Eydia for</h4>
            <a href="#">Salon Owners</a><a href="#">Gyms & Fitness</a><a href="#">Doctors & Clinics</a><a href="#">Restaurants & Bars</a><a href="#">Car Garages</a><a href="#">Home Services</a>
          </div>
          <div className="lp-foot-col">
            <h4>Product</h4>
            <a href="#how">Google Reviews / GBP</a><a href="#how">WhatsApp Marketing</a><a href="#how">Lead Management</a><a href="#pricing">Pricing</a>
          </div>
          <div className="lp-foot-col">
            <h4>Company</h4>
            <Link href="/about">About us</Link><Link href="/contact">Contact us</Link><Link href="/privacy">Privacy Policy</Link><Link href="/terms">Terms</Link>
            <p style={{ marginTop: 8 }}>support@eydia.in</p>
          </div>
        </div>
        <div className="lp-foot-bottom">
          <span>© {year} Eydia.ai — Automate · Engage · Grow</span>
          <span><Link href="/terms">Terms</Link> · <Link href="/privacy">Privacy</Link> · <Link href="/cancellation-policy">Refund</Link></span>
        </div>
      </footer>
    </div>
  );
}
