"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, DailyMessageStat, MessageStats, CampaignStat } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { PageHeader } from "@/components/PageHeader";

const DAYS_OPTIONS = [7, 14, 30, 60, 90];

function BarChart({ data, maxValue }: { data: DailyMessageStat[]; maxValue: number }) {
  if (!data.length) return <p className="muted">No data</p>;
  const max = maxValue || 1;
  return (
    <div style={{ overflowX: "auto" }}>
      <div style={{ display: "flex", alignItems: "flex-end", gap: 2, minWidth: data.length * 28, height: 180, padding: "0 4px" }}>
        {data.map((d) => {
          const total = d.sent + d.inbound;
          const h = Math.max((total / max) * 160, 2);
          const failH = d.failed ? Math.max((d.failed / max) * 160, 1) : 0;
          return (
            <div key={d.date} style={{ flex: 1, display: "flex", flexDirection: "column", alignItems: "center", minWidth: 20 }}>
              <div style={{ display: "flex", flexDirection: "column", alignItems: "center", width: "100%" }}>
                <div style={{ fontSize: 9, color: "var(--muted)", marginBottom: 2 }}>{total || ""}</div>
                <div style={{ position: "relative", width: "100%", maxWidth: 24 }}>
                  <div style={{ height: h, background: "#25d366", borderRadius: "3px 3px 0 0", width: "100%" }} title={`Sent: ${d.sent}, Inbound: ${d.inbound}`} />
                  {failH > 0 && (
                    <div style={{ height: failH, background: "#dc2626", width: "100%", marginTop: 1, borderRadius: "0 0 3px 3px" }} title={`Failed: ${d.failed}`} />
                  )}
                </div>
              </div>
              <div style={{ fontSize: 9, color: "var(--muted)", marginTop: 4, whiteSpace: "nowrap", transform: "rotate(-45deg)", transformOrigin: "top center" }}>
                {new Date(d.date).toLocaleDateString(undefined, { month: "short", day: "numeric" })}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function Pct({ value }: { value: number }) {
  const color = value >= 80 ? "#0a7d47" : value >= 50 ? "#856404" : "#c53030";
  return <span style={{ color, fontWeight: 600 }}>{value.toFixed(1)}%</span>;
}

const CAMP_STATUS_COLORS: Record<string, { bg: string; fg: string }> = {
  draft: { bg: "#eef1f2", fg: "#667781" },
  scheduled: { bg: "#fff3cd", fg: "#856404" },
  running: { bg: "#cce5ff", fg: "#004085" },
  completed: { bg: "#e7f7ef", fg: "#0a7d47" },
  cancelled: { bg: "#eef1f2", fg: "#667781" },
  failed: { bg: "#fdecec", fg: "#c53030" },
};

export default function AnalyticsPage() {
  const [msgStats, setMsgStats] = useState<MessageStats | null>(null);
  const [campStats, setCampStats] = useState<CampaignStat[]>([]);
  const [days, setDays] = useState(30);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    setError(null);
    try {
      const [m, c] = await Promise.all([
        api.analytics.messages(token, days),
        api.analytics.campaigns(token),
      ]);
      setMsgStats(m);
      setCampStats(c.campaigns);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, [days]);

  useEffect(() => { load(); }, [load]);

  const maxDaily = msgStats ? Math.max(...msgStats.daily.map((d) => d.sent + d.inbound), 1) : 1;

  return (
    <>
      <PageHeader title="Analytics" subtitle="Message delivery, read rates & campaign performance" />

      {error && <div className="error">{error}</div>}

      {/* Days selector */}
      <div style={{ display: "flex", gap: 8, marginBottom: 16 }}>
        {DAYS_OPTIONS.map((d) => (
          <button
            key={d}
            className={d === days ? "btn" : "btn-mini"}
            style={d === days ? { width: "auto", padding: "6px 14px", fontSize: 13 } : undefined}
            onClick={() => setDays(d)}
          >
            {d}d
          </button>
        ))}
      </div>

      {loading ? (
        <div className="panel"><p className="muted">Loading analytics...</p></div>
      ) : (
        <>
          {/* Message totals */}
          {msgStats && (
            <div className="grid">
              <div className="stat">
                <div className="label">Sent</div>
                <div className="value">{msgStats.totals.total_sent}</div>
              </div>
              <div className="stat">
                <div className="label">Delivered</div>
                <div className="value" style={{ color: "#007bfc" }}>{msgStats.totals.total_delivered}</div>
              </div>
              <div className="stat">
                <div className="label">Read</div>
                <div className="value" style={{ color: "#53bdeb" }}>{msgStats.totals.total_read}</div>
              </div>
              <div className="stat">
                <div className="label">Failed</div>
                <div className="value" style={{ color: "#c53030" }}>{msgStats.totals.total_failed}</div>
              </div>
              <div className="stat">
                <div className="label">Inbound</div>
                <div className="value" style={{ color: "#0a7d47" }}>{msgStats.totals.total_inbound}</div>
              </div>
              <div className="stat">
                <div className="label">Delivery rate</div>
                <div className="value"><Pct value={msgStats.totals.delivery_rate} /></div>
              </div>
              <div className="stat">
                <div className="label">Read rate</div>
                <div className="value"><Pct value={msgStats.totals.read_rate} /></div>
              </div>
            </div>
          )}

          {/* Daily chart */}
          <div className="panel">
            <h2>Daily messages (last {days} days)</h2>
            <div style={{ marginTop: 12, paddingBottom: 30 }}>
              {msgStats ? <BarChart data={msgStats.daily} maxValue={maxDaily} /> : <p className="muted">No data</p>}
            </div>
            <div style={{ display: "flex", gap: 16, fontSize: 12, color: "var(--muted)" }}>
              <span><span style={{ display: "inline-block", width: 10, height: 10, background: "#25d366", borderRadius: 2, marginRight: 4 }} />Sent + Inbound</span>
              <span><span style={{ display: "inline-block", width: 10, height: 10, background: "#dc2626", borderRadius: 2, marginRight: 4 }} />Failed</span>
            </div>
          </div>

          {/* Campaign performance */}
          <div className="panel">
            <h2>Campaign performance</h2>
            {campStats.length === 0 ? (
              <p className="muted">No campaigns yet.</p>
            ) : (
              <div style={{ overflowX: "auto", marginTop: 12 }}>
                <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
                  <thead>
                    <tr style={{ borderBottom: "2px solid var(--border)" }}>
                      <th style={{ textAlign: "left", padding: "8px 4px" }}>Campaign</th>
                      <th style={{ textAlign: "left", padding: "8px 4px" }}>Status</th>
                      <th style={{ textAlign: "right", padding: "8px 4px" }}>Recipients</th>
                      <th style={{ textAlign: "right", padding: "8px 4px" }}>Sent</th>
                      <th style={{ textAlign: "right", padding: "8px 4px" }}>Delivered</th>
                      <th style={{ textAlign: "right", padding: "8px 4px" }}>Read</th>
                      <th style={{ textAlign: "right", padding: "8px 4px" }}>Failed</th>
                      <th style={{ textAlign: "right", padding: "8px 4px" }}>Delivery %</th>
                      <th style={{ textAlign: "right", padding: "8px 4px" }}>Read %</th>
                    </tr>
                  </thead>
                  <tbody>
                    {campStats.map((c, i) => {
                      const sc = CAMP_STATUS_COLORS[c.status] ?? CAMP_STATUS_COLORS.draft;
                      return (
                        <tr key={i} style={{ borderBottom: "1px solid var(--border)" }}>
                          <td style={{ padding: "8px 4px", fontWeight: 500 }}>{c.name}</td>
                          <td style={{ padding: "8px 4px" }}>
                            <span style={{ background: sc.bg, color: sc.fg, padding: "2px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600 }}>{c.status}</span>
                          </td>
                          <td style={{ padding: "8px 4px", textAlign: "right" }}>{c.total_recipients}</td>
                          <td style={{ padding: "8px 4px", textAlign: "right" }}>{c.sent_count}</td>
                          <td style={{ padding: "8px 4px", textAlign: "right", color: "#007bfc" }}>{c.delivered_count}</td>
                          <td style={{ padding: "8px 4px", textAlign: "right", color: "#53bdeb" }}>{c.read_count}</td>
                          <td style={{ padding: "8px 4px", textAlign: "right", color: "#c53030" }}>{c.failed_count}</td>
                          <td style={{ padding: "8px 4px", textAlign: "right" }}><Pct value={c.delivery_rate} /></td>
                          <td style={{ padding: "8px 4px", textAlign: "right" }}><Pct value={c.read_rate} /></td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </>
      )}
    </>
  );
}
