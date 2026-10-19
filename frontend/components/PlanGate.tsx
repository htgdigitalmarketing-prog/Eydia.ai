"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { api, ApiError, PlanItem, BillingDetails, User } from "@/lib/api";
import { getToken } from "@/lib/auth";

/**
 * Returns false only when the plan explicitly sets this limit to 0 (feature not
 * included). null (unlimited), a positive number, or no gating → true.
 */
export function planAllows(limits: Record<string, number | null> | null | undefined, key: string): boolean {
  if (!limits) return true; // no gating (super admin / no plan map)
  return limits[key] !== 0;
}

/**
 * Whether the signed-in user's plan enables a feature flag (e.g. dash_gbp).
 * Super admin or no-plan map (plan_features null) → everything is allowed.
 */
export function hasFeature(
  user: { is_super_admin?: boolean; plan_features?: string[] | null },
  key: string,
): boolean {
  if (user.is_super_admin) return true;
  if (user.plan_features == null) return true; // no plan gating
  return user.plan_features.includes(key);
}

/**
 * Hard "Access Denied" screen shown when a user opens a dashboard their plan
 * does not include. The backend enforces the same gate (feature middleware),
 * so this cannot be bypassed via URL or API.
 */
export function AccessDenied({ dashboard }: { dashboard: string }) {
  return (
    <div className="panel" style={{ textAlign: "center", padding: "56px 24px", maxWidth: 560, margin: "40px auto" }}>
      <div style={{ fontSize: 54 }}>⛔</div>
      <h2 style={{ margin: "14px 0 6px", color: "var(--danger)" }}>Access Denied</h2>
      <p className="muted" style={{ marginBottom: 4 }}>
        The <strong>{dashboard}</strong> dashboard isn&apos;t included in your current plan.
      </p>
      <p className="muted" style={{ marginBottom: 22 }}>Upgrade your plan to unlock it.</p>
      <Link href="/billing" className="btn" style={{ display: "inline-block", width: "auto", padding: "12px 28px", textDecoration: "none" }}>
        View plans &amp; upgrade
      </Link>
    </div>
  );
}

/**
 * "Upgrade your plan" screen shown in place of a restricted module. The backend
 * enforces the same limit on every write, so this cannot be bypassed via URL/API.
 */
export function UpgradePrompt({ title, feature }: { title: string; feature: string }) {
  const user = useUser();
  const company = user.tenant?.company_name ?? user.tenant?.name ?? null;
  return (
    <div>
      <PageHeader title={title} />
      <div className="panel" style={{ textAlign: "center", padding: "48px 24px", maxWidth: 560, margin: "0 auto" }}>
        <div style={{ fontSize: 46 }}>🔒</div>
        <h2 style={{ margin: "14px 0 6px" }}>Upgrade your plan</h2>
        <p className="muted" style={{ marginBottom: 4 }}>
          {feature} isn&apos;t included in your current plan{company ? ` (${company})` : ""}.
        </p>
        <p className="muted" style={{ marginBottom: 22 }}>Upgrade to unlock {feature.toLowerCase()} and more.</p>
        <Link href="/billing" className="btn" style={{ display: "inline-block", width: "auto", padding: "12px 28px", textDecoration: "none" }}>
          View plans &amp; upgrade
        </Link>
      </div>
    </div>
  );
}

const LIMIT_LABELS: Record<string, string> = {
  max_agents: "Agents", max_contacts: "Contacts", max_campaigns: "Campaigns",
  max_chatbots: "Chatbots", max_templates: "Templates",
};
const FEATURE_LABELS: Record<string, string> = {
  reports: "Reports", advanced_reports: "Advanced reports", export: "Export",
  social: "Social publishing", chatbot: "Chatbot", automations: "Automations", api_access: "API access",
  seo: "SEO toolkit", ads: "Ads reporting", invoicing: "Invoicing",
};
const showLimit = (v: number | undefined) => (v === undefined || v < 0 ? "Unlimited" : v.toLocaleString("en-IN"));
const inr = (n: number) => "₹" + n.toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function loadRazorpay(): Promise<boolean> {
  return new Promise((resolve) => {
    const w = window as unknown as { Razorpay?: unknown };
    if (w.Razorpay) return resolve(true);
    const s = document.createElement("script");
    s.src = "https://checkout.razorpay.com/v1/checkout.js";
    s.onload = () => resolve(true);
    s.onerror = () => resolve(false);
    document.body.appendChild(s);
  });
}

