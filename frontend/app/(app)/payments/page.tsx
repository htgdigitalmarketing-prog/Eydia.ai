"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, AdminPayment } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

function fmtDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString(undefined, { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export default function PaymentsPage() {
  const me = useUser();
  const isSuper = !!me.is_super_admin;
  const [payments, setPayments] = useState<AdminPayment[]>([]);
  const [stats, setStats] = useState<{ count: number; paid: number; revenue: string } | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [q, setQ] = useState("");

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const res = await api.admin.payments(token);
      setPayments(res.payments);
      setStats(res.stats);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { if (isSuper) load(); else setLoading(false); }, [isSuper, load]);

  if (!isSuper) {
    return (
      <div>
        <PageHeader title="Payments" />
        <div className="panel"><p className="muted">This area is for the platform super admin only.</p></div>
      </div>
    );
  }

  const filtered = q.trim()
    ? payments.filter((p) => `${p.company} ${p.customer ?? ""} ${p.plan ?? ""} ${p.number} ${p.payment_id ?? ""}`.toLowerCase().includes(q.toLowerCase()))
    : payments;

  const badge = (s: string) => {
    const paid = s === "paid";
    return <span style={{ background: paid ? "#e7f7ef" : "#fff4e6", color: paid ? "#0a7d47" : "#c2410c", padding: "3px 10px", borderRadius: 999, fontSize: 12, fontWeight: 600, textTransform: "capitalize" }}>{s}</span>;
  };

  return (
    <div>
      <PageHeader title="Payments" subtitle="Every plan purchase across all companies — online (Razorpay) and free activations." />

      {error && <div className="error">{error}</div>}

      <div className="stat-cards">
        <div className="stat-card"><div className="ic" style={{ background: "#e7f0ff" }}>🧾</div><div><div className="sc-label">Transactions</div><div className="sc-value">{stats?.count ?? "—"}</div></div></div>
        <div className="stat-card"><div className="ic" style={{ background: "#e7f7ef" }}>✅</div><div><div className="sc-label">Paid</div><div className="sc-value">{stats?.paid ?? "—"}</div></div></div>
        <div className="stat-card"><div className="ic" style={{ background: "#f3eeff" }}>💰</div><div><div className="sc-label">Total revenue</div><div className="sc-value">{stats?.revenue ?? "—"}</div></div></div>
      </div>

      <div className="panel" style={{ padding: 12, marginBottom: 14, display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search company, customer, plan, payment id…"
          style={{ flex: 1, minWidth: 220, padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 13 }} />
        <span className="muted" style={{ fontSize: 12 }}>Showing {filtered.length} of {payments.length}</span>
      </div>

      {loading ? (
        <LoadingBlock label="Loading payments…" />
      ) : payments.length === 0 ? (
        <div className="panel" style={{ textAlign: "center", padding: 40 }}>
          <div style={{ fontSize: 40 }}>🧾</div>
          <h3 style={{ margin: "12px 0 4px" }}>No payments yet</h3>
          <p className="muted">Plan purchases will appear here as customers subscribe.</p>
        </div>
      ) : (
        <div className="panel" style={{ padding: 0, overflow: "hidden" }}>
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", fontSize: 12, color: "#667781", background: "#f8fafb", borderBottom: "1px solid #eef1f2" }}>
                <th style={{ padding: "12px 14px" }}>Date</th>
                <th style={{ padding: "12px 14px" }}>Company</th>
                <th style={{ padding: "12px 14px" }}>Plan</th>
                <th style={{ padding: "12px 14px" }}>Amount</th>
                <th style={{ padding: "12px 14px" }}>Status</th>
                <th style={{ padding: "12px 14px" }}>Gateway</th>
                <th style={{ padding: "12px 14px" }}>Invoice / Payment ID</th>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 && (
                <tr><td colSpan={7} style={{ padding: "24px 14px", textAlign: "center" }}><span className="muted">No matching payments.</span></td></tr>
              )}
              {filtered.map((p) => (
                <tr key={p.id} style={{ borderBottom: "1px solid #f4f6f7" }}>
                  <td style={{ padding: "12px 14px", fontSize: 13 }}>{fmtDate(p.issued_at)}</td>
                  <td style={{ padding: "12px 14px" }}>
                    <div style={{ fontWeight: 600, fontSize: 13.5 }}>{p.company}</div>
                    {p.customer && <div className="muted" style={{ fontSize: 12 }}>{p.customer}</div>}
                  </td>
                  <td style={{ padding: "12px 14px", fontSize: 13 }}>{p.plan ?? "—"}</td>
                  <td style={{ padding: "12px 14px", fontWeight: 700, fontVariantNumeric: "tabular-nums" }}>{p.total}</td>
                  <td style={{ padding: "12px 14px" }}>{badge(p.status)}</td>
                  <td style={{ padding: "12px 14px", fontSize: 13, textTransform: "capitalize" }}>{p.gateway}</td>
                  <td style={{ padding: "12px 14px", fontSize: 12 }}>
                    <div style={{ fontWeight: 600 }}>{p.number}</div>
                    {p.payment_id && <div className="muted" style={{ fontFamily: "monospace", fontSize: 11 }}>{p.payment_id}</div>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
