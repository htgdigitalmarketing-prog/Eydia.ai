"use client";

import { useCallback, useEffect, useState } from "react";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";
import { getToken } from "@/lib/auth";
import {
  api, ApiError, PlanItem, SubscriptionItem2, InvoiceItem, BillingDetails, CreditsInfo, PricingOffer,
} from "@/lib/api";
import { renderInvoiceHtml, printInvoice } from "@/components/invoice";

function Countdown({ endsAt }: { endsAt: string }) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, []);
  const ms = new Date(endsAt).getTime() - now;
  if (ms <= 0) return null;
  const s = Math.floor(ms / 1000);
  const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
  const pad = (n: number) => String(n).padStart(2, "0");
  return (
    <span style={{ fontVariantNumeric: "tabular-nums", fontWeight: 800 }}>
      {d > 0 ? `${d}d ` : ""}{pad(h)}:{pad(m)}:{pad(sec)}
    </span>
  );
}

const FEATURE_LABELS: Record<string, string> = {
  reports: "Reports", advanced_reports: "Advanced reports", export: "Export",
  social: "Social publishing", chatbot: "Chatbot", automations: "Automations", api_access: "API access",
};

export default function BillingPage() {
  const user = useUser();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [subscription, setSubscription] = useState<SubscriptionItem2 | null>(null);
  const [plans, setPlans] = useState<PlanItem[]>([]);
  const [invoices, setInvoices] = useState<InvoiceItem[]>([]);
  const [switching, setSwitching] = useState<string | null>(null);
  const [gstRate, setGstRate] = useState(18);
  const [offer, setOffer] = useState<PricingOffer | null>(null);

  // Billing-details step before payment
  const [billTarget, setBillTarget] = useState<PlanItem | null>(null);
  const [billForm, setBillForm] = useState<BillingDetails>({ name: "", email: "", phone: "", address: "", gstin: "" });
  const [cycle, setCycle] = useState<"monthly" | "quarterly" | "half_yearly" | "yearly">("monthly");
  // Autopay is always on (recurring subscription) — the checkbox is intentionally
  // hidden from clients; every purchase sets up a Razorpay auto-renew mandate.
  const autopay = true;
  const [coupon, setCoupon] = useState("");
  const [bq, setBq] = useState<{ list_price: number; cycle_price: number; cycle_discount_percent: number; coupon: { code: string; discount: number } | null; coupon_error: string | null; payable: number; gst_rate: number } | null>(null);
  const [viewInvoice, setViewInvoice] = useState<InvoiceItem | null>(null);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const [ov, pl, inv] = await Promise.all([
        api.billing.overview(token).catch(() => ({
          subscription: null as SubscriptionItem2 | null,
          tenant: { status: null, trial_ends_at: null },
        })),
        api.billing.plans(token).catch(() => ({ plans: [] as PlanItem[], gst_rate: 18, offer: null as PricingOffer | null })),
        api.billing.invoices(token).catch(() => ({ invoices: [] as InvoiceItem[], meta: { current_page: 1, last_page: 1, total: 0 } })),
      ]);
      setSubscription(ov.subscription);
      setPlans(pl.plans);
      setGstRate(pl.gst_rate ?? 18);
      setOffer(pl.offer ?? null);
      setInvoices(inv.invoices);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  function flash(msg: string) {
    setNotice(msg);
    setTimeout(() => setNotice(null), 3500);
  }

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

  // Step 1: open the billing-details form for the chosen plan.
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
    // The checkout renders inline where the plans were — bring it into view.
    if (typeof window !== "undefined") {
      setTimeout(() => { try { document.querySelector(".main-content")?.scrollTo({ top: 0, behavior: "smooth" }); window.scrollTo({ top: 0, behavior: "smooth" }); } catch {} }, 0);
    }
  }

  // Step 2: billing details submitted → create order → pay → verify → invoice.
  async function confirmPurchase(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    const plan = billTarget;
    if (!token || !plan) return;
    setSwitching(plan.id);
    setError(null);
    try {
      const billing: BillingDetails = { ...billForm, gstin: billForm.gstin || undefined };
      // Autopay → recurring Razorpay subscription; otherwise a one-time order.
      const order = autopay
        ? await api.billing.subscribeAuto(token, plan.id, billing, cycle, coupon || undefined)
        : await api.billing.order(token, plan.id, billing, cycle, coupon || undefined);

      // Free plan — assigned immediately, invoice generated, no checkout.
      if (order.free) {
        setBillTarget(null);
        setSubscription(order.subscription ?? null);
        flash(`You're now on the ${plan.name} plan.`);
        await load();
        setSwitching(null);
        return;
      }

      const ready = await loadRazorpay();
      if (!ready) {
        setError("Could not load the payment gateway. Check your connection and try again.");
        setSwitching(null);
        return;
      }

      const RazorpayCtor = (window as unknown as { Razorpay: new (opts: unknown) => { open: () => void } }).Razorpay;
      const useAutopay = autopay && !!order.subscription_id;
      const rzp = new RazorpayCtor({
        key: order.key_id,
        ...(useAutopay ? { subscription_id: order.subscription_id } : { amount: order.amount, currency: order.currency, order_id: order.order_id }),
        name: "Eydia.ai",
        description: `${plan.name} plan · ${CYCLES[cycle].label}${useAutopay ? " (autopay)" : ""}`,
        prefill: { name: billing.name, email: billing.email, contact: billing.phone },
        theme: { color: "#5b6bf5" },
        handler: async (resp: { razorpay_order_id?: string; razorpay_subscription_id?: string; razorpay_payment_id: string; razorpay_signature: string }) => {
          try {
            if (useAutopay) {
              const v = await api.billing.verifySubscription(token, {
                plan_id: plan.id, cycle, coupon: coupon || undefined, billing,
                razorpay_subscription_id: resp.razorpay_subscription_id ?? order.subscription_id ?? "",
                razorpay_payment_id: resp.razorpay_payment_id,
                razorpay_signature: resp.razorpay_signature,
              });
              setBillTarget(null); setSubscription(v.subscription);
              flash(`Autopay set up — you're on the ${plan.name} plan (${CYCLES[cycle].label}).`);
            } else {
              const v = await api.billing.verify(token, {
                plan_id: plan.id, cycle, coupon: coupon || undefined, billing,
                razorpay_order_id: resp.razorpay_order_id ?? "",
                razorpay_payment_id: resp.razorpay_payment_id,
                razorpay_signature: resp.razorpay_signature,
              });
              setBillTarget(null); setSubscription(v.subscription); flash(v.message);
            }
            await load();
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

  // Cycle pricing — matches the backend (months × monthly price × (1 − discount)).
  // Fallback labels only — the real discount/total comes from /billing/quote.
  const CYCLES = {
    monthly: { label: "Monthly", months: 1, discount: 0 },
    quarterly: { label: "Quarterly", months: 3, discount: 0.1 },
    half_yearly: { label: "6 Months", months: 6, discount: 0.15 },
    yearly: { label: "Yearly", months: 12, discount: 0.2 },
  } as const;

  // Authoritative price from the backend (reflects admin-set cycle discounts +
  // the coupon). Falls back to a client estimate until the quote returns.
  useEffect(() => {
    const token = getToken();
    if (!token || !billTarget) { setBq(null); return; }
    const t = setTimeout(() => {
      api.billing.quote(token, { plan_id: billTarget.id, cycle, coupon: coupon || undefined })
        .then(setBq).catch(() => setBq(null));
    }, coupon ? 350 : 0);
    return () => clearTimeout(t);
  }, [billTarget, cycle, coupon]);

  const quote = (() => {
    if (bq) {
      return { base: bq.cycle_price, list: bq.list_price, couponOff: bq.coupon?.discount ?? 0, total: bq.payable, saved: Math.round(bq.list_price - bq.cycle_price) };
    }
    const monthly = billTarget?.price ?? 0;
    const c = CYCLES[cycle];
    const price = Math.round(monthly * c.months * (1 - c.discount));
    return { base: price, list: Math.round(monthly * c.months), couponOff: 0, total: price, saved: Math.round(monthly * c.months) - price };
  })();
  const inr = (n: number) => "₹" + n.toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // Per-cycle info for the chosen plan, straight from the backend (`cycle_prices`).
  // Its discount_percent is the SAME value used to compute the price, so the
  // "Save X%" label can never disagree with the amount charged. Only the three
  // supported cycles are shown; a cycle the admin disabled simply won't appear.
  const SUPPORTED_CYCLES = ["monthly", "quarterly", "half_yearly", "yearly"] as const;
  const cyclesInfo = (
    billTarget?.cycle_prices?.length
      ? billTarget.cycle_prices
      : SUPPORTED_CYCLES.map((k) => ({
          key: k, label: CYCLES[k].label, months: CYCLES[k].months,
          list: 0, price: 0, per_month: 0, discount_percent: Math.round(CYCLES[k].discount * 100),
        }))
  ).filter((ci) => (SUPPORTED_CYCLES as readonly string[]).includes(ci.key));

  // A plan counts as "current" ONLY while the subscription is actually live.
  // A cancelled/expired subscription still references its old plan, so without
  // this guard the billing page wrongly shows that plan as "Current".
  const periodEndMs = subscription?.current_period_end ? new Date(subscription.current_period_end).getTime() : 0;
  const subIsLive = !!subscription
    && (subscription.status === "active" || subscription.status === "trialing")
    && (!periodEndMs || periodEndMs >= Date.now());
  const activePlan = subIsLive ? subscription?.plan : null;
  const currentPlanId = activePlan?.id;
  const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("en-IN") : "—");

  return (
    <>
      <PageHeader title="Billing" subtitle="Your plan & invoices" />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      {loading ? (
        <LoadingBlock label="Loading billing…" />
      ) : (
        <>
          {/* Launch offer banner + countdown (backend-managed) */}
          {offer?.active && (
            <div style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 12, flexWrap: "wrap", background: "linear-gradient(135deg, var(--accent, #5b6bf5), #8b5cf6)", color: "#fff", borderRadius: 14, padding: "12px 18px", marginBottom: 16, fontWeight: 700, fontSize: 14 }}>
              <span>⚡ {offer.label || "LAUNCH PRICE — LIMITED TIME"}</span>
              {offer.ends_at && (
                <span style={{ background: "#fff", color: "var(--accent, #5b6bf5)", borderRadius: 999, padding: "3px 12px", fontSize: 13 }}>
                  ENDS IN <Countdown endsAt={offer.ends_at} />
                </span>
              )}
            </div>
          )}

          {/* Summary row */}
          <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: 12, marginBottom: 18 }}>
            <div className="stat">
              <div className="label">Current plan</div>
              <div className="value" style={{ fontSize: 20 }}>{activePlan?.name ?? "No plan"}</div>
            </div>
            <div className="stat">
              <div className="label">Status</div>
              <div className="value" style={{ fontSize: 20, textTransform: "capitalize" }}>{subIsLive ? subscription?.status : (subscription ? "Inactive" : (user.tenant?.status ?? "—"))}</div>
            </div>
            <div className="stat">
              <div className="label">Renews / ends</div>
              <div className="value" style={{ fontSize: 20 }}>{fmtDate(subscription?.current_period_end ?? user.tenant?.trial_ends_at ?? null)}</div>
            </div>
          </div>

          {/* Available plans (or the inline checkout once a plan is chosen) */}
          <div className="panel">
            {billTarget ? (
              <div style={{ animation: "eydiaFade .15s ease" }}>
                {/* Gradient header with back + live total */}
                <div style={{ background: "linear-gradient(135deg, var(--accent, #5b6bf5), #8b5cf6)", color: "#fff", borderRadius: 16, padding: "16px 20px", marginBottom: 18 }}>
                  <button type="button" onClick={() => setBillTarget(null)}
                    style={{ background: "rgba(255,255,255,.2)", border: "none", color: "#fff", borderRadius: 9, padding: "5px 12px", cursor: "pointer", fontSize: 12.5, fontWeight: 600, marginBottom: 8 }}>← Back to plans</button>
                  <div style={{ fontSize: 11.5, opacity: .85, fontWeight: 700, letterSpacing: ".6px" }}>COMPLETE YOUR PURCHASE</div>
                  <div style={{ fontSize: 22, fontWeight: 800, marginTop: 2 }}>{billTarget.name} plan</div>
                  <div style={{ fontSize: 13.5, opacity: .92, marginTop: 2 }}>{CYCLES[cycle].label} · <strong>{inr(quote.total)}</strong> incl. GST</div>
                </div>

                <form onSubmit={confirmPurchase}>
                  {error && <div className="error">{error}</div>}

                  {/* Billing cycle */}
                  <div className="field">
                    <label style={{ fontWeight: 700 }}>Billing cycle</label>
                    <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(108px, 1fr))", gap: 9, marginTop: 4 }}>
                      {cyclesInfo.map((ci) => {
                        const on = cycle === ci.key;
                        return (
                          <button type="button" key={ci.key} onClick={() => setCycle(ci.key as "monthly" | "quarterly" | "half_yearly" | "yearly")}
                            style={{
                              position: "relative", cursor: "pointer", padding: "14px 8px 11px", borderRadius: 14, textAlign: "center",
                              border: `2px solid ${on ? "var(--accent)" : "var(--border)"}`,
                              background: on ? "color-mix(in srgb, var(--accent) 9%, var(--card, #fff))" : "var(--card, #fff)",
                              boxShadow: on ? "0 4px 14px color-mix(in srgb, var(--accent) 22%, transparent)" : "none", transition: "all .12s",
                            }}>
                            {ci.discount_percent > 0 && (
                              <span style={{ position: "absolute", top: -9, left: "50%", transform: "translateX(-50%)", background: "#0a7d47", color: "#fff", fontSize: 10, fontWeight: 800, padding: "2px 8px", borderRadius: 999, whiteSpace: "nowrap" }}>
                                SAVE {ci.discount_percent}%
                              </span>
                            )}
                            <div style={{ fontSize: 14, fontWeight: 800, color: on ? "var(--accent-dark, var(--accent))" : "var(--text)" }}>{ci.label}</div>
                            {ci.price > 0 && <div style={{ fontSize: 12, color: "var(--muted)", marginTop: 3 }}>{inr(ci.price)}</div>}
                          </button>
                        );
                      })}
                    </div>
                  </div>

                  {/* Coupon */}
                  <div className="field">
                    <label>Coupon code <span className="muted">(optional)</span></label>
                    <input value={coupon} onChange={(e) => setCoupon(e.target.value.toUpperCase())} placeholder="LAUNCH50" style={{ textTransform: "uppercase" }} />
                    {coupon && bq?.coupon && <div style={{ color: "#0a7d47", fontSize: 12.5, marginTop: 4 }}>✓ {bq.coupon.code} applied — you save {inr(bq.coupon.discount)}</div>}
                    {coupon && bq?.coupon_error && <div style={{ color: "#c53030", fontSize: 12.5, marginTop: 4 }}>{bq.coupon_error}</div>}
                  </div>

                  {/* Price summary */}
                  {(() => {
                    const gstR = bq?.gst_rate ?? gstRate;
                    const taxable = quote.total / (1 + gstR / 100);
                    const gstAmt = quote.total - taxable;
                    return (
                      <div style={{ background: "color-mix(in srgb, var(--accent) 5%, var(--bg, #f4f8f7))", border: "1px solid var(--border)", borderRadius: 14, padding: "14px 16px", margin: "4px 0 18px", fontSize: 14 }}>
                        <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 5 }}><span className="muted">{billTarget.name} · {CYCLES[cycle].label}</span><span>{bq && quote.list > quote.base ? <><span style={{ textDecoration: "line-through", color: "#aab2c3", marginRight: 6 }}>{inr(quote.list)}</span>{inr(quote.base)}</> : inr(quote.base)}</span></div>
                        {quote.saved > 0 && <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 5, color: "#0a7d47", fontWeight: 600 }}><span>Cycle discount</span><span>−{inr(quote.saved)}</span></div>}
                        {quote.couponOff > 0 && <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 5, color: "#0a7d47", fontWeight: 600 }}><span>Coupon {bq?.coupon?.code}</span><span>−{inr(quote.couponOff)}</span></div>}
                        <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 2, color: "#54656f", fontSize: 13 }}><span>Taxable value</span><span>{inr(taxable)}</span></div>
                        <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 6, color: "#54656f", fontSize: 13 }}><span>GST @ {gstR}% <span className="muted">(included)</span></span><span>{inr(gstAmt)}</span></div>
                        <div style={{ display: "flex", justifyContent: "space-between", fontWeight: 800, fontSize: 16, borderTop: "1px solid var(--border)", paddingTop: 8 }}><span>Total payable <span className="muted" style={{ fontWeight: 400, fontSize: 12 }}>(incl. GST)</span></span><span>{inr(quote.total)}</span></div>
                        <div className="muted" style={{ fontSize: 11.5, marginTop: 7 }}>🔁 Auto-renews every {CYCLES[cycle].label.toLowerCase()} via Razorpay (UPI / card mandate). Cancel anytime.</div>
                      </div>
                    );
                  })()}

                  {/* Invoice details */}
                  <div style={{ fontSize: 12.5, fontWeight: 700, color: "var(--muted)", textTransform: "uppercase", letterSpacing: ".5px", margin: "2px 0 8px" }}>Invoice details</div>
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
                      <input value={billForm.phone} onChange={(e) => setBillForm((f) => ({ ...f, phone: e.target.value }))} required placeholder="+91…" />
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

                  <button className="btn" disabled={switching === billTarget.id} style={{ width: "100%", padding: "14px", fontSize: 15, fontWeight: 700, marginTop: 6 }}>
                    {switching === billTarget.id ? "Processing…" : quote.total > 0 ? `🔒 Proceed to pay ${inr(quote.total)}` : "Activate plan"}
                  </button>
                  <div className="muted" style={{ textAlign: "center", fontSize: 11, marginTop: 8 }}>Secured by Razorpay · UPI / Card / Netbanking · GST invoice emailed</div>
                </form>
              </div>
            ) : (
            <>
            <h2 style={{ marginTop: 0 }}>Plans</h2>
            {plans.length === 0 ? (
              <p className="muted">No plans available. Ask an administrator to configure plans.</p>
            ) : (
              <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(250px, 1fr))", gap: 18, marginBottom: 4 }}>
                {plans.map((p, idx) => {
                  const isCurrent = p.id === currentPlanId;
                  // Highlight the middle tier as "popular" (only when nothing is current).
                  const isPopular = !currentPlanId && plans.length >= 3 && idx === Math.floor(plans.length / 2);
                  const feats = Object.keys(FEATURE_LABELS).filter((k) => p.features?.[k]);
                  return (
                    <div key={p.id} className={`plan-card ${isCurrent ? "plan-card-current" : isPopular ? "plan-card-popular" : ""}`}>
                      <div className="plan-card-top" />
                      {isCurrent && <div className="plan-ribbon" style={{ background: "var(--green, #0a7d47)" }}>CURRENT</div>}
                      {isPopular && <div className="plan-ribbon">POPULAR</div>}

                      <h3 style={{ margin: "4px 0 2px", fontSize: 17 }}>{p.name}</h3>
                      <div style={{ display: "flex", alignItems: "baseline", gap: 4, margin: "6px 0 2px" }}>
                        <span style={{ fontSize: 30, fontWeight: 800, letterSpacing: "-.5px" }}>{p.price_display}</span>
                        <span className="muted" style={{ fontSize: 13, fontWeight: 500 }}>/ {p.billing_period === "yearly" ? "yr" : "mo"}</span>
                      </div>
                      <div className="muted" style={{ fontSize: 11.5 }}>incl. GST</div>
                      {p.description && <p className="muted" style={{ fontSize: 13, margin: "10px 0 0" }}>{p.description}</p>}

                      {/* Features (numeric limits hidden) */}
                      <div style={{ display: "grid", gap: 2, margin: "16px 0 18px", paddingTop: 14, borderTop: "1px solid var(--border)" }}>
                        {feats.length > 0 ? feats.map((k) => (
                          <div key={k} className="plan-feat"><span className="tick">✓</span>{FEATURE_LABELS[k]}</div>
                        )) : <span className="muted" style={{ fontSize: 12.5 }}>Core features included</span>}
                      </div>

                      <button
                        className="btn"
                        style={{ width: "100%", marginTop: "auto", padding: "12px", fontSize: 14.5, fontWeight: 700, opacity: isCurrent ? 0.65 : 1, cursor: isCurrent ? "default" : "pointer" }}
                        disabled={isCurrent || switching === p.id}
                        onClick={() => openBilling(p)}
                      >
                        {isCurrent ? "✓ Current plan" : switching === p.id ? "Processing…" : p.price > 0 ? `Buy — ${p.price_display}` : "Choose plan"}
                      </button>
                    </div>
                  );
                })}
              </div>
            )}
            </>
            )}
          </div>

          {/* AI Credits (below Plans) */}
          <CreditsPanel />

          {/* Invoices */}
          <div className="panel">
            <h2 style={{ marginTop: 0 }}>Invoices</h2>
            {invoices.length === 0 ? (
              <p className="muted">No invoices yet.</p>
            ) : (
              <div style={{ overflowX: "auto" }}>
                <table style={{ width: "100%", borderCollapse: "collapse" }}>
                  <thead>
                    <tr style={{ textAlign: "left", fontSize: 12, color: "#667781", borderBottom: "1px solid #eef1f2" }}>
                      <th style={{ padding: "8px 6px" }}>Number</th>
                      <th style={{ padding: "8px 6px" }}>Status</th>
                      <th style={{ padding: "8px 6px" }}>Issued</th>
                      <th style={{ padding: "8px 6px", textAlign: "right" }}>Total</th>
                      <th style={{ padding: "8px 6px", textAlign: "right" }}></th>
                    </tr>
                  </thead>
                  <tbody>
                    {invoices.map((inv) => (
                      <tr key={inv.id} style={{ borderBottom: "1px solid #f6f7f8" }}>
                        <td style={{ padding: "8px 6px", fontSize: 13, fontFamily: "monospace" }}>{inv.number ?? inv.id.slice(0, 8)}</td>
                        <td style={{ padding: "8px 6px", fontSize: 13, textTransform: "capitalize" }}>{inv.status}</td>
                        <td style={{ padding: "8px 6px", fontSize: 13 }}>{fmtDate(inv.issued_at)}</td>
                        <td style={{ padding: "8px 6px", textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{inv.total}</td>
                        <td style={{ padding: "8px 6px", textAlign: "right", whiteSpace: "nowrap" }}>
                          <button className="btn-mini" onClick={() => setViewInvoice(inv)}>View</button>
                          <button className="btn-mini" style={{ marginLeft: 6 }} onClick={() => printInvoice(inv)}>⬇ PDF</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          <p className="muted" style={{ fontSize: 12, marginTop: 8 }}>
            Payments are processed securely by Razorpay (card / UPI / netbanking). Your plan activates automatically once payment succeeds.
          </p>
        </>
      )}

      {/* Invoice viewer */}
      {viewInvoice && (
        <div className="msg-info-overlay" onClick={() => setViewInvoice(null)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 780, width: "94vw", padding: 12 }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "4px 8px 10px" }}>
              <h2 style={{ margin: 0 }}>Invoice {viewInvoice.number}</h2>
              <div style={{ display: "flex", gap: 8 }}>
                <button className="btn-mini" onClick={() => printInvoice(viewInvoice)}>⬇ Download PDF</button>
                <button className="btn-mini" onClick={() => setViewInvoice(null)}>Close</button>
              </div>
            </div>
            <iframe title="invoice" srcDoc={renderInvoiceHtml(viewInvoice)} style={{ width: "100%", height: "70vh", border: "1px solid var(--border)", borderRadius: 8, background: "#fff" }} />
          </div>
        </div>
      )}
    </>
  );
}

const ACTION_LABELS: Record<string, string> = {
  ai_chat: "AI assistant reply", ai_review_reply: "AI review reply", ai_keyword_gen: "Keyword generation",
  ai_rank_check: "Rank check", ai_competitor: "Competitor analysis", ai_audit: "Google audit",
  ai_optimize: "One-click optimize", ai_media_generate: "AI image generation",
  topup: "Top-up", admin_topup: "Admin top-up", monthly_reset: "Plan credits",
  plan_credits: "Plan credits", purchase: "Credit pack purchase",
};

function CreditsPanel() {
  const [info, setInfo] = useState<CreditsInfo | null>(null);
  useEffect(() => {
    const t = getToken();
    if (!t) return;
    api.credits.get(t).then(setInfo).catch(() => {});
  }, []);
  if (!info || info.balance === null) return null;

  const pct = info.allowance ? Math.min(100, Math.round((info.balance / info.allowance) * 100)) : 0;
  return (
    <div className="panel" style={{ marginBottom: 18 }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", flexWrap: "wrap", gap: 10 }}>
        <h2 style={{ margin: 0 }}>⚡ AI Credits</h2>
        <a href="/buy-credits" className="btn-mini">⚡ Buy more</a>
      </div>

      <div style={{ display: "flex", alignItems: "baseline", gap: 8, margin: "10px 0 6px" }}>
        <span style={{ fontSize: 34, fontWeight: 800 }}>{info.balance.toLocaleString("en-IN")}</span>
        <span className="muted">credits left</span>
      </div>
      <div style={{ height: 8, background: "var(--bg)", borderRadius: 999, marginBottom: 14 }}>
        <div style={{ width: `${pct}%`, height: "100%", background: "var(--brand-grad)", borderRadius: 999 }} />
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "minmax(0,1fr) minmax(0,1.3fr)", gap: 18, alignItems: "start" }}>
        <div>
          <div className="muted" style={{ fontSize: 12, fontWeight: 700, textTransform: "uppercase", letterSpacing: ".5px", marginBottom: 8 }}>Cost per AI action</div>
          <div style={{ display: "grid", gap: 4 }}>
            {Object.entries(info.costs).map(([k, v]) => (
              <div key={k} style={{ display: "flex", justifyContent: "space-between", fontSize: 13, padding: "3px 0", borderBottom: "1px solid var(--border)" }}>
                <span>{ACTION_LABELS[k] ?? k}</span><strong>{v} ⚡</strong>
              </div>
            ))}
          </div>
        </div>
        <div>
          <div className="muted" style={{ fontSize: 12, fontWeight: 700, textTransform: "uppercase", letterSpacing: ".5px", marginBottom: 8 }}>Recent usage</div>
          {info.ledger.length === 0 ? <p className="muted" style={{ fontSize: 13 }}>No activity yet.</p> : (
            <div style={{ display: "grid", gap: 2, maxHeight: 220, overflowY: "auto" }}>
              {info.ledger.map((l) => (
                <div key={l.id} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", fontSize: 13, padding: "5px 0", borderBottom: "1px solid var(--border)" }}>
                  <span>{ACTION_LABELS[l.action] ?? l.action}{l.description ? <span className="muted"> · {l.description}</span> : ""}</span>
                  <strong style={{ color: l.delta < 0 ? "var(--danger)" : "#0a7d47" }}>{l.delta > 0 ? "+" : ""}{l.delta}</strong>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
