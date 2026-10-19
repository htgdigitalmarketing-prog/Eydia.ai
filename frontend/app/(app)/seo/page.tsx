"use client";

import { useCallback, useEffect, useState } from "react";
import { useUser } from "@/lib/user-context";
import { AccessDenied, hasFeature } from "@/components/PlanGate";
import { PageHeader } from "@/components/PageHeader";
import {
  api, ApiError, SeoOverview, SeoKeywordItem, SeoCompetitorItem,
  SeoRankResult, SeoCompetitorResult,
} from "@/lib/api";
import { getToken } from "@/lib/auth";

export default function SeoPage() {
  const user = useUser();
  if (!hasFeature(user, "seo")) return <AccessDenied dashboard="SEO Toolkit" />;
  return <SeoDashboard />;
}

function SeoDashboard() {
  const [tab, setTab] = useState<"keywords" | "competitors" | "ideas">("keywords");
  const [overview, setOverview] = useState<SeoOverview | null>(null);
  const [error, setError] = useState<string | null>(null);

  const loadOverview = useCallback(() => {
    const token = getToken();
    if (!token) return;
    api.seo.overview(token).then(setOverview).catch((e) => setError((e as ApiError).message));
  }, []);
  useEffect(() => { loadOverview(); }, [loadOverview]);

  return (
    <div>
      <PageHeader title="SEO Toolkit" subtitle="Track keyword ranks, generate keyword ideas and size up competitors." />

      {error && <div className="error" style={{ marginBottom: 12 }}>{error}</div>}

      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(190px,1fr))", gap: 12, marginBottom: 18 }}>
        <Stat label="Tracked keywords" value={overview?.keywords ?? 0} icon="🔑" bg="#eef3ff" />
        <Stat label="Competitors" value={overview?.competitors ?? 0} icon="🥊" bg="#fff4e6" />
        <Stat label="Checks this month" value={overview?.checks_this_month ?? 0} icon="📈" bg="#e9faf1" />
        <Stat label="AI estimates" value={overview?.ai_configured ? "On" : "Off"} icon="🤖" bg={overview?.ai_configured ? "#e9faf1" : "#fdecec"} />
      </div>

      <div className="panel" style={{ padding: 6, marginBottom: 16, display: "inline-flex", gap: 4 }}>
        {(["keywords", "competitors", "ideas"] as const).map((t) => (
          <button key={t} className="btn-mini"
            style={{ background: tab === t ? "var(--accent)" : "transparent", color: tab === t ? "#fff" : undefined, border: "none" }}
            onClick={() => setTab(t)}>
            {t === "keywords" ? "Keyword tracking" : t === "competitors" ? "Competitors" : "Keyword ideas"}
          </button>
        ))}
      </div>

      {tab === "keywords" && <KeywordsTab locations={overview?.locations ?? []} onChange={loadOverview} />}
      {tab === "competitors" && <CompetitorsTab locations={overview?.locations ?? []} onChange={loadOverview} />}
      {tab === "ideas" && <IdeasTab />}
    </div>
  );
}

