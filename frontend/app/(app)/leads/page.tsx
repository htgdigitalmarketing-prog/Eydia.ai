"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useUser } from "@/lib/user-context";
import { AccessDenied, hasFeature } from "@/components/PlanGate";
import { PageHeader } from "@/components/PageHeader";
import { api, ApiError, GoogleLeadConnection, LeadConnection, LeadItem, LeadsResponse } from "@/lib/api";
import { getToken } from "@/lib/auth";

const SOURCE_META: Record<string, { label: string; color: string; icon: string }> = {
  GOOGLE: { label: "Google", color: "#4285F4", icon: "🔎" },
  META: { label: "Meta", color: "#1877F2", icon: "📘" },
  WHATSAPP: { label: "WhatsApp", color: "#25D366", icon: "💬" },
  WEBSITE: { label: "Website", color: "#6d5efc", icon: "🌐" },
  MANUAL: { label: "Manual", color: "#667781", icon: "✍️" },
};
const STAGE_LABEL: Record<string, string> = {
  NEW: "New", CONTACTED: "Contacted", FOLLOW_UP: "Follow-up", APPOINTMENT: "Appointment", CONVERTED: "Converted", LOST: "Lost",
};
const inr = (n: number) => "₹" + n.toLocaleString("en-IN");

function SourceBadge({ source }: { source: string }) {
  const m = SOURCE_META[source] ?? { label: source, color: "#667781", icon: "•" };
  return (
    <span style={{ background: m.color + "1a", color: m.color, padding: "2px 9px", borderRadius: 999, fontSize: 11, fontWeight: 700, whiteSpace: "nowrap" }}>
      {m.icon} {m.label}
    </span>
  );
}

export default function LeadsPage() {
  const user = useUser();
  if (!hasFeature(user, "dash_leads")) return <AccessDenied dashboard="Lead Management" />;
  return <LeadsDashboard />;
}