const ALLOWED_WHEN_EXPIRED = ["/billing", "/profile", "/support"];

export function isAllowedWhenExpired(pathname: string): boolean {
  return ALLOWED_WHEN_EXPIRED.some((p) => pathname === p || pathname.startsWith(p + "/"));
}

export function ExpiredBanner({ planName }: { planName: string | null }) {
  return (
    <div style={{ background: "#fef3cd", color: "#856404", padding: "10px 18px", fontSize: 14, display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, borderBottom: "1px solid #ffc107" }}>
      <span>
        <strong>Your {planName ?? ""} plan has expired.</strong> Renew to continue using WhatsApp, inbox, campaigns and all other features.
      </span>
      <Link href="/billing" className="btn" style={{ fontSize: 13, padding: "6px 18px", whiteSpace: "nowrap", textDecoration: "none" }}>
        Renew Plan
      </Link>
    </div>
  );
}

export function ExpiredBlock() {
  return (
    <div className="panel" style={{ textAlign: "center", padding: "48px 24px", maxWidth: 560, margin: "40px auto" }}>
      <div style={{ fontSize: 46 }}>⏰</div>
      <h2 style={{ margin: "14px 0 6px" }}>Plan Expired</h2>
      <p className="muted" style={{ marginBottom: 4 }}>
        Your subscription has ended. Renew your plan to access this feature.
      </p>
      <p className="muted" style={{ marginBottom: 22 }}>Your WhatsApp numbers are still connected and will resume working once you renew.</p>
      <Link href="/billing" className="btn" style={{ display: "inline-block", width: "auto", padding: "12px 28px", textDecoration: "none" }}>
        View Plans &amp; Renew
      </Link>
    </div>
  );
}

