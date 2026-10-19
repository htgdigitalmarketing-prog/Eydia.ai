"use client";

import { useState } from "react";
import Link from "next/link";

export default function ContactPage() {
  const [form, setForm] = useState({ name: "", email: "", message: "" });
  const [sent, setSent] = useState(false);
  const ok = form.name.trim() && form.email.trim() && form.message.trim();

  return (
    <div className="lp">
      {/* Top bar */}
      <header className="lp-topbar">
        <div className="lp-topbar-in">
          <Link href="/" className="brand-lockup" style={{ textDecoration: "none" }}>
            <span className="brand-mark">e</span>
            <span className="brand-word" style={{ fontSize: 20 }}>Eydia.ai</span>
          </Link>
          <nav className="lp-topnav">
            <Link href="/#how">How it works</Link>
            <Link href="/#pricing">Pricing</Link>
            <Link href="/about">About</Link>
            <Link href="/login" className="lp-btn lp-btn-primary">Login →</Link>
          </nav>
        </div>
      </header>

      <section className="lp-hero" style={{ paddingBottom: 56 }}>
        <div className="lp-wrap lp-hero-grid">
          {/* Left: info */}
          <div className="lp-hero-copy">
            <span className="lp-badge"><span className="dot">e</span> We&apos;re here to help</span>
            <h1 className="lp-h1">Talk to the <span className="accent">Eydia team.</span></h1>
            <p className="lp-lead">Questions about plans, setup or how the AI agents work? Send us a message and we&apos;ll get back to you.</p>

            <div className="lp-story" style={{ maxWidth: 420 }}>
              <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 14 }}>
                <span className="lp-chip-ic">✉️</span>
                <div><b style={{ display: "block", fontSize: 14 }}>Email</b><a href="mailto:heltogtechnologies@gmail.com">heltogtechnologies@gmail.com</a></div>
              </div>
              <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
                <span className="lp-chip-ic">💬</span>
                <div><b style={{ display: "block", fontSize: 14 }}>One workspace</b><span style={{ color: "var(--muted)", fontSize: 13.5 }}>WhatsApp · Google Reviews · Leads</span></div>
              </div>
            </div>
          </div>

          {/* Right: message form */}
          <div className="lp-demo">
            {!sent ? (
              <form onSubmit={(e) => { e.preventDefault(); if (ok) setSent(true); }}>
                <h2>Send us a message</h2>
                <div className="lp-field">
                  <label className="lp-label">Your name <span className="req">*</span></label>
                  <input className="lp-input" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="Jane Doe" required />
                </div>
                <div className="lp-field">
                  <label className="lp-label">Email <span className="req">*</span></label>
                  <input className="lp-input" type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} placeholder="you@company.com" required />
                </div>
                <div className="lp-field">
                  <label className="lp-label">Message <span className="req">*</span></label>
                  <textarea className="lp-input" rows={4} value={form.message} onChange={(e) => setForm((f) => ({ ...f, message: e.target.value }))} placeholder="How can we help?" style={{ resize: "vertical", fontFamily: "inherit" }} required />
                </div>
                <button type="submit" className="lp-btn lp-btn-primary lp-btn-lg lp-btn-block" disabled={!ok}>Send message</button>
                <div className="lp-demo-note">🔒 Your details are safe &amp; will never be shared</div>
              </form>
            ) : (
              <div className="lp-demo-success">
                <div className="tick">✓</div>
                <h3>Thanks, {form.name.trim()}!</h3>
                <p>We&apos;ve received your message and will reply to {form.email.trim()} soon.</p>
              </div>
            )}
          </div>
        </div>
      </section>

      <footer className="lp-foot">
        <div className="lp-foot-bottom" style={{ borderTop: "none", marginTop: 0, justifyContent: "center", gap: 18 }}>
          <span>© {new Date().getFullYear()} Eydia.ai — Automate · Engage · Grow</span>
          <span><Link href="/privacy">Privacy</Link> · <Link href="/terms">Terms</Link> · <Link href="/pricing-policy">Pricing</Link></span>
        </div>
      </footer>
    </div>
  );
}