function LeadsDashboard() {
  const [data, setData] = useState<LeadsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [sourceFilter, setSourceFilter] = useState("");
  const [view, setView] = useState<"board" | "table">("board");
  const [showNew, setShowNew] = useState(false);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    try {
      const res = await api.leads.list(token, { search: search || undefined, source: sourceFilter || undefined });
      setData(res);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }, [search, sourceFilter]);

  useEffect(() => { load(); }, [load]);

  async function move(lead: LeadItem, stage: string) {
    const token = getToken();
    if (!token || lead.stage === stage) return;
    // optimistic
    setData((d) => d ? { ...d, leads: d.leads.map((l) => l.id === lead.id ? { ...l, stage } : l) } : d);
    try { await api.leads.move(token, lead.id, stage); await load(); } catch { await load(); }
  }

  async function sync(source: string) {
    const token = getToken();
    if (!token) return;
    setBusy(true);
    try {
      const res = await api.leads.sync(token, source);
      alert(res.message);
      await load();
    } catch (err) { alert((err as ApiError).message); } finally { setBusy(false); }
  }

  const stats = data?.stats;
  const stages = data?.stages ?? [];
  const byStage = useMemo(() => {
    const map: Record<string, LeadItem[]> = {};
    for (const s of stages) map[s] = [];
    (data?.leads ?? []).forEach((l) => { (map[l.stage] ??= []).push(l); });
    return map;
  }, [data, stages]);

  return (
    <div>
      <PageHeader title="Lead Management" subtitle="Leads from Google, Meta and manual entry — worked through your pipeline." />

      {error && <div className="error" style={{ marginBottom: 12 }}>{error}</div>}

      <LeadsConnectBar />

      {/* Stat cards */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(190px,1fr))", gap: 12, marginBottom: 18 }}>
        <Stat label="Total leads" value={stats?.total ?? 0} icon="📇" bg="#eef3ff" />
        <Stat label="Converted" value={stats?.converted ?? 0} icon="🏆" bg="#e9faf1" />
        <Stat label="Pipeline value" value={stats ? inr(stats.pipeline_value) : "—"} icon="💰" bg="#fff4e6" />
        <Stat label="From Google" value={stats?.by_source?.GOOGLE ?? 0} icon="🔎" bg="#eaf1fe" />
        <Stat label="From Meta" value={stats?.by_source?.META ?? 0} icon="📘" bg="#eaf1fe" />
      </div>

      {/* Toolbar */}
      <div className="panel" style={{ padding: 14, marginBottom: 16, display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
        <input placeholder="Search name, phone, email…" value={search} onChange={(e) => setSearch(e.target.value)}
          style={{ flex: 1, minWidth: 200, padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
        <select value={sourceFilter} onChange={(e) => setSourceFilter(e.target.value)}
          style={{ padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
          <option value="">All sources</option>
          {Object.keys(SOURCE_META).map((s) => <option key={s} value={s}>{SOURCE_META[s].label}</option>)}
        </select>
        <div style={{ display: "flex", border: "1px solid var(--border)", borderRadius: 10, overflow: "hidden" }}>
          <button className="btn-mini" style={{ borderRadius: 0, background: view === "board" ? "var(--accent)" : undefined, color: view === "board" ? "#fff" : undefined }} onClick={() => setView("board")}>Board</button>
          <button className="btn-mini" style={{ borderRadius: 0, background: view === "table" ? "var(--accent)" : undefined, color: view === "table" ? "#fff" : undefined }} onClick={() => setView("table")}>Table</button>
        </div>
        <button className="btn-mini" disabled={busy} onClick={() => sync("GOOGLE")}>⟳ Sync Google</button>
        <button className="btn-mini" disabled={busy} onClick={() => sync("META")}>⟳ Sync Meta</button>
        <button className="btn" style={{ width: "auto", padding: "9px 18px" }} onClick={() => setShowNew(true)}>+ New lead</button>
      </div>

      {/* Board (Kanban) */}
      {view === "board" ? (
        <div style={{ display: "grid", gridAutoFlow: "column", gridAutoColumns: "minmax(250px, 1fr)", gap: 12, overflowX: "auto", paddingBottom: 8 }}>
          {stages.map((stage) => (
            <div key={stage}
              onDragOver={(e) => e.preventDefault()}
              onDrop={(e) => { const id = e.dataTransfer.getData("lead"); const lead = data?.leads.find((l) => l.id === id); if (lead) move(lead, stage); }}
              style={{ background: "var(--card)", border: "1px solid var(--border)", borderRadius: 12, padding: 10, minHeight: 140 }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8, paddingBottom: 6, borderBottom: "1px solid var(--border)" }}>
                <strong style={{ fontSize: 13 }}>{STAGE_LABEL[stage] ?? stage}</strong>
                <span className="muted" style={{ fontSize: 12 }}>{byStage[stage]?.length ?? 0}</span>
              </div>
              <div style={{ display: "grid", gap: 8 }}>
                {(byStage[stage] ?? []).map((lead) => (
                  <div key={lead.id} draggable onDragStart={(e) => e.dataTransfer.setData("lead", lead.id)}
                    style={{ background: "var(--bg)", border: "1px solid var(--border)", borderRadius: 10, padding: "10px 12px", cursor: "grab" }}>
                    <div style={{ display: "flex", justifyContent: "space-between", gap: 6 }}>
                      <strong style={{ fontSize: 13.5 }}>{lead.name}</strong>
                      <SourceBadge source={lead.source} />
                    </div>
                    {lead.company && <div className="muted" style={{ fontSize: 12 }}>{lead.company}</div>}
                    <div className="muted" style={{ fontSize: 12 }}>{lead.phone ?? lead.email ?? ""}</div>
                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 6 }}>
                      <span style={{ fontSize: 11, fontWeight: 700, color: lead.score >= 75 ? "#0a7d47" : lead.score >= 50 ? "#f26f21" : "#667781" }}>Score {lead.score}</span>
                      {lead.value ? <span style={{ fontSize: 12, fontWeight: 700 }}>{inr(lead.value)}</span> : null}
                    </div>
                  </div>
                ))}
                {(byStage[stage]?.length ?? 0) === 0 && <div className="muted" style={{ fontSize: 12, textAlign: "center", padding: "10px 0" }}>—</div>}
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="panel" style={{ padding: 0, overflow: "hidden" }}>
          <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 14 }}>
            <thead>
              <tr style={{ background: "var(--bg)", textAlign: "left" }}>
                {["Name", "Source", "Stage", "Score", "Value", "Contact"].map((h) => <th key={h} style={{ padding: "10px 14px", fontSize: 12, color: "var(--muted)" }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {(data?.leads ?? []).map((lead) => (
                <tr key={lead.id} style={{ borderTop: "1px solid var(--border)" }}>
                  <td style={{ padding: "10px 14px" }}><strong>{lead.name}</strong>{lead.company && <div className="muted" style={{ fontSize: 12 }}>{lead.company}</div>}</td>
                  <td style={{ padding: "10px 14px" }}><SourceBadge source={lead.source} /></td>
                  <td style={{ padding: "10px 14px" }}>
                    <select value={lead.stage} onChange={(e) => move(lead, e.target.value)} style={{ padding: "5px 8px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}>
                      {stages.map((s) => <option key={s} value={s}>{STAGE_LABEL[s] ?? s}</option>)}
                    </select>
                  </td>
                  <td style={{ padding: "10px 14px" }}>{lead.score}</td>
                  <td style={{ padding: "10px 14px" }}>{lead.value ? inr(lead.value) : "—"}</td>
                  <td style={{ padding: "10px 14px" }} className="muted">{lead.phone ?? lead.email ?? "—"}</td>
                </tr>
              ))}
              {(data?.leads?.length ?? 0) === 0 && <tr><td colSpan={6} style={{ padding: 24, textAlign: "center" }} className="muted">No leads yet. Add one or sync a source.</td></tr>}
            </tbody>
          </table>
        </div>
      )}

      {showNew && <NewLeadModal sources={data?.sources ?? Object.keys(SOURCE_META)} onClose={() => setShowNew(false)} onCreated={() => { setShowNew(false); load(); }} />}
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

function LeadsConnectBar() {
  const [meta, setMeta] = useState<LeadConnection | null>(null);
  const [google, setGoogle] = useState<GoogleLeadConnection | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [showGoogle, setShowGoogle] = useState(false);

  const load = useCallback(() => {
    const token = getToken();
    if (!token) return;
    api.leads.metaConnection(token).then(setMeta).catch(() => {});
  }, []);
  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    const p = new URLSearchParams(window.location.search).get("meta_connect");
    if (p) {
      setMsg(p === "success" ? "Meta connected. New lead-ad submissions will import automatically; use “Sync Meta” to backfill." : `Meta connection ${p.replace(/_/g, " ")}.`);
      window.history.replaceState({}, "", window.location.pathname);
    }
  }, []);

  async function connectMeta() {
    const token = getToken();
    if (!token) return;
    setBusy(true);
    try { const r = await api.leads.metaConnect(token); window.location.href = r.url; }
    catch (e) { setMsg((e as ApiError).message); setBusy(false); }
  }
  async function disconnectMeta() {
    const token = getToken();
    if (!token || !confirm("Disconnect Meta Lead Ads?")) return;
    setBusy(true);
    try { await api.leads.metaDisconnect(token); load(); } catch (e) { setMsg((e as ApiError).message); } finally { setBusy(false); }
  }
  async function openGoogle() {
    const token = getToken();
    if (!token) return;
    setBusy(true);
    try { setGoogle(await api.leads.googleConnection(token)); setShowGoogle(true); }
    catch (e) { setMsg((e as ApiError).message); } finally { setBusy(false); }
  }

  return (
    <div className="panel" style={{ padding: "12px 16px", marginBottom: 16, display: "grid", gap: 10 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
        <span style={{ fontSize: 20 }}>{meta?.connected ? "🟢" : "📘"}</span>
        <div style={{ flex: 1, minWidth: 200 }}>
          <strong style={{ fontSize: 14 }}>Meta Lead Ads</strong>
          <div className="muted" style={{ fontSize: 12.5 }}>
            {meta && !meta.configured ? "Not configured on the server (set META_APP_ID / META_APP_SECRET)."
              : meta?.connected ? `Connected${meta.page ? ` · ${meta.page}` : ""}. Leads import in real time via webhook.`
              : "Connect a Facebook Page to auto-import Lead Ad submissions."}
          </div>
        </div>
        {meta?.configured && (meta.connected
          ? <button className="btn-mini" disabled={busy} onClick={disconnectMeta}>Disconnect</button>
          : <button className="btn-mini" disabled={busy} onClick={connectMeta}>{busy ? "Redirecting…" : "Connect Meta"}</button>)}
      </div>

      <div style={{ display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap", borderTop: "1px solid var(--border)", paddingTop: 10 }}>
        <span style={{ fontSize: 20 }}>🔎</span>
        <div style={{ flex: 1, minWidth: 200 }}>
          <strong style={{ fontSize: 14 }}>Google Lead Forms</strong>
          <div className="muted" style={{ fontSize: 12.5 }}>Paste a webhook URL + key into your Google Ads lead-form extension to receive leads.</div>
        </div>
        <button className="btn-mini" disabled={busy} onClick={openGoogle}>Webhook details</button>
      </div>

      {msg && <div className="muted" style={{ fontSize: 12.5, color: "var(--green)" }}>{msg}</div>}

      {showGoogle && google && (
        <div className="msg-info-overlay" onClick={() => setShowGoogle(false)} style={{ zIndex: 200 }}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 560 }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
              <h2 style={{ margin: 0 }}>Google Lead Form webhook</h2>
              <button className="btn-mini" onClick={() => setShowGoogle(false)}>Close</button>
            </div>
            <p className="muted" style={{ fontSize: 13 }}>In Google Ads → your lead-form extension → “Webhook integration”, paste these two values. Leads then arrive here automatically.</p>
            <label style={{ fontSize: 12, fontWeight: 700 }}>Webhook URL</label>
            <input readOnly value={google.webhook_url} onFocus={(e) => e.target.select()}
              style={{ width: "100%", padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 13, marginBottom: 10 }} />
            <label style={{ fontSize: 12, fontWeight: 700 }}>Key</label>
            <input readOnly value={google.google_key} onFocus={(e) => e.target.select()}
              style={{ width: "100%", padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 13 }} />
          </div>
        </div>
      )}
    </div>
  );
}

function NewLeadModal({ sources, onClose, onCreated }: { sources: string[]; onClose: () => void; onCreated: () => void }) {
  const [form, setForm] = useState({ name: "", phone: "", email: "", company: "", source: "MANUAL", value: "", notes: "" });
  const [err, setErr] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSaving(true); setErr(null);
    try {
      await api.leads.create(token, {
        name: form.name, phone: form.phone || null, email: form.email || null, company: form.company || null,
        source: form.source, value: form.value ? Number(form.value) : null, notes: form.notes || null,
      } as never);
      onCreated();
    } catch (e2) {
      const ae = e2 as ApiError;
      setErr(ae.errors ? Object.values(ae.errors).flat().join(". ") : ae.message);
    } finally { setSaving(false); }
  }

  return (
    <div className="msg-info-overlay" onClick={onClose} style={{ zIndex: 200 }}>
      <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 520 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <h2 style={{ margin: 0 }}>New lead</h2>
          <button className="btn-mini" onClick={onClose}>Close</button>
        </div>
        {err && <div className="error" style={{ marginTop: 10 }}>{err}</div>}
        <form onSubmit={submit} style={{ marginTop: 10 }}>
          <div className="field"><label>Name</label><input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required placeholder="Lead name" /></div>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            <div className="field" style={{ flex: 1, minWidth: 180 }}><label>Phone</label><input value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} placeholder="+91…" /></div>
            <div className="field" style={{ flex: 1, minWidth: 180 }}><label>Email</label><input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} placeholder="name@email.com" /></div>
          </div>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            <div className="field" style={{ flex: 1, minWidth: 180 }}><label>Company</label><input value={form.company} onChange={(e) => setForm((f) => ({ ...f, company: e.target.value }))} /></div>
            <div className="field" style={{ flex: 1, minWidth: 140 }}><label>Source</label>
              <select value={form.source} onChange={(e) => setForm((f) => ({ ...f, source: e.target.value }))} style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10 }}>
                {sources.map((s) => <option key={s} value={s}>{SOURCE_META[s]?.label ?? s}</option>)}
              </select>
            </div>
          </div>
          <div className="field"><label>Estimated value (₹)</label><input type="number" value={form.value} onChange={(e) => setForm((f) => ({ ...f, value: e.target.value }))} placeholder="0" /></div>
          <div className="field"><label>Notes</label><textarea value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} rows={2} style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontFamily: "inherit", resize: "vertical" }} /></div>
          <button className="btn" disabled={saving} style={{ width: "auto", padding: "11px 24px" }}>{saving ? "Saving…" : "Create lead"}</button>
        </form>
      </div>
    </div>
  );
}
