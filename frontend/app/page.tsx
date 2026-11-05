"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";

// Eydia.ai marketing homepage — "AI marketing team" layout: three AI agents
// (Google Business Profile, WhatsApp Chat, WhatsApp Marketing), trust, outcomes,
// stats, testimonials, categories and FAQ. Self-contained styles so it renders
// consistently regardless of the app's global CSS.

const AGENTS = [
  { icon: "⭐", tag: "GBP AI Agent", title: "Get more customers", sub: "from Google", color: "#2160ef" },
  { icon: "💬", tag: "WhatsApp Chat", title: "Get more bookings", sub: "from enquiries", color: "#16a34a" },
  { icon: "📣", tag: "WhatsApp Marketing", title: "Drive repeat sales", sub: "with campaigns", color: "#7b2ff7" },
];

const FEATURES = [
  {
    badge: "GBP AI Agent", icon: "⭐", flip: false,
    title: "Get more calls from Google",
    lead: "Your AI agent keeps your Google Business Profile fresh and optimised, so nearby customers find you first.",
    points: ["SEO profile rewrite with top keywords", "Daily SEO-optimised posts", "Reviews — get more & auto-reply to all", "Latest photo uploads", "Performance analytics"],
    chip: { t: "It starts with a local search", s: "Google · \"best near me\"" },
  },
  {
    badge: "WhatsApp Chat", icon: "💬", flip: true,
    title: "Turn enquiries into bookings",
    lead: "Your AI agent answers questions, suggests available slots and confirms appointments — in the customer's own language.",
    points: ["Answer questions & nurture leads", "Suggest slots & confirm bookings", "Auto follow-up when chats go quiet", "Multilingual — talks like your customer"],
    chip: { t: "A new enquiry comes in", s: "WhatsApp · replied in seconds" },
  },
  {
    badge: "WhatsApp Marketing", icon: "📣", flip: false,
    title: "Bring customers back with campaigns",
    lead: "Your AI agent sends offers and creative ready-to-send broadcasts, so one-time visitors become repeat customers.",
    points: ["Campaigns & offers for occasions", "Send brochures & targeted offers", "Booking reminders & follow-ups", "Automations configured for your business"],
    chip: { t: "WhatsApp Marketing in action", s: "Broadcast · 98% delivered" },
  },
];

const STATS = [
  { v: "₹9 Lakh+", l: "campaign revenue in 3 months", d: "WhatsApp Marketing" },
  { v: "90 → 355", l: "monthly calls from Google in 6 months", d: "GBP AI Agent" },
  { v: "1200+", l: "patients seen in 2 months", d: "WhatsApp Chat" },
];

const TESTIMONIALS = [
  { q: "Eydia solved our visibility problem. We got 2x better online visibility and stronger Google ranking.", n: "Rajan", r: "Owner, Multibrand Bike Service" },
  { q: "We were low on Google search. Eydia pushed our new location to the top rank.", n: "Dr. Bhanuchandra", r: "Owner, Advanced Physiotherapy" },
  { q: "With Eydia AI, we moved from 11th rank to top 4 on Google, with more views and more calls.", n: "Owner", r: "Diagnostic Centre" },
];

const CATEGORIES = [
  { i: "🩺", t: "Clinics & dentists" }, { i: "💇", t: "Salons" }, { i: "🏋️", t: "Gyms & fitness" },
  { i: "✈️", t: "Travel agencies" }, { i: "🍽️", t: "Restaurants & cafés" }, { i: "🧴", t: "Pest control" },
  { i: "🔧", t: "Car garages" }, { i: "🧘", t: "Yoga & wellness" }, { i: "🧹", t: "Home services" }, { i: "🏨", t: "Hotels & stays" },
];

const FAQS = [
  { q: "Will it work if I am not tech-savvy?", a: "Yes. Eydia runs on autopilot. You connect Google and WhatsApp once; the AI agents handle the rest and you just watch results grow." },
  { q: "How do the Google and WhatsApp agents work together?", a: "The GBP agent brings new customers from Google, the WhatsApp Chat agent converts enquiries into bookings, and WhatsApp Marketing brings them back — one connected marketing team." },
  { q: "How soon will I see results?", a: "Most businesses see more calls and reviews within the first few weeks as the AI optimises your profile and replies to every customer." },
  { q: "Is my customer data safe?", a: "Yes. Data is encrypted, access is controlled per workspace, and we never share your customer information." },
  { q: "Will someone help me if I get stuck?", a: "Absolutely — our team supports you over WhatsApp and email to get you set up and growing." },
  { q: "Does Eydia work for all businesses?", a: "Eydia is built for local businesses — clinics, salons, gyms, restaurants, services and more." },
];