function KeywordsTab({ locations, onChange }: { locations: { id: string; name: string }[]; onChange: () => void }) {
  const [keywords, setKeywords] = useState<SeoKeywordItem[]>([]);
  const [form, setForm] = useState({ keyword: "", city: "", location_id: "" });
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [result, setResult] = useState<{ kw: string; r: SeoRankResult } | null>(null);

  const load = useCallback(() => {
    const token = getToken();
    if (!token) return;
    api.seo.keywords(token).then((r) => setKeywords(r.keywords)).catch((e) => setErr((e as ApiError).message));
  }, []);
  useEffect(() => { load(); }, [load]);

  async function add(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token || !form.keyword.trim()) return;
    setBusy(true); setErr(null);
    try {
      await api.seo.addKeyword(token, { keyword: form.keyword.trim(), city: form.city || undefined, location_id: form.location_id || undefined });
      setForm({ keyword: "", city: "", location_id: "" });
      load(); onChange();
    } catch (e2) { setErr((e2 as ApiError).message); } finally { setBusy(false); }
  }

  async function check(id: string, kw: string) {
    const token = getToken();
    if (!token) return;
    setBusy(true);
    try { const r = await api.seo.checkKeyword(token, id); setResult({ kw, r: r.result }); load(); onChange(); }
    catch (e) { setErr((e as ApiError).message); } finally { setBusy(false); }
  }

  async function remove(id: string) {
    const token = getToken();
    if (!token) return;
    try { await api.seo.deleteKeyword(token, id); load(); onChange(); } catch (e) { setErr((e as ApiError).message); }
  }

  return (
    <div>
      {err && <div className="error" style={{ marginBottom: 12 }}>{err}</div>}
      <form onSubmit={add} className="panel" style={{ padding: 12, marginBottom: 14, display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
        <input placeholder="Keyword e.g. best cafe pune" value={form.keyword} onChange={(e) => setForm((f) => ({ ...f, keyword: e.target.value }))} required
          style={{ flex: 2, minWidth: 180, padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
        <input placeholder="City (optional)" value={form.city} onChange={(e) => setForm((f) => ({ ...f, city: e.target.value }))}
          style={{ flex: 1, minWidth: 120, padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
        <select value={form.location_id} onChange={(e) => setForm((f) => ({ ...f, location_id: e.target.value }))}
          style={{ padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
          <option value="">No location</option>
          {locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
        </select>
        <button className="btn" disabled={busy} style={{ width: "auto", padding: "9px 18px" }}>+ Track</button>
      </form>

      {result && (
        <div className="panel" style={{ padding: 14, marginBottom: 14, borderLeft: "3px solid var(--accent)" }}>
          <strong>Rank check — {result.kw}</strong> <span className="muted" style={{ fontSize: 12 }}>({result.r.source})</span>
          <div style={{ margin: "6px 0", fontSize: 14 }}><b>{result.r.rank ?? "No numeric rank"}</b> — {result.r.summary}</div>
          {result.r.tips?.length > 0 && <ul style={{ margin: "6px 0 0 18px", fontSize: 13 }}>{result.r.tips.map((t, i) => <li key={i}>{t}</li>)}</ul>}
        </div>
      )}

      <div style={{ display: "grid", gap: 8 }}>
        {keywords.map((k) => (
          <div key={k.id} className="panel" style={{ padding: "12px 16px", display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
            <div style={{ flex: 1, minWidth: 180 }}>
              <strong style={{ fontSize: 14 }}>{k.keyword}</strong>
              <div className="muted" style={{ fontSize: 12.5 }}>
                {[k.city, k.location?.name].filter(Boolean).join(" · ") || "No location"} · {k.checks} check{k.checks === 1 ? "" : "s"}
                {k.last_checked_at ? ` · last ${new Date(k.last_checked_at).toLocaleDateString()}` : ""}
              </div>
            </div>
            <span style={{ fontSize: 13, fontWeight: 700, color: "var(--accent)" }}>{k.latest_rank ?? "—"}</span>
            <button className="btn-mini" disabled={busy} onClick={() => check(k.id, k.keyword)}>Check rank</button>
            <button className="btn-mini" onClick={() => remove(k.id)}>✕</button>
          </div>
        ))}
        {keywords.length === 0 && <div className="panel" style={{ padding: 24, textAlign: "center" }}><span className="muted">No keywords tracked yet. Add one above.</span></div>}
      </div>
    </div>
  );
}

function CompetitorsTab({ locations, onChange }: { locations: { id: string; name: string }[]; onChange: () => void }) {
  const [list, setList] = useState<SeoCompetitorItem[]>([]);
  const [form, setForm] = useState({ name: "", city: "", location_id: "" });
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [result, setResult] = useState<{ name: string; r: SeoCompetitorResult } | null>(null);

  const load = useCallback(() => {
    const token = getToken();
    if (!token) return;
    api.seo.competitors(token).then((r) => setList(r.competitors)).catch((e) => setErr((e as ApiError).message));
  }, []);
  useEffect(() => { load(); }, [load]);

  async function add(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token || !form.name.trim()) return;
    setBusy(true); setErr(null);
    try {
      await api.seo.addCompetitor(token, { name: form.name.trim(), city: form.city || undefined, location_id: form.location_id || undefined });
      setForm({ name: "", city: "", location_id: "" }); load(); onChange();
    } catch (e2) { setErr((e2 as ApiError).message); } finally { setBusy(false); }
  }
  async function analyze(id: string, name: string) {
    const token = getToken();
    if (!token) return;
    setBusy(true);
    try { const r = await api.seo.analyzeCompetitor(token, id); setResult({ name, r: r.result }); load(); }
    catch (e) { setErr((e as ApiError).message); } finally { setBusy(false); }
  }
  async function remove(id: string) {
    const token = getToken();
    if (!token) return;
    try { await api.seo.deleteCompetitor(token, id); load(); onChange(); } catch (e) { setErr((e as ApiError).message); }
  }

  return (
    <div>
      {err && <div className="error" style={{ marginBottom: 12 }}>{err}</div>}
      <form onSubmit={add} className="panel" style={{ padding: 12, marginBottom: 14, display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
        <input placeholder="Competitor name" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required
          style={{ flex: 2, minWidth: 180, padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
        <input placeholder="City (optional)" value={form.city} onChange={(e) => setForm((f) => ({ ...f, city: e.target.value }))}
          style={{ flex: 1, minWidth: 120, padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
        <select value={form.location_id} onChange={(e) => setForm((f) => ({ ...f, location_id: e.target.value }))}
          style={{ padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
          <option value="">No location</option>
          {locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
        </select>
        <button className="btn" disabled={busy} style={{ width: "auto", padding: "9px 18px" }}>+ Add</button>
      </form>

      {result && (
        <div className="panel" style={{ padding: 16, marginBottom: 14, borderLeft: "3px solid var(--accent)" }}>
          <strong>Analysis vs {result.name}</strong> <span className="muted" style={{ fontSize: 12 }}>({result.r.source})</span>
          <p style={{ fontSize: 14, margin: "6px 0" }}>{result.r.summary}</p>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px,1fr))", gap: 12, marginTop: 8 }}>
            <ResultList title="Strengths" items={result.r.strengths} color="#0a7d47" />
            <ResultList title="Gaps" items={result.r.gaps} color="#c53030" />
            <ResultList title="Actions" items={result.r.actions} color="var(--accent)" />
          </div>
        </div>
      )}

      <div style={{ display: "grid", gap: 8 }}>
        {list.map((c) => (
          <div key={c.id} className="panel" style={{ padding: "12px 16px", display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
            <div style={{ flex: 1, minWidth: 180 }}>
              <strong style={{ fontSize: 14 }}>{c.name}</strong>
              <div className="muted" style={{ fontSize: 12.5 }}>{[c.city, c.location?.name].filter(Boolean).join(" · ") || "—"}</div>
              {c.analysis && <div style={{ fontSize: 12.5, marginTop: 4 }}>{c.analysis}</div>}
            </div>
            <button className="btn-mini" disabled={busy} onClick={() => analyze(c.id, c.name)}>Analyze</button>
            <button className="btn-mini" onClick={() => remove(c.id)}>✕</button>
          </div>
        ))}
        {list.length === 0 && <div className="panel" style={{ padding: 24, textAlign: "center" }}><span className="muted">No competitors added yet.</span></div>}
      </div>
    </div>
  );
}

function IdeasTab() {
  const [form, setForm] = useState({ business: "", city: "", industry: "" });
  const [groups, setGroups] = useState<{ theme: string; keywords: string[] }[] | null>(null);
  const [source, setSource] = useState<string>("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function generate(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setBusy(true); setErr(null);
    try { const r = await api.seo.generate(token, { business: form.business, city: form.city, industry: form.industry || undefined }); setGroups(r.groups); setSource(r.source); }
    catch (e2) { setErr((e2 as ApiError).message); } finally { setBusy(false); }
  }

  return (
    <div>
      {err && <div className="error" style={{ marginBottom: 12 }}>{err}</div>}
      <form onSubmit={generate} className="panel" style={{ padding: 12, marginBottom: 14, display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
        <input placeholder="Business name" value={form.business} onChange={(e) => setForm((f) => ({ ...f, business: e.target.value }))} required
          style={{ flex: 1, minWidth: 150, padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
        <input placeholder="City" value={form.city} onChange={(e) => setForm((f) => ({ ...f, city: e.target.value }))} required
          style={{ flex: 1, minWidth: 120, padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
        <input placeholder="Industry (optional)" value={form.industry} onChange={(e) => setForm((f) => ({ ...f, industry: e.target.value }))}
          style={{ flex: 1, minWidth: 120, padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
        <button className="btn" disabled={busy} style={{ width: "auto", padding: "9px 18px" }}>{busy ? "Generating…" : "Generate"}</button>
      </form>
      {groups && (
        <div>
          <div className="muted" style={{ fontSize: 12.5, marginBottom: 8 }}>Source: {source}</div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(240px,1fr))", gap: 12 }}>
            {groups.map((g, i) => (
              <div key={i} className="panel" style={{ padding: 14 }}>
                <strong style={{ fontSize: 14 }}>{g.theme}</strong>
                <div style={{ display: "flex", flexWrap: "wrap", gap: 6, marginTop: 8 }}>
                  {g.keywords.map((k, j) => <span key={j} style={{ background: "var(--bg)", padding: "3px 9px", borderRadius: 999, fontSize: 12.5 }}>{k}</span>)}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function ResultList({ title, items, color }: { title: string; items: string[]; color: string }) {
  return (
    <div>
      <div style={{ fontSize: 12, fontWeight: 700, color, marginBottom: 4 }}>{title}</div>
      <ul style={{ margin: "0 0 0 16px", fontSize: 13, display: "grid", gap: 2 }}>{(items ?? []).map((t, i) => <li key={i}>{t}</li>)}</ul>
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
