"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, AiInsights } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

const RANGES: { k: string; label: string }[] = [
  { k: "7d", label: "7 days" },
  { k: "30d", label: "30 days" },
  { k: "mtd", label: "This month" },
  { k: "all", label: "All time" },
];

const PROVIDER_META: Record<string, { label: string; icon: string; color: string }> = {
  gemini: { label: "Gemini (Google)", icon: "✦", color: "#1a73e8" },
  openai: { label: "OpenAI", icon: "◍", color: "#10a37f" },
  anthropic: { label: "Claude (Anthropic)", icon: "✶", color: "#d97757" },
};

const money = (m: { usd: number; inr: number }) => `₹${m.inr.toLocaleString("en-IN", { maximumFractionDigits: 2 })}`;
const usd = (n: number) => `$${n.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const num = (n: number) => n.toLocaleString("en-IN");

export default function AiInsightsPage() {
  const user = useUser();
  const [range, setRange] = useState("30d");
  const [data, setData] = useState<AiInsights | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      setData(await api.admin.aiInsights(token, range));
    } catch (e) {
      setError((e as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, [range]);
  useEffect(() => { load(); }, [load]);

  if (!user.is_super_admin) {
    return <div className="panel" style={{ textAlign: "center", padding: 40 }}>This area is for the platform super admin only.</div>;
  }

  const maxDaily = data ? Math.max(0.0001, ...data.daily.map((d) => d.cost_usd)) : 1;

  return (
    <>
      <PageHeader title="AI Insights" subtitle="Spend across Gemini, OpenAI and Claude — per provider and per model." />

      {error && <div className="error">{error}</div>}

      {/* Range selector */}
      <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 14 }}>
        {RANGES.map((r) => (
          <button key={r.k} className="btn-mini" onClick={() => setRange(r.k)}
            style={range === r.k ? { background: "var(--accent)", color: "#fff", border: "none" } : undefined}>
            {r.label}
          </button>
        ))}
        <button className="btn-mini" onClick={load} style={{ marginLeft: "auto" }}>↻ Refresh</button>
      </div>

      {loading || !data ? (
        <LoadingBlock label="Loading AI spend…" />
      ) : (
        <>
          {/* Totals */}
          <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(190px, 1fr))", gap: 12, marginBottom: 18 }}>
            {[
              { label: "This month", v: data.totals.month_to_date },
              { label: "Today", v: data.totals.today },
              { label: RANGES.find((r) => r.k === range)?.label ?? "Range", v: data.totals.range },
              { label: "All time", v: data.totals.all_time },
            ].map((c) => (
              <div key={c.label} className="stat">
                <div className="label">{c.label}</div>
                <div className="value" style={{ fontSize: 24 }}>{money(c.v)}</div>
                <div className="muted" style={{ fontSize: 12, marginTop: 2 }}>{usd(c.v.usd)}</div>
              </div>
            ))}
          </div>

          {/* Active provider + source note */}
          <div className="panel" style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center", marginBottom: 16 }}>
            <span style={{ fontSize: 13 }}>
              <strong>Active provider:</strong>{" "}
              {data.active_provider
                ? <span style={{ fontWeight: 700, color: PROVIDER_META[data.active_provider]?.color }}>{PROVIDER_META[data.active_provider]?.label ?? data.active_provider}</span>
                : <span className="muted">Auto (from .env keys)</span>}
            </span>
            <span className="muted" style={{ fontSize: 12, marginLeft: "auto" }}>≈ ₹{data.usd_to_inr}/$ · figures are estimates</span>
          </div>

          {/* Per-provider cards */}
          <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(240px, 1fr))", gap: 12, marginBottom: 18 }}>
            {(["gemini", "openai", "anthropic"] as const).map((p) => {
              const row = data.by_provider.find((x) => x.provider === p);
              const meta = PROVIDER_META[p];
              const cfg = data.providers[p];
              return (
                <div key={p} className="panel" style={{ borderTop: `3px solid ${meta.color}` }}>
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8 }}>
                    <h3 style={{ margin: 0, fontSize: 15 }}>
                      <span style={{ color: meta.color, marginRight: 6 }}>{meta.icon}</span>{meta.label}
                    </h3>
                    {cfg?.active && <span style={{ fontSize: 10, fontWeight: 800, color: "#0a7d47", background: "#e7f7ef", padding: "2px 8px", borderRadius: 999 }}>ACTIVE</span>}
                  </div>
                  <div style={{ fontSize: 26, fontWeight: 800, margin: "10px 0 2px" }}>{row ? money({ usd: row.cost_usd, inr: row.cost_inr }) : "₹0"}</div>
                  <div className="muted" style={{ fontSize: 12 }}>{row ? usd(row.cost_usd) : "$0.00"} · {row?.calls ?? 0} calls</div>
                  <div style={{ display: "grid", gap: 3, fontSize: 12.5, color: "#54656f", marginTop: 10, paddingTop: 10, borderTop: "1px solid var(--border)" }}>
                    <div style={{ display: "flex", justifyContent: "space-between" }}><span>Input tokens</span><strong>{num(row?.input_tokens ?? 0)}</strong></div>
                    <div style={{ display: "flex", justifyContent: "space-between" }}><span>Output tokens</span><strong>{num(row?.output_tokens ?? 0)}</strong></div>
                    <div style={{ display: "flex", justifyContent: "space-between" }}><span>Images</span><strong>{num(row?.images ?? 0)}</strong></div>
                    <div style={{ display: "flex", justifyContent: "space-between" }}><span>Text model</span><strong style={{ fontSize: 11.5 }}>{cfg?.model ?? "—"}</strong></div>
                    <div style={{ display: "flex", justifyContent: "space-between" }}><span>API key</span><strong style={{ color: cfg?.has_key ? "#0a7d47" : "#c53030" }}>{cfg?.has_key ? "set" : "not set"}</strong></div>
                  </div>
                </div>
              );
            })}
          </div>

          {/* Daily trend */}
          <div className="panel">
            <h2 style={{ marginTop: 0 }}>Daily spend (last 30 days)</h2>
            <div style={{ display: "flex", alignItems: "flex-end", gap: 3, height: 120, marginTop: 10 }}>
              {data.daily.map((d) => (
                <div key={d.date} title={`${d.date}: ${usd(d.cost_usd)}`}
                  style={{ flex: 1, minWidth: 0, display: "flex", flexDirection: "column", justifyContent: "flex-end", height: "100%" }}>
                  <div style={{ height: `${Math.max(2, (d.cost_usd / maxDaily) * 100)}%`, background: "var(--brand-grad, var(--accent))", borderRadius: "3px 3px 0 0" }} />
                </div>
              ))}
            </div>
            <div className="muted" style={{ fontSize: 11, marginTop: 6, display: "flex", justifyContent: "space-between" }}>
              <span>{data.daily[0]?.date}</span><span>peak {usd(maxDaily)}/day</span><span>{data.daily[data.daily.length - 1]?.date}</span>
            </div>
          </div>

          {/* Per-model table */}
          <div className="panel" style={{ padding: 0, overflowX: "auto" }}>
            <div style={{ padding: "16px 16px 0" }}><h2 style={{ margin: 0 }}>Spend by model</h2></div>
            <table style={{ width: "100%", borderCollapse: "collapse", minWidth: 640, marginTop: 10 }}>
              <thead>
                <tr style={{ textAlign: "left", fontSize: 12, color: "#667781", background: "#f8fafb", borderBottom: "1px solid #eef1f2" }}>
                  <th style={{ padding: "10px 14px" }}>Provider</th>
                  <th style={{ padding: "10px 14px" }}>Model</th>
                  <th style={{ padding: "10px 14px", textAlign: "right" }}>Calls</th>
                  <th style={{ padding: "10px 14px", textAlign: "right" }}>In tokens</th>
                  <th style={{ padding: "10px 14px", textAlign: "right" }}>Out tokens</th>
                  <th style={{ padding: "10px 14px", textAlign: "right" }}>Images</th>
                  <th style={{ padding: "10px 14px", textAlign: "right" }}>Cost</th>
                </tr>
              </thead>
              <tbody>
                {data.by_model.length === 0 && (
                  <tr><td colSpan={7} style={{ padding: "22px 14px", textAlign: "center" }}><span className="muted">No AI usage recorded yet for this range.</span></td></tr>
                )}
                {data.by_model.map((m, i) => (
                  <tr key={i} style={{ borderBottom: "1px solid #f4f6f7" }}>
                    <td style={{ padding: "10px 14px", fontWeight: 600, color: PROVIDER_META[m.provider]?.color }}>{PROVIDER_META[m.provider]?.label ?? m.provider}</td>
                    <td style={{ padding: "10px 14px", fontFamily: "monospace", fontSize: 12.5 }}>{m.model}</td>
                    <td style={{ padding: "10px 14px", textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{num(m.calls)}</td>
                    <td style={{ padding: "10px 14px", textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{num(m.input_tokens)}</td>
                    <td style={{ padding: "10px 14px", textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{num(m.output_tokens)}</td>
                    <td style={{ padding: "10px 14px", textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{num(m.images)}</td>
                    <td style={{ padding: "10px 14px", textAlign: "right", fontVariantNumeric: "tabular-nums", fontWeight: 700 }}>{money({ usd: m.cost_usd, inr: m.cost_inr })}<div className="muted" style={{ fontSize: 11, fontWeight: 400 }}>{usd(m.cost_usd)}</div></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <p className="muted" style={{ fontSize: 12, marginTop: 10, lineHeight: 1.6 }}>
            ℹ️ {data.note}
          </p>
        </>
      )}
    </>
  );
}
