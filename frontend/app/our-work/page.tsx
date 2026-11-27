"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getToken } from "@/lib/auth";
import { api, PublicWorkItem } from "@/lib/api";
import { LandingHeader, LandingFooter } from "@/components/landing-kit";

// Dedicated Our Work / portfolio page — the full list (home shows only the
// first few). Category-filterable, ad-ready.

export default function OurWorkPage() {
  const [loggedIn, setLoggedIn] = useState(false);
  const [work, setWork] = useState<PublicWorkItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [cat, setCat] = useState<string>("All");
  useEffect(() => { setLoggedIn(!!getToken()); }, []);
  useEffect(() => { api.publicWork().then((r) => setWork(r.items)).catch(() => setWork([])).finally(() => setLoading(false)); }, []);
  const cta = loggedIn ? "/dashboard" : "/register";

  const cats = ["All", ...Array.from(new Set(work.map((w) => w.category)))];
  const shown = cat === "All" ? work : work.filter((w) => w.category === cat);

  return (
    <div className="lph">
      <LandingHeader active="/our-work" />

      {/* ===== Hero ===== */}
      <section className="lph-hero">
        <div className="lph-hero-in" style={{ gridTemplateColumns: "1fr", textAlign: "center", maxWidth: 820, margin: "0 auto" }}>
          <div className="lph-hero-copy" style={{ margin: "0 auto" }}>
            <span className="lph-pill">Our Work</span>
            <h1 className="lph-h1">Real results for<br /><span className="lph-grad-text">real businesses</span></h1>
            <p className="lph-lead">
              Websites, mobile apps, Google &amp; Meta ad campaigns, and Google Business Profiles —
              designed, built and managed by the Eydia team.
            </p>
            <div className="lph-hero-cta" style={{ justifyContent: "center" }}>
              <Link href={cta} className="lph-btn lph-btn-grad lph-btn-lg">Work With Us</Link>
              <Link href="/features" className="lph-btn lph-btn-outline lph-btn-lg">See Features</Link>
            </div>
          </div>
        </div>
      </section>

      {/* ===== Portfolio ===== */}
      <section className="lph-sec" style={{ background: "#f7f9ff" }}>
        <div className="lph-wrap">
          <div className="lph-eyebrow">PORTFOLIO</div>
          <h2 className="lph-h2">What we&apos;ve built</h2>
          <p className="lph-sub">Filter by category to see the kind of work we deliver.</p>

          {loading ? (
            <p style={{ textAlign: "center", color: "#778", marginTop: 32 }}>Loading…</p>
          ) : work.length === 0 ? (
            <p style={{ textAlign: "center", color: "#778", marginTop: 32 }}>Our portfolio is coming soon.</p>
          ) : (
            <>
              <div style={{ display: "flex", gap: 8, flexWrap: "wrap", justifyContent: "center", margin: "22px 0 28px" }}>
                {cats.map((c) => (
                  <button key={c} onClick={() => setCat(c)}
                    style={{ cursor: "pointer", padding: "8px 16px", borderRadius: 999, fontSize: 13.5, fontWeight: 700,
                      border: `1.5px solid ${cat === c ? "transparent" : "#dde3f0"}`,
                      background: cat === c ? "var(--brand-grad, #5b6bf5)" : "#fff",
                      color: cat === c ? "#fff" : "#334" }}>{c}</button>
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
            </>
          )}
        </div>
      </section>

      {/* ===== CTA band ===== */}
      <section className="lph-cta-wrap">
        <div className="lph-wrap">
          <div className="lph-cta">
            <h2>Want results like these?</h2>
            <p>Let Eydia manage your Google, WhatsApp and leads — and grow your local business.</p>
            <Link href={cta} className="lph-btn lph-btn-white lph-btn-lg">Get Started Now</Link>
          </div>
        </div>
      </section>

      <LandingFooter />
    </div>
  );
}
