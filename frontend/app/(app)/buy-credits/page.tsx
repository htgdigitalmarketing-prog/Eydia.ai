"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, CreditPackClient } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

const inr = (n: number) => "₹" + n.toLocaleString("en-IN", { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
const ACCENTS = ["#16a34a", "#2160ef", "#7b2ff7", "#f59e0b", "#db2777"];

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

export default function BuyCreditsPage() {
  const user = useUser();
  const [packs, setPacks] = useState<CreditPackClient[]>([]);
  const [balance, setBalance] = useState(0);
  const [loading, setLoading] = useState(true);
  const [buying, setBuying] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const t = getToken(); if (!t) return;
    setLoading(true);
    try { const r = await api.credits.packs(t); setPacks(r.packs); setBalance(r.balance); }
    catch (e) { setError((e as ApiError).message); } finally { setLoading(false); }
  }, []);
  useEffect(() => { load(); }, [load]);

  function flash(m: string) { setNotice(m); setTimeout(() => setNotice(null), 5000); }

  async function buy(pack: CreditPackClient) {
    const t = getToken(); if (!t) return;
    setBuying(pack.id); setError(null);
    try {
      const order = await api.credits.orderPack(t, pack.id);
      const ready = await loadRazorpay();
      if (!ready) { setError("Could not load the payment gateway. Try again."); setBuying(null); return; }

      const RazorpayCtor = (window as unknown as { Razorpay: new (o: unknown) => { open: () => void } }).Razorpay;
      const rzp = new RazorpayCtor({
        key: order.key_id, amount: order.amount, currency: order.currency, order_id: order.order_id,
        name: "Eydia.ai", description: `${pack.name} — ${pack.credits.toLocaleString("en-IN")} credits`,
        prefill: { name: user.tenant?.company_name ?? user.name, email: user.email },
        theme: { color: "#4c57d8" },
        handler: async (resp: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }) => {
          try {
            const v = await api.credits.confirmPack(t, {
              pack_id: pack.id,
              razorpay_order_id: resp.razorpay_order_id,
              razorpay_payment_id: resp.razorpay_payment_id,
              razorpay_signature: resp.razorpay_signature,
            });
            setBalance(v.balance); flash(v.message);
          } catch (err) { setError((err as ApiError).message); } finally { setBuying(null); }
        },
        modal: { ondismiss: () => setBuying(null) },
      });
      rzp.open();
    } catch (e) { setError((e as ApiError).message); setBuying(null); }
  }

  return (
    <div>
      <PageHeader title="Buy Credits" subtitle="Top up your AI credits anytime. New credits are added to your current balance."
        action={<div className="panel" style={{ padding: "8px 16px", display: "flex", alignItems: "center", gap: 8 }}>
          <span style={{ fontSize: 18 }}>⚡</span>
          <div><div style={{ fontSize: 20, fontWeight: 800, lineHeight: 1 }}>{balance.toLocaleString("en-IN")}</div><div className="muted" style={{ fontSize: 11 }}>Current balance</div></div>
        </div>} />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      {loading ? <LoadingBlock /> : packs.length === 0 ? (
        <div className="panel" style={{ textAlign: "center", padding: 40 }}><span className="muted">No credit packs are available right now.</span></div>
      ) : (
        <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(230px,1fr))", gap: 16 }}>
          {packs.map((p, i) => {
            const c = ACCENTS[i % ACCENTS.length];
            return (
              <div key={p.id} className="panel" style={{ textAlign: "center", borderTop: `4px solid ${c}`, padding: "22px 18px" }}>
                <div style={{ fontSize: 34, fontWeight: 800, color: c, lineHeight: 1 }}>{p.credits.toLocaleString("en-IN")}</div>
                <div className="muted" style={{ fontSize: 11, fontWeight: 700, letterSpacing: ".06em" }}>CREDITS</div>
                <div style={{ fontWeight: 800, fontSize: 16, marginTop: 12 }}>{p.name}</div>
                <div style={{ fontSize: 26, fontWeight: 800, marginTop: 6 }}>{inr(p.price)}</div>
                <div className="muted" style={{ fontSize: 11.5 }}>Incl. {p.gst_rate}% GST ({inr(p.gst)})</div>
                <div className="muted" style={{ fontSize: 12, marginTop: 6 }}>Balance after: <b style={{ color: "var(--text)" }}>{(balance + p.credits).toLocaleString("en-IN")}</b></div>
                <button className="btn" style={{ width: "100%", marginTop: 14, background: c, boxShadow: "none" }}
                  disabled={!!buying} onClick={() => buy(p)}>
                  {buying === p.id ? "Opening…" : `Buy — Pay ${inr(p.price)}`}
                </button>
              </div>
            );
          })}
        </div>
      )}
      <p className="muted" style={{ fontSize: 12, marginTop: 16 }}>Payments are processed securely by Razorpay (card / UPI / netbanking). Credits are added instantly once payment succeeds.</p>
    </div>
  );
}
