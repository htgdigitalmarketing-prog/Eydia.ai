"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";

// Eydia.ai unifies three products (WhatsApp, Google Reviews/GBP, Leads CRM) into
// one workspace. Landing styled in the Grexa look: lavender canvas, royal-blue
// CTAs, soft rounded cards. Content is Eydia's own.

// The four outcomes map to Eydia's three products + re-engagement campaigns.
const CHIPS = [
  { icon: "🚀", label: "Rank #1 on Google" },
  { icon: "💬", label: "Never miss a lead" },
  { icon: "📈", label: "Close more sales" },
  { icon: "🔄", label: "Bring customers back" },
];

// Industry sectors Eydia serves (placeholder logo tiles — swap for client logos).
const SECTORS = ["Salons", "Clinics", "Gyms & Fitness", "Restaurants", "Retail", "Local Services"];

// Store/review highlights — replace the numbers with your real published ratings.
const RATINGS = [
  { ic: "", name: "App Store", score: "4.8", den: "/5", note: "iOS app" },
  { ic: "▶", name: "Google Play", score: "4.6", den: "/5", note: "Android app" },
  { ic: "G", name: "Google", score: "4.8", den: "/5", note: "Business reviews" },
  { ic: "★", name: "Trustpilot", score: "4.6", den: "/5", note: "Verified" },
];

// Representative customer stories — replace with your real testimonials.
const STORIES = [
  { q: "Our Google Business Profile views and walk-ins jumped after Eydia started replying to reviews and posting weekly. Footfall is up even on weekdays.", name: "Anvika Salon", role: "Owner" },
  { q: "The WhatsApp agent answers enquiries and books slots on its own, in the customer's language. We stopped losing leads to slow replies.", name: "Dr. Rahul's Clinic", role: "Founder" },
  { q: "All our leads land in one CRM, sorted by intent. Follow-ups are automatic — lead management finally feels hassle-free.", name: "FnH Services", role: "Director" },
];

type PlanCard = { name: string; eyebrow: string; price: string; period: string; desc: string; popular?: boolean; bullets: string[] };
const PLANS: PlanCard[] = [
  { name: "Starter", eyebrow: "WhatsApp", price: "Free", period: "mo", desc: "Run your WhatsApp inbox and campaigns.", bullets: ["WhatsApp dashboard", "2 team agents", "1,000 contacts", "Reports", "Email support"] },
  { name: "Growth", eyebrow: "All 3 agents", price: "₹2,999", period: "mo", popular: true, desc: "Your complete AI marketing team.", bullets: ["All 3 dashboards unlocked", "10 agents · 5 GBP locations", "25,000 contacts & leads", "AI chatbot, automations & social", "Priority support"] },
  { name: "Enterprise", eyebrow: "Unlimited", price: "₹9,999", period: "mo", desc: "Scale across teams and locations.", bullets: ["All 3 dashboards, unlimited", "Unlimited agents & locations", "Unlimited contacts & leads", "API access & advanced reports", "Dedicated manager"] },
];

const BUDGETS = ["More than ₹5000", "₹3000 – ₹5000", "Less than ₹3000"];