export function NoPlanGate({ user, onActivated }: { user: User; onActivated: () => void }) {
  const [plans, setPlans] = useState<PlanItem[]>([]);
  const [gstRate, setGstRate] = useState(18);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [switching, setSwitching] = useState<string | null>(null);

  const [billTarget, setBillTarget] = useState<PlanItem | null>(null);
  const [billForm, setBillForm] = useState<BillingDetails>({ name: "", email: "", phone: "", address: "", gstin: "" });

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const res = await api.billing.plans(token);
      setPlans(res.plans);
      setGstRate(res.gst_rate ?? 18);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  function openBilling(plan: PlanItem) {
    setBillTarget(plan);
    setError(null);
    setBillForm({
      name: user.tenant?.company_name ?? user.tenant?.name ?? user.name,
      email: user.email,
      phone: "",
      address: "",
      gstin: "",
    });
  }

  async function confirmPurchase(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    const plan = billTarget;
    if (!token || !plan) return;
    setSwitching(plan.id);
    setError(null);
    try {
      const billing: BillingDetails = { ...billForm, gstin: billForm.gstin || undefined };
      const order = await api.billing.order(token, plan.id, billing);

      if (order.free) {
        setBillTarget(null);
        setSwitching(null);
        onActivated();
        return;
      }

      const ready = await loadRazorpay();
      if (!ready) {
        setError("Could not load the payment gateway. Check your connection and try again.");
        setSwitching(null);
        return;
      }

      const RazorpayCtor = (window as unknown as { Razorpay: new (opts: unknown) => { open: () => void } }).Razorpay;
      const rzp = new RazorpayCtor({
        key: order.key_id,
        amount: order.amount,
        currency: order.currency,
        order_id: order.order_id,
        name: "Eydia.ai",
        description: `${plan.name} plan`,
        prefill: { name: billing.name, email: billing.email, contact: billing.phone },
        theme: { color: "#5b6bf5" },
        handler: async (resp: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }) => {
          try {
            await api.billing.verify(token, {
              plan_id: plan.id,
              billing,
              razorpay_order_id: resp.razorpay_order_id,
              razorpay_payment_id: resp.razorpay_payment_id,
              razorpay_signature: resp.razorpay_signature,
            });
            setBillTarget(null);
            onActivated();
          } catch (err) {
            setError((err as ApiError).message);
          } finally {
            setSwitching(null);
          }
        },
        modal: { ondismiss: () => setSwitching(null) },
      });
      rzp.open();
    } catch (err) {
      const e2 = err as ApiError;
      setError(e2.errors ? Object.values(e2.errors).flat().join(". ") : e2.message);
      setSwitching(null);
    }
  }

  const quote = (() => {
    const price = billTarget?.price ?? 0;
    const gst = Math.round(price * gstRate) / 100;
    return { base: price, gst, total: price + gst };
  })();

  return (
    <div style={{ minHeight: "100vh", background: "radial-gradient(1000px 520px at 100% 0%, rgba(34,199,245,.16), transparent 60%), radial-gradient(900px 500px at 0% 100%, rgba(139,92,246,.22), transparent 60%), linear-gradient(135deg, #0a0e27 0%, #141c45 50%, #2b1e63 100%)", display: "flex", alignItems: "center", justifyContent: "center", padding: 24 }}>
      <div style={{ width: "100%", maxWidth: 900, textAlign: "center" }}>
        <div style={{ marginBottom: 32 }}>
          <img src="/eydia-logo-white.png" alt="Eydia.ai Automate · Engage · Grow" style={{ height: 96, marginBottom: 16 }} onError={(e) => { (e.target as HTMLImageElement).style.display = "none"; }} />
          <h1 style={{ color: "#fff", fontSize: 28, margin: "0 0 8px", fontWeight: 800 }}>Choose a Plan to Get Started</h1>
          <p style={{ color: "rgba(255,255,255,.7)", fontSize: 15, margin: 0 }}>
            Select a plan to unlock WhatsApp inbox, campaigns, chatbots and more.
          </p>
        </div>

        {error && <div style={{ background: "#fdecec", color: "#c53030", padding: "10px 16px", borderRadius: 10, marginBottom: 16, fontSize: 14 }}>{error}</div>}

        {loading ? (
          <div style={{ color: "rgba(255,255,255,.7)", padding: 40 }}>Loading plans...</div>
        ) : plans.length === 0 ? (
          <div style={{ background: "rgba(255,255,255,.1)", borderRadius: 16, padding: 40, color: "#fff" }}>
            <p style={{ fontSize: 16 }}>No plans available right now.</p>
            <p style={{ color: "rgba(255,255,255,.6)", fontSize: 14 }}>Please contact your administrator to set up billing plans.</p>
          </div>
        ) : (
          <div style={{ display: "grid", gridTemplateColumns: `repeat(auto-fill, minmax(260px, 1fr))`, gap: 16 }}>
            {plans.map((p) => (
              <div key={p.id} style={{ background: "#fff", borderRadius: 16, padding: "28px 22px 22px", textAlign: "left", boxShadow: "0 4px 24px rgba(0,0,0,.15)" }}>
                <h3 style={{ margin: "0 0 4px", fontSize: 18 }}>{p.name}</h3>
                <div style={{ fontSize: 28, fontWeight: 800, color: "#5b6bf5" }}>
                  {p.price_display}
                  <span style={{ fontSize: 13, fontWeight: 400, color: "#667781" }}> / {p.billing_period === "yearly" ? "yr" : "mo"}</span>
                </div>
                {p.description && <p style={{ color: "#667781", fontSize: 13, margin: "8px 0" }}>{p.description}</p>}

                <div style={{ display: "grid", gap: 4, fontSize: 13, color: "#54656f", margin: "14px 0 10px", paddingTop: 10, borderTop: "1px solid #eef1f2" }}>
                  {Object.keys(LIMIT_LABELS).map((k) => (
                    <div key={k} style={{ display: "flex", justifyContent: "space-between" }}>
                      <span>{LIMIT_LABELS[k]}</span>
                      <strong>{showLimit(p.limits?.[k])}</strong>
                    </div>
                  ))}
                </div>

                <div style={{ display: "flex", flexWrap: "wrap", gap: 4, marginBottom: 14 }}>
                  {Object.keys(FEATURE_LABELS).filter((k) => p.features?.[k]).map((k) => (
                    <span key={k} style={{ background: "#eafaf7", color: "#0a7d47", padding: "2px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600 }}>
                      {FEATURE_LABELS[k]}
                    </span>
                  ))}
                </div>

                <button
                  className="btn"
                  style={{ width: "100%" }}
                  disabled={switching === p.id}
                  onClick={() => openBilling(p)}
                >
                  {switching === p.id ? "Processing..." : p.price > 0 ? `Buy — ${p.price_display}` : "Choose plan"}
                </button>
              </div>
            ))}
          </div>
        )}
      </div>

      {billTarget && (
        <div className="msg-info-overlay" onClick={() => setBillTarget(null)} style={{ zIndex: 200 }}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 560 }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
              <h2 style={{ margin: 0 }}>Billing Details</h2>
              <button className="btn-mini" onClick={() => setBillTarget(null)}>Close</button>
            </div>
            <p className="muted" style={{ fontSize: 13, marginTop: 4 }}>For your invoice ({billTarget.name} plan). GST is applied as per Indian tax rules.</p>
            {error && <div className="error">{error}</div>}
            <form onSubmit={confirmPurchase}>
              <div className="field">
                <label>Full name / Company name</label>
                <input value={billForm.name} onChange={(e) => setBillForm((f) => ({ ...f, name: e.target.value }))} required placeholder="Acme Pvt Ltd" />
              </div>
              <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
                <div className="field" style={{ flex: 1, minWidth: 200 }}>
                  <label>Email</label>
                  <input type="email" value={billForm.email} onChange={(e) => setBillForm((f) => ({ ...f, email: e.target.value }))} required placeholder="billing@acme.com" />
                </div>
                <div className="field" style={{ flex: 1, minWidth: 160 }}>
                  <label>Phone</label>
                  <input value={billForm.phone} onChange={(e) => setBillForm((f) => ({ ...f, phone: e.target.value }))} required placeholder="+91..." />
                </div>
              </div>
              <div className="field">
                <label>Billing address</label>
                <textarea value={billForm.address} onChange={(e) => setBillForm((f) => ({ ...f, address: e.target.value }))} required rows={2} placeholder="Street, City, State, PIN" style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, fontFamily: "inherit", resize: "vertical" }} />
              </div>
              <div className="field">
                <label>GSTIN <span className="muted">(optional)</span></label>
                <input value={billForm.gstin ?? ""} onChange={(e) => setBillForm((f) => ({ ...f, gstin: e.target.value }))} placeholder="22AAAAA0000A1Z5" />
              </div>

              <div style={{ background: "#f4f8f7", borderRadius: 10, padding: "12px 14px", margin: "6px 0 14px", fontSize: 14 }}>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 4 }}><span className="muted">Plan ({billTarget.name})</span><span>{inr(quote.base)}</span></div>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 4 }}><span className="muted">GST ({gstRate}%)</span><span>{inr(quote.gst)}</span></div>
                <div style={{ display: "flex", justifyContent: "space-between", fontWeight: 800, borderTop: "1px solid var(--border)", paddingTop: 6 }}><span>Total payable</span><span>{inr(quote.total)}</span></div>
              </div>

              <div style={{ display: "flex", gap: 8 }}>
                <button className="btn" disabled={switching === billTarget.id} style={{ width: "auto", padding: "11px 22px" }}>
                  {switching === billTarget.id ? "Processing..." : quote.total > 0 ? `Proceed to pay ${inr(quote.total)}` : "Activate plan"}
                </button>
                <button type="button" className="btn-mini" onClick={() => setBillTarget(null)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
