"use client";

import { useCallback, useEffect, useState } from "react";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";
import { getToken } from "@/lib/auth";
import {
  api, ApiError, PlanItem, SubscriptionItem2, InvoiceItem, BillingDetails, CreditsInfo,
} from "@/lib/api";
import { renderInvoiceHtml, printInvoice } from "@/components/invoice";

const LIMIT_LABELS: Record<string, string> = {
  max_agents: "Agents", max_contacts: "Contacts", max_campaigns: "Campaigns",
  max_chatbots: "Chatbots", max_templates: "Templates",
};
const FEATURE_LABELS: Record<string, string> = {
  reports: "Reports", advanced_reports: "Advanced reports", export: "Export",
  social: "Social publishing", chatbot: "Chatbot", automations: "Automations", api_access: "API access",
};
const showLimit = (v: number | undefined) => (v === undefined || v < 0 ? "Unlimited" : v.toLocaleString("en-IN"));

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

  // Billing-details step before payment
  const [billTarget, setBillTarget] = useState<PlanItem | null>(null);
  const [billForm, setBillForm] = useState<BillingDetails>({ name: "", email: "", phone: "", address: "", gstin: "" });
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
        api.billing.plans(token).catch(() => ({ plans: [] as PlanItem[], gst_rate: 18 })),
        api.billing.invoices(token).catch(() => ({ invoices: [] as InvoiceItem[], meta: { current_page: 1, last_page: 1, total: 0 } })),
      ]);
      setSubscription(ov.subscription);
      setPlans(pl.plans);
      setGstRate(pl.gst_rate ?? 18);
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
      const order = await api.billing.order(token, plan.id, billing);

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
            const v = await api.billing.verify(token, {
              plan_id: plan.id,
              billing,
              razorpay_order_id: resp.razorpay_order_id,
              razorpay_payment_id: resp.razorpay_payment_id,
              razorpay_signature: resp.razorpay_signature,
            });
            setBillTarget(null);
            setSubscription(v.subscription);
            flash(v.message);
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

  // GST preview for the billing modal.
  const quote = (() => {
    const price = billTarget?.price ?? 0;
    const gst = Math.round(price * gstRate) / 100;
    return { base: price, gst, total: price + gst };
  })();
  const inr = (n: number) => "₹" + n.toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const currentPlanId = subscription?.plan?.id;
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
          {/* Summary row */}
          <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: 12, marginBottom: 18 }}>
            <div className="stat">
              <div className="label">Current plan</div>
              <div className="value" style={{ fontSize: 20 }}>{subscription?.plan?.name ?? "No plan"}</div>
            </div>
            <div className="stat">
              <div className="label">Status</div>
              <div className="value" style={{ fontSize: 20, textTransform: "capitalize" }}>{subscription?.status ?? user.tenant?.status ?? "—"}</div>
            </div>
            <div className="stat">
              <div className="label">Renews / ends</div>
              <div className="value" style={{ fontSize: 20 }}>{fmtDate(subscription?.current_period_end ?? user.tenant?.trial_ends_at ?? null)}</div>
            </div>
          </div>

          <CreditsPanel />

          {/* Available plans */}
          <div className="panel">
            <h2 style={{ marginTop: 0 }}>Plans</h2>
            {plans.length === 0 ? (
              <p className="muted">No plans available. Ask an administrator to configure plans.</p>
            ) : (
              <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(240px, 1fr))", gap: 14 }}>
                {plans.map((p) => {
                  const isCurrent = p.id === currentPlanId;
                  return (
                    <div key={p.id} className="panel" style={{
                      border: isCurrent ? "2px solid var(--green)" : "1px solid var(--border)",
                      position: "relative",
                    }}>
                      {isCurrent && (
                        <span style={{ position: "absolute", top: 12, right: 12, background: "#e7f7ef", color: "#0a7d47", fontSize: 11, fontWeight: 700, padding: "2px 9px", borderRadius: 999 }}>
                          CURRENT
                        </span>
                      )}
                      <h3 style={{ margin: "0 0 4px" }}>{p.name}</h3>
                      <div style={{ fontSize: 24, fontWeight: 700 }}>
                        {p.price_display}
                        <span className="muted" style={{ fontSize: 13, fontWeight: 400 }}> / {p.billing_period === "yearly" ? "yr" : "mo"}</span>
                      </div>
                      {p.description && <p className="muted" style={{ fontSize: 13, margin: "8px 0" }}>{p.description}</p>}

                      {/* Numeric limits */}
                      <div style={{ display: "grid", gap: 4, fontSize: 13, color: "#54656f", margin: "12px 0 10px", paddingTop: 10, borderTop: "1px solid var(--border)" }}>
                        {Object.keys(LIMIT_LABELS).map((k) => (
                          <div key={k} style={{ display: "flex", justifyContent: "space-between" }}>
                            <span>{LIMIT_LABELS[k]}</span>
                            <strong>{showLimit(p.limits?.[k])}</strong>
                          </div>
                        ))}
                      </div>

                      {/* Enabled features */}
                      <div style={{ display: "flex", flexWrap: "wrap", gap: 4, marginBottom: 12 }}>
                        {Object.keys(FEATURE_LABELS).filter((k) => p.features?.[k]).map((k) => (
                          <span key={k} style={{ background: "#eafaf7", color: "#0a7d47", padding: "2px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600 }}>
                            ✓ {FEATURE_LABELS[k]}
                          </span>
                        ))}
                        {Object.keys(FEATURE_LABELS).filter((k) => p.features?.[k]).length === 0 && (
                          <span className="muted" style={{ fontSize: 12 }}>Core features included</span>
                        )}
                      </div>

                      <button
                        className="btn"
                        style={{ width: "100%", marginTop: 10, opacity: isCurrent ? 0.6 : 1 }}
                        disabled={isCurrent || switching === p.id}
                        onClick={() => openBilling(p)}
                      >
                        {isCurrent ? "Current plan" : switching === p.id ? "Processing…" : p.price > 0 ? `Buy — ${p.price_display}` : "Choose plan"}
                      </button>
                    </div>
                  );
                })}
              </div>
            )}
          </div>

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

      {/* Billing details step */}
      {billTarget && (
        <div className="msg-info-overlay" onClick={() => setBillTarget(null)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 560 }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
              <h2 style={{ margin: 0 }}>Billing details</h2>
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

              <div style={{ background: "#f4f8f7", borderRadius: 10, padding: "12px 14px", margin: "6px 0 14px", fontSize: 14 }}>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 4 }}><span className="muted">Plan ({billTarget.name})</span><span>{inr(quote.base)}</span></div>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 4 }}><span className="muted">GST ({gstRate}%)</span><span>{inr(quote.gst)}</span></div>
                <div style={{ display: "flex", justifyContent: "space-between", fontWeight: 800, borderTop: "1px solid var(--border)", paddingTop: 6 }}><span>Total payable</span><span>{inr(quote.total)}</span></div>
              </div>

              <div style={{ display: "flex", gap: 8 }}>
                <button className="btn" disabled={switching === billTarget.id} style={{ width: "auto", padding: "11px 22px" }}>
                  {switching === billTarget.id ? "Processing…" : quote.total > 0 ? `Proceed to pay ${inr(quote.total)}` : "Activate plan"}
                </button>
                <button type="button" className="btn-mini" onClick={() => setBillTarget(null)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
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
  topup: "Top-up", admin_topup: "Admin top-up", monthly_reset: "Monthly reset",
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
        <span className="muted" style={{ fontSize: 12.5 }}>
          {info.reset_at ? `Resets ${new Date(info.reset_at).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" })}` : ""}
        </span>
      </div>

      <div style={{ display: "flex", alignItems: "baseline", gap: 8, margin: "10px 0 6px" }}>
        <span style={{ fontSize: 34, fontWeight: 800 }}>{info.balance.toLocaleString("en-IN")}</span>
        <span className="muted">/ {info.allowance?.toLocaleString("en-IN")} this month</span>
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
