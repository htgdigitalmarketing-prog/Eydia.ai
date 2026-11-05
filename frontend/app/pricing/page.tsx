"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { api, PublicPlan } from "@/lib/api";
import { getToken } from "@/lib/auth";

// Public pricing page — plan cards with a monthly / quarterly / yearly selector
// (quarterly 10% off, yearly 20% off, matching checkout) and a feature list.

const CYCLES = {
  monthly: { label: "Monthly", months: 1, discount: 0, per: "mo" },
  quarterly: { label: "Quarterly", months: 3, discount: 0.1, per: "3 mo" },
  yearly: { label: "Yearly", months: 12, discount: 0.2, per: "yr" },
} as const;
type CycleKey = keyof typeof CYCLES;

const FEATURE_LABELS: Record<string, string> = {
  dash_whatsapp: "WhatsApp Marketing", dash_gbp: "Google Reviews / GBP", dash_leads: "Lead Management",
  reports: "Reports", advanced_reports: "Advanced reports", export: "Data export", social: "Social publishing",
  chatbot: "AI Chatbot", automations: "Automations", api_access: "API access", seo: "SEO toolkit", ads: "Ad reports", invoicing: "Invoicing",
};

export default function PricingPage() {
  const [plans, setPlans] = useState<PublicPlan[]>([]);
  const [cycle, setCycle] = useState<CycleKey>("yearly");
  const [loggedIn, setLoggedIn] = useState(false);
  const [loading, setLoading] = useState(true);
  const grad = "linear-gradient(120deg,#16b6f2 0%,#2160ef 40%,#6a22f0 78%,#ad17e6 100%)";

  useEffect(() => {
    setLoggedIn(!!getToken());
    api.publicPlans().then((r) => setPlans(r.plans)).catch(() => {}).finally(() => setLoading(false));
  }, []);

  const start = loggedIn ? "/billing" : "/register";
  const inr = (n: number) => "₹" + Math.round(n).toLocaleString("en-IN");
  const priceFor = (monthly: number) => {
    const c = CYCLES[cycle];
    return Math.round(monthly * c.months * (1 - c.discount));
  };

  return (
    <div style={{ background: "#f6f7fb", color: "#0f1b2d", fontFamily: "Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif", minHeight: "100vh" }}>
      <style>{`
        .pr-wrap{max-width:1040px;margin:0 auto;padding:0 20px;}
        .pr-card{background:#fff;border:1px solid #eceef6;border-radius:20px;}
        .pr-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;}
        .pr-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border-radius:12px;font-weight:700;text-decoration:none;cursor:pointer;}
        @media(max-width:860px){.pr-grid{grid-template-columns:1fr;}}
      `}</style>

      {/* Header */}
      <header style={{ position: "sticky", top: 0, zIndex: 20, background: "rgba(246,247,251,.85)", backdropFilter: "blur(10px)", borderBottom: "1px solid #eceef6" }}>
        <div className="pr-wrap" style={{ display: "flex", alignItems: "center", justifyContent: "space-between", height: 66 }}>
          <Link href="/"><img src="/eydia-logo.png" alt="Eydia.ai" style={{ height: 34 }} /></Link>
          <Link href={loggedIn ? "/dashboard" : "/login"} className="pr-btn" style={{ background: grad, color: "#fff", padding: "9px 18px", fontSize: 14 }}>{loggedIn ? "Open app" : "Login"}</Link>
        </div>
      </header>

      {/* Hero */}
      <section style={{ background: grad, color: "#fff", padding: "54px 0 70px", textAlign: "center" }}>
        <div className="pr-wrap">
          <div style={{ fontSize: 13, fontWeight: 800, letterSpacing: ".12em", textTransform: "uppercase", opacity: .85 }}>Plans &amp; pricing</div>
          <h1 style={{ fontSize: 40, fontWeight: 900, margin: "8px 0 10px", letterSpacing: "-.02em" }}>Your next stage of growth starts here</h1>
          <p style={{ opacity: .93, maxWidth: 560, margin: "0 auto 22px" }}>Start with Google visibility or put all three Eydia AI agents to work. Cancel anytime.</p>
          {/* Cycle toggle */}
          <div style={{ display: "inline-flex", background: "rgba(255,255,255,.16)", borderRadius: 999, padding: 4, gap: 4 }}>
            {(Object.keys(CYCLES) as CycleKey[]).map((c) => (
              <button key={c} onClick={() => setCycle(c)} style={{
                border: "none", cursor: "pointer", padding: "9px 18px", borderRadius: 999, fontSize: 13.5, fontWeight: 800,
                background: cycle === c ? "#fff" : "transparent", color: cycle === c ? "#2160ef" : "#fff",
              }}>
                {CYCLES[c].label}{CYCLES[c].discount > 0 && <span style={{ fontSize: 11, marginLeft: 5, color: cycle === c ? "#16a34a" : "#dff7e6" }}>−{CYCLES[c].discount * 100}%</span>}
              </button>
            ))}
          </div>
        </div>
      </section>

      {/* Plans */}
      <section style={{ padding: "40px 0 60px", marginTop: -40 }}>
        <div className="pr-wrap">
          {loading ? (
            <div className="pr-card" style={{ padding: 40, textAlign: "center", color: "#7b8498" }}>Loading plans…</div>
          ) : (
            <div className="pr-grid">
              {plans.map((p, i) => {
                const featured = i === 1 || plans.length === 1;
                const total = priceFor(p.price);
                const perMonth = CYCLES[cycle].months > 1 ? Math.round(total / CYCLES[cycle].months) : total;
                const saved = Math.round(p.price * CYCLES[cycle].months) - total;
                const on = Object.entries(p.features || {}).filter(([, v]) => v).map(([k]) => FEATURE_LABELS[k]).filter(Boolean);
                return (
                  <div key={p.id} className="pr-card" style={{ padding: 26, position: "relative", ...(featured ? { border: "2px solid #2160ef", boxShadow: "0 20px 50px rgba(33,96,239,.12)" } : {}) }}>
                    {featured && <span style={{ position: "absolute", top: -12, left: "50%", transform: "translateX(-50%)", background: grad, color: "#fff", fontSize: 11.5, fontWeight: 800, padding: "4px 12px", borderRadius: 999 }}>MOST POPULAR</span>}
                    <div style={{ fontSize: 13, fontWeight: 800, color: "#4c57d8", textTransform: "uppercase", letterSpacing: ".06em" }}>{p.name}</div>
                    {p.description && <div style={{ color: "#7b8498", fontSize: 13, marginTop: 4, minHeight: 34 }}>{p.description}</div>}
                    <div style={{ margin: "14px 0 2px" }}>
                      <span style={{ fontSize: 38, fontWeight: 900 }}>{total === 0 ? "Free" : inr(total)}</span>
                      {total > 0 && <span style={{ color: "#7b8498", fontSize: 14 }}> / {CYCLES[cycle].per}</span>}
                    </div>
                    {total > 0 && CYCLES[cycle].months > 1 && <div style={{ fontSize: 12.5, color: "#16a34a", fontWeight: 700 }}>{inr(perMonth)}/mo · you save {inr(saved)}</div>}
                    <div style={{ fontSize: 12, color: "#9aa3b5" }}>incl. GST</div>
                    <Link href={start} className="pr-btn" style={{ width: "100%", marginTop: 16, padding: "12px", ...(featured ? { background: grad, color: "#fff" } : { background: "#eef1ff", color: "#2160ef" }) }}>{total === 0 ? "Start free" : "Get started"} →</Link>
                    <div style={{ marginTop: 18, display: "grid", gap: 9 }}>
                      {on.map((f) => (
                        <div key={f} style={{ display: "flex", gap: 9, alignItems: "center", fontSize: 13.5 }}><span style={{ color: "#16a34a", fontWeight: 900 }}>✓</span>{f}</div>
                      ))}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
          <div style={{ textAlign: "center", marginTop: 26, color: "#7b8498", fontSize: 13 }}>
            Prices billed for the full term. Autopay renews automatically — cancel anytime from Billing.
          </div>
        </div>
      </section>

      <footer style={{ background: "#0f1b2d", color: "#aeb8cc", padding: "28px 0", textAlign: "center", fontSize: 13 }}>
        © {new Date().getFullYear()} Eydia.ai · <Link href="/" style={{ color: "#fff" }}>Home</Link> · <Link href="/terms" style={{ color: "#fff" }}>Terms</Link> · <Link href="/privacy" style={{ color: "#fff" }}>Privacy</Link>
      </footer>
    </div>
  );
}
