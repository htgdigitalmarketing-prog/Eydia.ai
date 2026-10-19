"use client";

import { useCallback, useEffect, useState } from "react";
import { useUser } from "@/lib/user-context";
import { AccessDenied, hasFeature } from "@/components/PlanGate";
import { PageHeader } from "@/components/PageHeader";
import { api, ApiError, AdsResponse, AdReportItem } from "@/lib/api";
import { getToken } from "@/lib/auth";

const inr = (n: number) => "₹" + n.toLocaleString("en-IN");

export default function AdsPage() {
  const user = useUser();
  if (!hasFeature(user, "ads")) return <AccessDenied dashboard="Ads Reporting" />;
  return <AdsDashboard />;
}

function AdsDashboard() {
  const [data, setData] = useState<AdsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [network, setNetwork] = useState("");
  const [showNew, setShowNew] = useState(false);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    try { setData(await api.ads.list(token, network || undefined)); }
    catch (e) { setError((e as ApiError).message); }
  }, [network]);
  useEffect(() => { load(); }, [load]);

  async function remove(id: string) {
    const token = getToken();
    if (!token || !confirm("Delete this report?")) return;
    try { await api.ads.remove(token, id); load(); } catch (e) { setError((e as ApiError).message); }
  }

  const t = data?.totals;
  return (
    <div>
      <PageHeader title="Ads Reporting" subtitle="Track Google & Meta campaign spend, clicks and leads in one place."
        action={<button className="btn" style={{ width: "auto", padding: "9px 18px" }} onClick={() => setShowNew(true)}>+ Add report</button>} />

      {error && <div className="error" style={{ marginBottom: 12 }}>{error}</div>}

      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(170px,1fr))", gap: 12, marginBottom: 18 }}>
        <Stat label="Total spend" value={t ? inr(t.spend) : "—"} icon="💸" bg="#fff4e6" />
        <Stat label="Clicks" value={t?.clicks ?? 0} icon="👆" bg="#eef3ff" />
        <Stat label="Conversions" value={t?.conversions ?? 0} icon="✅" bg="#e9faf1" />
        <Stat label="Leads" value={t?.leads ?? 0} icon="🎯" bg="#eaf1fe" />
        <Stat label="Avg CPL" value={t && t.leads ? inr(Math.round(t.spend / t.leads)) : "—"} icon="🧮" bg="#f3eefe" />
      </div>

      <div className="panel" style={{ padding: 12, marginBottom: 14, display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
        <select value={network} onChange={(e) => setNetwork(e.target.value)}
          style={{ padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
          <option value="">All networks</option>
          {(data?.networks ?? ["GOOGLE", "META"]).map((n) => <option key={n} value={n}>{n}</option>)}
        </select>
        <span className="muted" style={{ fontSize: 12.5 }}>
          Google {data?.has_google ? "🟢 connected" : "⚪ not connected"} · Meta {data?.has_meta ? "🟢 connected" : "⚪ not connected"}
        </span>
      </div>

      <div className="panel" style={{ padding: 0, overflowX: "auto" }}>
        <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13.5 }}>
          <thead>
            <tr style={{ textAlign: "left", background: "var(--bg)" }}>
              {["Network", "Campaign", "Spend", "Clicks", "Impr.", "CTR", "Conv.", "Leads", "CPL", "Period", ""].map((h) => (
                <th key={h} style={{ padding: "10px 12px", fontSize: 12, color: "var(--muted)", whiteSpace: "nowrap" }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {(data?.reports ?? []).map((r: AdReportItem) => (
              <tr key={r.id} style={{ borderTop: "1px solid var(--border)" }}>
                <td style={{ padding: "10px 12px" }}><span style={{ background: r.network === "GOOGLE" ? "#4285F41a" : "#1877F21a", color: r.network === "GOOGLE" ? "#4285F4" : "#1877F2", padding: "2px 9px", borderRadius: 999, fontSize: 11, fontWeight: 700 }}>{r.network}</span></td>
                <td style={{ padding: "10px 12px" }}>{r.campaign}</td>
                <td style={{ padding: "10px 12px" }}>{inr(r.spend)}</td>
                <td style={{ padding: "10px 12px" }}>{r.clicks.toLocaleString("en-IN")}</td>
                <td style={{ padding: "10px 12px" }}>{r.impressions.toLocaleString("en-IN")}</td>
                <td style={{ padding: "10px 12px" }}>{r.ctr}%</td>
                <td style={{ padding: "10px 12px" }}>{r.conversions}</td>
                <td style={{ padding: "10px 12px" }}>{r.leads}</td>
                <td style={{ padding: "10px 12px" }}>{r.cpl !== null ? inr(r.cpl) : "—"}</td>
                <td style={{ padding: "10px 12px", whiteSpace: "nowrap", fontSize: 12 }} className="muted">{r.period_start} → {r.period_end}</td>
                <td style={{ padding: "10px 12px" }}><button className="btn-mini" onClick={() => remove(r.id)}>✕</button></td>
              </tr>
            ))}
            {(data?.reports?.length ?? 0) === 0 && (
              <tr><td colSpan={11} style={{ padding: 28, textAlign: "center" }}><span className="muted">No reports yet. Add one to start tracking.</span></td></tr>
            )}
          </tbody>
        </table>
      </div>

      {showNew && <NewReportModal networks={data?.networks ?? ["GOOGLE", "META"]} onClose={() => setShowNew(false)} onCreated={() => { setShowNew(false); load(); }} />}
    </div>
  );
}

function NewReportModal({ networks, onClose, onCreated }: { networks: string[]; onClose: () => void; onCreated: () => void }) {
  const [form, setForm] = useState({ network: networks[0] ?? "GOOGLE", campaign: "", spend: "", clicks: "", impressions: "", conversions: "", leads: "", period_start: "", period_end: "" });
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSaving(true); setErr(null);
    try {
      await api.ads.create(token, {
        network: form.network, campaign: form.campaign,
        spend: Number(form.spend) || 0, clicks: Number(form.clicks) || 0,
        impressions: Number(form.impressions) || 0, conversions: Number(form.conversions) || 0,
        leads: Number(form.leads) || 0, period_start: form.period_start, period_end: form.period_end,
      });
      onCreated();
    } catch (e2) { const a = e2 as ApiError; setErr(a.errors ? Object.values(a.errors).flat().join(". ") : a.message); } finally { setSaving(false); }
  }

  const field = (key: keyof typeof form, label: string, type = "text") => (
    <div className="field" style={{ flex: 1, minWidth: 130 }}>
      <label>{label}</label>
      <input type={type} value={form[key]} onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.value }))} />
    </div>
  );

  return (
    <div className="msg-info-overlay" onClick={onClose} style={{ zIndex: 200 }}>
      <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 620 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <h2 style={{ margin: 0 }}>Add ad report</h2>
          <button className="btn-mini" onClick={onClose}>Close</button>
        </div>
        {err && <div className="error">{err}</div>}
        <form onSubmit={submit}>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            <div className="field" style={{ flex: 1, minWidth: 130 }}>
              <label>Network</label>
              <select value={form.network} onChange={(e) => setForm((f) => ({ ...f, network: e.target.value }))}
                style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
                {networks.map((n) => <option key={n} value={n}>{n}</option>)}
              </select>
            </div>
            {field("campaign", "Campaign name")}
          </div>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            {field("spend", "Spend (₹)", "number")}
            {field("clicks", "Clicks", "number")}
            {field("impressions", "Impressions", "number")}
          </div>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            {field("conversions", "Conversions", "number")}
            {field("leads", "Leads", "number")}
          </div>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            {field("period_start", "Period start", "date")}
            {field("period_end", "Period end", "date")}
          </div>
          <button className="btn" disabled={saving} style={{ width: "auto", padding: "11px 24px", marginTop: 6 }}>{saving ? "Saving…" : "Save report"}</button>
        </form>
      </div>
    </div>
  );
}

function Stat({ label, value, icon, bg }: { label: string; value: React.ReactNode; icon: string; bg: string }) {
  return (
    <div className="stat-card">
      <div className="ic" style={{ background: bg }}>{icon}</div>
      <div><div className="sc-label">{label}</div><div className="sc-value">{value}</div></div>
    </div>
  );
}
