"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, AgentItem, AgentDetail, AgentStat } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { PageHeader } from "@/components/PageHeader";
import { UpgradePrompt, planAllows } from "@/components/PlanGate";
import { useUser } from "@/lib/user-context";

type Tab = "roster" | "workload";

export default function AgentsPage() {
  const gateUser = useUser();
  const [tab, setTab] = useState<Tab>("roster");
  const [agents, setAgents] = useState<AgentItem[]>([]);
  const [stats, setStats] = useState<AgentStat[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [detail, setDetail] = useState<AgentDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const [agentsRes, statsRes] = await Promise.all([
        api.agents.list(token),
        api.agents.stats(token).catch(() => ({ stats: [] as AgentStat[] })),
      ]);
      setAgents(agentsRes.agents);
      setStats(statsRes.stats);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  async function openDetail(id: string) {
    const token = getToken();
    if (!token) return;
    setDetailLoading(true);
    setError(null);
    try {
      const res = await api.agents.get(token, id);
      setDetail(res.agent);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setDetailLoading(false);
    }
  }

  async function unassign(conversationId: string) {
    const token = getToken();
    if (!token || !detail) return;
    try {
      await api.agents.unassign(token, { conversation_id: conversationId });
      await openDetail(detail.id);
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  const totalActive = agents.reduce((s, a) => s + a.active_conversations_count, 0);
  const totalHandled = agents.reduce((s, a) => s + a.total_handled, 0);

  if (!planAllows(gateUser.plan_limits, "max_agents")) return <UpgradePrompt title="Agents" feature="Agents" />;

  return (
    <div>
      <PageHeader
        title="Agents"
        subtitle="Your team and their live conversation workload."
      />

      {error && <div className="error">{error}</div>}

      <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: 12, marginBottom: 18 }}>
        <div className="stat"><div className="label">Agents</div><div className="value">{agents.length}</div></div>
        <div className="stat"><div className="label">Active chats</div><div className="value">{totalActive}</div></div>
        <div className="stat"><div className="label">Total handled</div><div className="value">{totalHandled}</div></div>
      </div>

      <div className="filter-tabs" style={{ marginBottom: 16 }}>
        <button className={tab === "roster" ? "active" : ""} onClick={() => setTab("roster")}>Roster</button>
        <button className={tab === "workload" ? "active" : ""} onClick={() => setTab("workload")}>Workload</button>
      </div>

      {loading ? (
        <div className="loading-block"><span className="spinner" /><span>Loading…</span></div>
      ) : tab === "roster" ? (
        agents.length === 0 ? (
          <div className="panel" style={{ textAlign: "center", padding: 40 }}>
            <div style={{ fontSize: 40 }}>🎧</div>
            <h3 style={{ margin: "12px 0 4px" }}>No agents yet</h3>
            <p className="muted">Invite team members to your workspace to start assigning conversations.</p>
          </div>
        ) : (
          <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(260px, 1fr))", gap: 14 }}>
            {agents.map((a) => (
              <div key={a.id} className="panel" style={{ cursor: "pointer" }} onClick={() => openDetail(a.id)}>
                <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                  <div style={{
                    width: 40, height: 40, borderRadius: "50%", background: "#25d366", color: "#fff",
                    display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, fontSize: 16,
                    overflow: "hidden", flexShrink: 0,
                  }}>
                    {a.avatar_url
                      ? /* eslint-disable-next-line @next/next/no-img-element */ <img src={a.avatar_url} alt="" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                      : a.name.slice(0, 1).toUpperCase()}
                  </div>
                  <div style={{ minWidth: 0 }}>
                    <div style={{ fontWeight: 600, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{a.name}</div>
                    <div className="muted" style={{ fontSize: 12, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{a.email}</div>
                  </div>
                </div>
                <div style={{ display: "flex", flexWrap: "wrap", gap: 4, marginTop: 10 }}>
                  {a.roles.length === 0 ? (
                    <span className="muted" style={{ fontSize: 12 }}>No role</span>
                  ) : a.roles.map((r) => (
                    <span key={r} style={{ background: "#eef1f2", color: "#54656f", padding: "1px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600 }}>{r}</span>
                  ))}
                </div>
                <div style={{ display: "flex", gap: 16, marginTop: 12, fontSize: 13 }}>
                  <span><strong>{a.active_conversations_count}</strong> <span className="muted">active</span></span>
                  <span><strong>{a.total_handled}</strong> <span className="muted">handled</span></span>
                </div>
              </div>
            ))}
          </div>
        )
      ) : (
        <div className="panel">
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", fontSize: 12, color: "#667781", borderBottom: "1px solid #eef1f2" }}>
                <th style={{ padding: "8px 6px" }}>Agent</th>
                <th style={{ padding: "8px 6px" }}>Open</th>
                <th style={{ padding: "8px 6px" }}>Pending</th>
                <th style={{ padding: "8px 6px" }}>Resolved</th>
                <th style={{ padding: "8px 6px" }}>Closed</th>
                <th style={{ padding: "8px 6px" }}>Total</th>
              </tr>
            </thead>
            <tbody>
              {stats.length === 0 ? (
                <tr><td colSpan={6} style={{ padding: 16 }} className="muted">No assigned conversations yet.</td></tr>
              ) : stats.map((s) => (
                <tr key={s.id} style={{ borderBottom: "1px solid #f6f7f8" }}>
                  <td style={{ padding: "10px 6px", fontWeight: 600 }}>{s.name}</td>
                  <td style={{ padding: "10px 6px" }}>{s.open}</td>
                  <td style={{ padding: "10px 6px" }}>{s.pending}</td>
                  <td style={{ padding: "10px 6px" }}>{s.resolved}</td>
                  <td style={{ padding: "10px 6px" }}>{s.closed}</td>
                  <td style={{ padding: "10px 6px", fontWeight: 600 }}>{s.total}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {(detail || detailLoading) && (
        <div className="msg-info-overlay" onClick={() => setDetail(null)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 520 }}>
            {detailLoading || !detail ? (
              <div className="loading-block"><span className="spinner" /><span>Loading…</span></div>
            ) : (
              <>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start" }}>
                  <div>
                    <h2 style={{ margin: 0 }}>{detail.name}</h2>
                    <p className="muted" style={{ margin: "4px 0 0" }}>{detail.email}</p>
                  </div>
                  <button className="btn-mini" onClick={() => setDetail(null)}>✕</button>
                </div>
                <div style={{ display: "flex", gap: 16, margin: "14px 0", fontSize: 14 }}>
                  <span><strong>{detail.active_conversations_count}</strong> <span className="muted">active</span></span>
                  <span><strong>{detail.total_handled}</strong> <span className="muted">handled</span></span>
                </div>
                <h4 style={{ margin: "8px 0" }}>Assigned conversations</h4>
                {(!detail.conversations || detail.conversations.length === 0) ? (
                  <p className="muted">None assigned right now.</p>
                ) : (
                  <div style={{ maxHeight: 320, overflowY: "auto" }}>
                    {detail.conversations.map((c) => (
                      <div key={c.id} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "8px 0", borderBottom: "1px solid #f6f7f8", gap: 8 }}>
                        <div style={{ minWidth: 0 }}>
                          <div style={{ fontWeight: 600, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{c.contact_name || c.contact_phone}</div>
                          <div className="muted" style={{ fontSize: 12 }}>{c.status}</div>
                        </div>
                        <button className="btn-mini" onClick={() => unassign(c.id)}>Unassign</button>
                      </div>
                    ))}
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