export default function Landing() {
  const [loggedIn, setLoggedIn] = useState(false);
  useEffect(() => { setLoggedIn(!!getToken()); }, []);

  // Book-a-demo form is client-side only (no backend) — captures intent and shows
  // a thank-you. Wire it to your CRM/leads endpoint when ready.
  const [form, setForm] = useState({ business: "", phone: "", budget: BUDGETS[1] });
  const [sent, setSent] = useState(false);
  const canSubmit = form.business.trim() && form.phone.trim();

  const year = new Date().getFullYear();

  return (
    <div className="lp">
      {/* Top bar */}
      <header className="lp-topbar">
        <div className="lp-topbar-in">
          <div className="brand-lockup">
            <span className="brand-mark">e</span>
            <span style={{ display: "inline-flex", flexDirection: "column", lineHeight: 1 }}>
              <span className="brand-word" style={{ fontSize: 20 }}>Eydia.ai</span>
              <span className="brand-by" style={{ marginTop: 2 }}>Automate · Engage · Grow</span>
            </span>
          </div>
          <nav className="lp-topnav">
            <a href="#how">How it works</a>
            <a href="#pricing">Pricing</a>
            <a href="#stories">Stories</a>
            {loggedIn
              ? <Link href="/dashboard" className="lp-btn lp-btn-primary">Go to dashboard →</Link>
              : <Link href="/login" className="lp-btn lp-btn-primary">Login →</Link>}
          </nav>
        </div>
      </header>

      {/* Hero */}
      <section className="lp-hero">
        <div className="lp-wrap lp-hero-grid">
          {/* Copy */}
          <div className="lp-hero-copy">
            <span className="lp-badge"><span className="dot">e</span> One workspace · WhatsApp · Reviews · Leads</span>
            <h1 className="lp-h1">Want more customers?<br /><span className="accent">Let&apos;s show you how.</span></h1>
            <div className="lp-chips">
              {CHIPS.map((c) => (
                <div key={c.label} className="lp-chip"><span className="lp-chip-ic">{c.icon}</span>{c.label}</div>
              ))}
            </div>
            <p className="lp-lead">Your AI agents handle the daily marketing work — WhatsApp conversations, Google reviews and leads — and you stay in control from one Eydia.ai login.</p>
            <div className="lp-quote">
              <p>&ldquo;Eydia keeps our WhatsApp, Google reviews and leads in one place. Replies go out instantly and our follow-ups never slip. It has genuinely helped us reach more customers.&rdquo;</p>
              <div className="lp-quote-author">
                <span className="lp-avatar">P</span>
                <div><b>Pankaj Gupta</b> <span>· Founder, Ultimate Fitness Centre</span></div>
              </div>
            </div>
          </div>

          {/* Book a demo card */}
          <div className="lp-demo">
            {!sent ? (
              <form onSubmit={(e) => { e.preventDefault(); if (canSubmit) setSent(true); }}>
                <h2>Book a Free Demo</h2>
                <div className="lp-field">
                  <label className="lp-label">Business Details <span className="req">*</span></label>
                  <input className="lp-input" value={form.business} onChange={(e) => setForm((f) => ({ ...f, business: e.target.value }))} placeholder="Start typing your business name" required />
                </div>
                <div className="lp-field">
                  <label className="lp-label">Phone Number <span className="req">*</span></label>
                  <div className="lp-phone">
                    <select className="lp-input lp-cc" defaultValue="+91" aria-label="Country code">
                      <option>+91</option><option>+1</option><option>+44</option><option>+971</option>
                    </select>
                    <input className="lp-input" value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} placeholder="81234 56789" inputMode="tel" required />
                  </div>
                </div>
                <div className="lp-field">
                  <label className="lp-label">Monthly Budget</label>
                  <p className="lp-hint">(How much are you willing to spend on digital marketing?)</p>
                  <div className="lp-budget">
                    {BUDGETS.map((b) => (
                      <label key={b} className={`lp-radio ${form.budget === b ? "active" : ""}`}>
                        <input type="radio" name="budget" checked={form.budget === b} onChange={() => setForm((f) => ({ ...f, budget: b }))} />
                        {b}
                      </label>
                    ))}
                  </div>
                </div>
                <div className="lp-slots">⏳ Only 7 Slots Left</div>
                <button type="submit" className="lp-btn lp-btn-primary lp-btn-lg lp-btn-block" disabled={!canSubmit}>Book Free Demo</button>
                <div className="lp-demo-note">🔒 Your details are safe &amp; will never be shared</div>
              </form>
            ) : (
              <div className="lp-demo-success">
                <div className="tick">✓</div>
                <h3>Thanks, {form.business.trim()}!</h3>
                <p>Our team will reach out on the number you shared to book your free demo. Prefer to explore now? <Link href="/register">Create your account →</Link></p>
              </div>
            )}
          </div>
        </div>
      </section>

      {/* Trust bar */}
      <section className="lp-trust lp-section">
        <div className="lp-wrap">
          <span className="lp-eyebrow">Businesses growing with Eydia</span>
          <h2 className="lp-h2">Built for local businesses.</h2>
          <p className="lp-sub">From neighbourhood shops to multi-location brands, Eydia helps teams reach customers across India, the UAE, the UK, the USA and beyond.</p>

          <div className="lp-logos">
            {SECTORS.map((s) => (
              <div key={s} className="lp-logo">
                <div className="lp-logo-badge">{s.split(" ")[0]}</div>
                <span>{s}</span>
              </div>
            ))}
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
            <p>Eydia.ai works with Meta &amp; Google tools your customers already use.</p>
            <div className="lp-partner-badges">
              <span className="lp-partner">∞ Meta Business</span>
              <span className="lp-partner">G Google</span>
            </div>
          </div>
        </div>
      </section>

      {/* How it works */}
      <section id="how" className="lp-section">
        <div className="lp-wrap">
          <span className="lp-eyebrow">What&apos;s included</span>
          <h2 className="lp-h2">Meet your AI marketing team.</h2>
          <p className="lp-sub">Each agent handles a different part of the customer journey — all from one Eydia.ai login.</p>
          <div className="lp-story-grid">
            <div className="lp-story">
              <div className="lp-chip-ic" style={{ marginBottom: 12 }}>⭐</div>
              <b style={{ fontSize: 17 }}>GBP AI Agent</b>
              <p>Get found on Google: SEO profile rewrite, replies to every review, AI sentiment, daily posts, photo scheduler and performance insights.</p>
            </div>
            <div className="lp-story">
              <div className="lp-chip-ic" style={{ marginBottom: 12 }}>💬</div>
              <b style={{ fontSize: 17 }}>WhatsApp Chat Agent</b>
              <p>Turn enquiries into bookings: answers questions, nurtures leads, confirms slots, auto follow-ups when chats go quiet — multilingual.</p>
            </div>
            <div className="lp-story">
              <div className="lp-chip-ic" style={{ marginBottom: 12 }}>📣</div>
              <b style={{ fontSize: 17 }}>WhatsApp Marketing Agent</b>
              <p>Bring customers back: campaigns and offers for the right occasions, booking reminders, broadcasts and automations built for your business.</p>
            </div>
          </div>
        </div>
      </section>

      {/* Customer stories */}
      <section id="stories" className="lp-stories lp-section">
        <div className="lp-wrap">
          <span className="lp-eyebrow">Customer stories</span>
          <h2 className="lp-h2">Business owners. Real results.</h2>
          <p className="lp-sub">Hear what changed when they put Eydia to work.</p>
          <div className="lp-story-grid">
            {STORIES.map((s) => (
              <div key={s.name} className="lp-story">
                <div className="q">&ldquo;</div>
                <p>{s.q}</p>
                <div className="lp-story-author">
                  <span className="lp-avatar">{s.name.slice(0, 1)}</span>
                  <div><b>{s.name}</b><span>{s.role}</span></div>
                </div>
              </div>
            ))}
          </div>
          <div className="lp-dots"><span className="lp-dot active" /><span className="lp-dot" /><span className="lp-dot" /></div>
        </div>
      </section>

      {/* Pricing */}
      <section id="pricing" className="lp-pricing lp-section">
        <div className="lp-wrap">
          <span className="lp-eyebrow">Plans &amp; pricing</span>
          <h2 className="lp-h2">Your next stage of growth starts here.</h2>
          <p className="lp-sub">Start with WhatsApp, or put all three Eydia AI agents to work. Upgrade anytime.</p>
          <div className="lp-price-grid">
            {PLANS.map((plan) => (
              <div key={plan.name} className={`lp-price-card ${plan.popular ? "lp-price-popular" : ""}`}>
                {plan.popular && <div className="lp-price-tag">Most popular</div>}
                <div className="lp-price-eyebrow">{plan.eyebrow}</div>
                <h3>{plan.name}</h3>
                <p className="lp-price-desc">{plan.desc}</p>
                <div className="lp-price-amount">
                  <span className="lp-price-value">{plan.price}</span>
                  <span className="lp-price-per">/{plan.period}</span>
                </div>
                <ul className="lp-check-list">
                  {plan.bullets.map((b) => <li key={b}>{b}</li>)}
                </ul>
                <Link href="/register" className={`lp-btn lp-btn-lg lp-btn-block ${plan.popular ? "lp-btn-primary" : "lp-btn-outline"}`}>Get started →</Link>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Final CTA */}
      <section className="lp-final">
        <h2>Ready to grow your business?</h2>
        <p>See what Eydia AI agents can do for you.</p>
        <div style={{ display: "flex", gap: 12, justifyContent: "center", flexWrap: "wrap" }}>
          <Link href="/register" className="lp-btn lp-btn-light lp-btn-lg">Create account →</Link>
          <Link href="/login" className="lp-btn lp-btn-ghost lp-btn-lg">Login</Link>
        </div>
      </section>

      {/* Footer */}
      <footer className="lp-foot">
        <div className="lp-wrap lp-foot-grid">
          <div className="lp-foot-brand">
            <div className="brand-lockup">
              <span className="brand-mark">e</span>
              <span className="brand-word" style={{ fontSize: 20, color: "#fff" }}>Eydia.ai</span>
            </div>
            <p>AI marketing agents for local businesses. Get discovered, turn enquiries into opportunities and bring customers back.</p>
            <div className="lp-foot-social"><a href="#" aria-label="Facebook">f</a><a href="#" aria-label="Instagram">◎</a><a href="#" aria-label="LinkedIn">in</a></div>
          </div>
          <div className="lp-foot-col">
            <h4>Product</h4>
            <a href="#how">GBP AI Agent</a>
            <a href="#how">WhatsApp Chat</a>
            <a href="#how">WhatsApp Marketing</a>
            <a href="#pricing">Pricing</a>
          </div>
          <div className="lp-foot-col">
            <h4>Company</h4>
            <Link href="/about">About us</Link>
            <Link href="/contact">Contact us</Link>
            <Link href="/privacy">Privacy Policy</Link>
            <Link href="/terms">Terms &amp; Conditions</Link>
          </div>
          <div className="lp-foot-col">
            <h4>Contact us</h4>
            <p>Email: heltogtechnologies@gmail.com</p>
            <p>WhatsApp · Reviews · Leads — one workspace.</p>
            <Link href="/register" className="lp-btn lp-btn-primary" style={{ marginTop: 4 }}>Book Free Demo →</Link>
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