export default function Landing() {
  const [loggedIn, setLoggedIn] = useState(false);
  const [faq, setFaq] = useState<number | null>(0);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);
  const year = new Date().getFullYear();
  const cta = loggedIn ? "/dashboard" : "/register";
  const grad = "linear-gradient(120deg,#16b6f2 0%,#2160ef 40%,#6a22f0 78%,#ad17e6 100%)";

  return (
    <div style={{ background: "#f6f7fb", color: "#0f1b2d", fontFamily: "Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif", lineHeight: 1.55 }}>
      <style>{`
        .ei-wrap{max-width:1140px;margin:0 auto;padding:0 20px;}
        .ei-btn{display:inline-flex;align-items:center;gap:7px;border-radius:12px;font-weight:700;text-decoration:none;cursor:pointer;transition:transform .12s, box-shadow .12s;}
        .ei-btn:hover{transform:translateY(-1px);}
        .ei-btn-grad{background:${grad};color:#fff;box-shadow:0 10px 26px rgba(76,87,216,.28);}
        .ei-btn-ghost{background:#fff;color:#1a2230;border:1px solid #e6e8f2;}
        .ei-card{background:#fff;border:1px solid #eceef6;border-radius:18px;}
        .ei-grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;}
        .ei-feat{display:grid;grid-template-columns:1.05fr .95fr;gap:34px;align-items:center;}
        .ei-cats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;}
        .ei-fade{opacity:0;transform:translateY(14px);animation:eiUp .6s forwards;}
        @keyframes eiUp{to{opacity:1;transform:none;}}
        @media(max-width:860px){
          .ei-grid3{grid-template-columns:1fr;}
          .ei-feat{grid-template-columns:1fr;gap:18px;}
          .ei-feat.flip > div:first-child{order:2;}
          .ei-cats{grid-template-columns:repeat(2,1fr);}
          .ei-hnav{display:none!important;}
          .ei-h1{font-size:34px!important;}
        }
      `}</style>

      {/* Header */}
      <header style={{ position: "sticky", top: 0, zIndex: 20, background: "rgba(246,247,251,.85)", backdropFilter: "blur(10px)", borderBottom: "1px solid #eceef6" }}>
        <div className="ei-wrap" style={{ display: "flex", alignItems: "center", justifyContent: "space-between", height: 66 }}>
          <Link href="/"><img src="/eydia-logo.png" alt="Eydia.ai" style={{ height: 34 }} /></Link>
          <nav className="ei-hnav" style={{ display: "flex", gap: 26, fontSize: 14.5, fontWeight: 600 }}>
            <a href="#how" style={{ color: "#44506a", textDecoration: "none" }}>How it works</a>
            <Link href="/pricing" style={{ color: "#44506a", textDecoration: "none" }}>Pricing</Link>
            <a href="#faq" style={{ color: "#44506a", textDecoration: "none" }}>FAQ</a>
          </nav>
          <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
            <Link href="/login" className="ei-btn ei-btn-ghost" style={{ padding: "9px 16px", fontSize: 14 }}>{loggedIn ? "Open app" : "Login"}</Link>
            <Link href={cta} className="ei-btn ei-btn-grad" style={{ padding: "9px 18px", fontSize: 14 }}>Get Started →</Link>
          </div>
        </div>
      </header>

      {/* Hero */}
      <section style={{ background: grad, color: "#fff", padding: "64px 0 90px", position: "relative", overflow: "hidden" }}>
        <div className="ei-wrap" style={{ textAlign: "center", position: "relative", zIndex: 2 }}>
          <span style={{ display: "inline-block", background: "rgba(255,255,255,.16)", border: "1px solid rgba(255,255,255,.28)", padding: "5px 14px", borderRadius: 999, fontSize: 12.5, fontWeight: 700, marginBottom: 18 }}>⚡ Trusted by growing local businesses</span>
          <h1 className="ei-h1" style={{ fontSize: 50, fontWeight: 900, letterSpacing: "-.02em", margin: "0 0 14px", lineHeight: 1.08 }}>
            Your AI Marketing Team<br /><span style={{ opacity: .92 }}>that delivers real revenue</span>
          </h1>
          <p style={{ maxWidth: 640, margin: "0 auto 26px", fontSize: 17, opacity: .94 }}>
            Three AI agents work your Google Business Profile, WhatsApp chats and campaigns — so you get more customers, more bookings and more repeat sales, fully on autopilot.
          </p>
          <div className="ei-grid3" style={{ maxWidth: 820, margin: "0 auto 26px" }}>
            {AGENTS.map((a, i) => (
              <div key={a.tag} className="ei-fade" style={{ animationDelay: `${i * 0.08}s`, background: "rgba(255,255,255,.12)", border: "1px solid rgba(255,255,255,.22)", borderRadius: 16, padding: "16px 14px", textAlign: "left", backdropFilter: "blur(6px)" }}>
                <div style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12, fontWeight: 700, opacity: .9, marginBottom: 8 }}><span style={{ fontSize: 16 }}>{a.icon}</span>{a.tag}</div>
                <div style={{ fontSize: 17, fontWeight: 800 }}>{a.title}</div>
                <div style={{ fontSize: 13.5, opacity: .85 }}>{a.sub}</div>
              </div>
            ))}
          </div>
          <Link href={cta} className="ei-btn" style={{ background: "#fff", color: "#2160ef", padding: "14px 28px", fontSize: 16 }}>Book your free demo →</Link>
        </div>
        <div style={{ position: "absolute", right: -120, top: -120, width: 420, height: 420, borderRadius: "50%", background: "rgba(255,255,255,.1)" }} />
      </section>

      {/* Trust */}
      <section style={{ padding: "34px 0" }}>
        <div className="ei-wrap" style={{ textAlign: "center" }}>
          <div style={{ fontSize: 13, fontWeight: 800, letterSpacing: ".1em", textTransform: "uppercase", color: "#7b8498" }}>Businesses growing with Eydia</div>
          <h2 style={{ fontSize: 24, fontWeight: 800, margin: "6px 0 14px" }}>Trusted by local businesses across India</h2>
          <div style={{ display: "flex", gap: 14, justifyContent: "center", flexWrap: "wrap" }}>
            {[["Google", "4.8★"], ["App Store", "4.8★"], ["Play Store", "4.6★"], ["Meta Partner", "✓"], ["Google Partner", "✓"]].map(([n, v]) => (
              <div key={n} className="ei-card" style={{ padding: "10px 16px", fontSize: 13.5, fontWeight: 700 }}>{n} <span style={{ color: "#2160ef" }}>{v}</span></div>
            ))}
          </div>
        </div>
      </section>

      {/* Meet the team / features */}
      <section id="how" style={{ padding: "26px 0 10px" }}>
        <div className="ei-wrap" style={{ textAlign: "center", marginBottom: 26 }}>
          <div style={{ fontSize: 13, fontWeight: 800, letterSpacing: ".1em", textTransform: "uppercase", color: "#4c57d8" }}>Meet your AI marketing team</div>
          <h2 style={{ fontSize: 30, fontWeight: 900, margin: "6px 0 0" }}>Each agent works a different part of your customer journey</h2>
        </div>
        <div className="ei-wrap" style={{ display: "grid", gap: 20 }}>
          {FEATURES.map((f) => (
            <div key={f.title} className={`ei-card ei-feat${f.flip ? " flip" : ""}`} style={{ padding: 26 }}>
              <div>
                <span style={{ display: "inline-flex", alignItems: "center", gap: 7, background: "#eef1ff", color: "#2160ef", padding: "4px 11px", borderRadius: 999, fontSize: 12.5, fontWeight: 800 }}>{f.icon} {f.badge}</span>
                <h3 style={{ fontSize: 24, fontWeight: 900, margin: "12px 0 8px" }}>{f.title}</h3>
                <p className="muted" style={{ color: "#5b6577", fontSize: 14.5, margin: "0 0 14px" }}>{f.lead}</p>
                <div style={{ display: "grid", gap: 9 }}>
                  {f.points.map((p) => (
                    <div key={p} style={{ display: "flex", gap: 9, alignItems: "flex-start", fontSize: 14 }}>
                      <span style={{ color: "#16a34a", fontWeight: 900 }}>✓</span>{p}
                    </div>
                  ))}
                </div>
              </div>
              <div style={{ background: "linear-gradient(160deg,#f3f5ff,#eef7ff)", border: "1px solid #e6ecfb", borderRadius: 16, padding: 20, minHeight: 230, display: "grid", placeItems: "center" }}>
                <div style={{ width: "100%", maxWidth: 300 }}>
                  <div className="ei-card" style={{ padding: 14, display: "flex", gap: 10, alignItems: "center", marginBottom: 12 }}>
                    <span style={{ width: 40, height: 40, borderRadius: 12, background: grad, color: "#fff", display: "grid", placeItems: "center", fontSize: 18 }}>{f.icon}</span>
                    <div><div style={{ fontWeight: 800, fontSize: 13.5 }}>{f.chip.t}</div><div style={{ color: "#7b8498", fontSize: 12 }}>{f.chip.s}</div></div>
                  </div>
                  {f.points.slice(0, 3).map((p, i) => (
                    <div key={i} className="ei-card" style={{ padding: "9px 12px", fontSize: 12.5, marginBottom: 8, display: "flex", gap: 8, alignItems: "center" }}>
                      <span style={{ width: 8, height: 8, borderRadius: 3, background: ["#2160ef", "#16a34a", "#7b2ff7"][i] }} />{p}
                    </div>
                  ))}
                </div>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Stats */}
      <section style={{ padding: "48px 0" }}>
        <div className="ei-wrap" style={{ textAlign: "center", marginBottom: 22 }}>
          <h2 style={{ fontSize: 28, fontWeight: 900, margin: 0 }}>Real businesses. Real growth.</h2>
          <p style={{ color: "#5b6577", marginTop: 6 }}>More calls, more enquiries and more repeat sales — shared with their consent.</p>
        </div>
        <div className="ei-wrap ei-grid3">
          {STATS.map((s) => (
            <div key={s.l} className="ei-card" style={{ padding: 24, textAlign: "center" }}>
              <div style={{ fontSize: 30, fontWeight: 900, background: grad, WebkitBackgroundClip: "text", WebkitTextFillColor: "transparent" }}>{s.v}</div>
              <div style={{ fontSize: 14, marginTop: 4 }}>{s.l}</div>
              <div style={{ fontSize: 12, color: "#7b8498", marginTop: 8, fontWeight: 700 }}>{s.d}</div>
            </div>
          ))}
        </div>
      </section>

      {/* Testimonials */}
      <section style={{ padding: "10px 0 48px" }}>
        <div className="ei-wrap" style={{ textAlign: "center", marginBottom: 22 }}>
          <h2 style={{ fontSize: 26, fontWeight: 900, margin: 0 }}>What business owners say about Eydia</h2>
        </div>
        <div className="ei-wrap ei-grid3">
          {TESTIMONIALS.map((t) => (
            <div key={t.n} className="ei-card" style={{ padding: 22 }}>
              <div style={{ fontSize: 30, color: "#c7cedf", lineHeight: 1 }}>&ldquo;</div>
              <p style={{ fontSize: 14.5, margin: "4px 0 16px" }}>{t.q}</p>
              <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                <span style={{ width: 36, height: 36, borderRadius: "50%", background: grad, color: "#fff", display: "grid", placeItems: "center", fontWeight: 800 }}>{t.n[0]}</span>
                <div><div style={{ fontWeight: 800, fontSize: 13.5 }}>{t.n}</div><div style={{ color: "#7b8498", fontSize: 12 }}>{t.r}</div></div>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Categories */}
      <section style={{ padding: "10px 0 48px" }}>
        <div className="ei-wrap" style={{ textAlign: "center", marginBottom: 22 }}>
          <div style={{ fontSize: 13, fontWeight: 800, letterSpacing: ".1em", textTransform: "uppercase", color: "#4c57d8" }}>Built for business owners like you</div>
          <h2 style={{ fontSize: 26, fontWeight: 900, margin: "6px 0 0" }}>From clinics and salons to gyms and restaurants</h2>
        </div>
        <div className="ei-wrap ei-cats">
          {CATEGORIES.map((c) => (
            <div key={c.t} className="ei-card" style={{ padding: "16px 12px", textAlign: "center" }}>
              <div style={{ fontSize: 26 }}>{c.i}</div>
              <div style={{ fontSize: 13, fontWeight: 700, marginTop: 6 }}>{c.t}</div>
            </div>
          ))}
        </div>
      </section>

      {/* FAQ */}
      <section id="faq" style={{ padding: "10px 0 54px" }}>
        <div className="ei-wrap" style={{ maxWidth: 760 }}>
          <h2 style={{ fontSize: 26, fontWeight: 900, textAlign: "center", marginBottom: 20 }}>Common questions</h2>
          {FAQS.map((f, i) => (
            <div key={f.q} className="ei-card" style={{ marginBottom: 10, overflow: "hidden" }}>
              <button onClick={() => setFaq(faq === i ? null : i)} style={{ width: "100%", textAlign: "left", background: "none", border: "none", padding: "16px 18px", fontSize: 15, fontWeight: 700, cursor: "pointer", display: "flex", justifyContent: "space-between", gap: 12 }}>
                {f.q}<span style={{ color: "#7b8498" }}>{faq === i ? "−" : "+"}</span>
              </button>
              {faq === i && <div style={{ padding: "0 18px 16px", color: "#5b6577", fontSize: 14 }}>{f.a}</div>}
            </div>
          ))}
        </div>
      </section>

      {/* Final CTA */}
      <section style={{ padding: "0 0 60px" }}>
        <div className="ei-wrap">
          <div style={{ background: grad, borderRadius: 22, padding: "48px 24px", textAlign: "center", color: "#fff" }}>
            <h2 style={{ fontSize: 30, fontWeight: 900, margin: "0 0 10px" }}>Put your AI marketing team to work</h2>
            <p style={{ opacity: .92, maxWidth: 520, margin: "0 auto 22px" }}>Connect Google and WhatsApp once. Eydia&apos;s AI agents help you get found, win bookings and bring customers back.</p>
            <Link href={cta} className="ei-btn" style={{ background: "#fff", color: "#2160ef", padding: "14px 30px", fontSize: 16 }}>Get Started Free →</Link>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer style={{ background: "#0f1b2d", color: "#aeb8cc", padding: "40px 0 28px" }}>
        <div className="ei-wrap" style={{ display: "flex", justifyContent: "space-between", gap: 24, flexWrap: "wrap" }}>
          <div style={{ maxWidth: 280 }}>
            <img src="/eydia-logo.png" alt="Eydia.ai" style={{ height: 30, filter: "brightness(0) invert(1)", marginBottom: 10 }} />
            <p style={{ fontSize: 13 }}>AI marketing agents for local businesses. Get discovered, turn enquiries into bookings and bring customers back.</p>
          </div>
          <div style={{ display: "flex", gap: 40, flexWrap: "wrap" }}>
            <div>
              <div style={{ color: "#fff", fontWeight: 700, fontSize: 14, marginBottom: 10 }}>Product</div>
              {[["Pricing", "/pricing"], ["Login", "/login"], ["Get started", cta]].map(([t, h]) => (
                <Link key={t} href={h} style={{ display: "block", color: "#aeb8cc", textDecoration: "none", fontSize: 13.5, marginBottom: 7 }}>{t}</Link>
              ))}
            </div>
            <div>
              <div style={{ color: "#fff", fontWeight: 700, fontSize: 14, marginBottom: 10 }}>Company</div>
              {[["About", "/about"], ["Contact", "/contact"], ["Privacy", "/privacy"], ["Terms", "/terms"]].map(([t, h]) => (
                <Link key={t} href={h} style={{ display: "block", color: "#aeb8cc", textDecoration: "none", fontSize: 13.5, marginBottom: 7 }}>{t}</Link>
              ))}
            </div>
          </div>
        </div>
        <div className="ei-wrap" style={{ borderTop: "1px solid #223049", marginTop: 26, paddingTop: 18, fontSize: 12.5 }}>© {year} Eydia.ai · Automate · Engage · Grow</div>
      </footer>
    </div>
  );
}
