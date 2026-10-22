"use client";

import { useCallback, useEffect, useState } from "react";
import { useUser } from "@/lib/user-context";
import { AccessDenied, hasFeature } from "@/components/PlanGate";
import { PageHeader } from "@/components/PageHeader";
import {
  api, ApiError, GbpConnection, GbpOverview, GbpReviewItem,
  GbpPostItem, GbpPhotoItem, GbpInsights, GbpHealth, GbpLocationItem, GbpOptimizeResult,
} from "@/lib/api";
import { getToken } from "@/lib/auth";

const stars = (n: number) => "★★★★★".slice(0, n) + "☆☆☆☆☆".slice(0, 5 - n);
const POST_TYPES = ["STANDARD", "EVENT", "OFFER", "ALERT"];
const CTA_TYPES = ["NONE", "LEARN_MORE", "CALL", "BOOK", "ORDER", "SIGN_UP", "SHOP"];
const PHOTO_CATS = ["ADDITIONAL", "COVER", "PROFILE", "LOGO", "EXTERIOR", "INTERIOR", "PRODUCT", "AT_WORK", "FOOD_AND_DRINK"];

const TABS = [
  { k: "overview", label: "Overview", icon: "📊" },
  { k: "reviews", label: "Reviews", icon: "⭐" },
  { k: "posts", label: "Posts", icon: "📝" },
  { k: "photos", label: "Photos", icon: "🖼️" },
  { k: "insights", label: "Insights", icon: "📈" },
  { k: "health", label: "Health Score", icon: "🩺" },
];

export default function GbpPage() {
  const user = useUser();
  if (!hasFeature(user, "dash_gbp")) return <AccessDenied dashboard="Google Reviews / GBP" />;
  return <GbpDashboard />;
}

function GbpDashboard() {
  const [tab, setTab] = useState("overview");
  const [overview, setOverview] = useState<GbpOverview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const loadOverview = useCallback(() => {
    const token = getToken();
    if (!token) return;
    api.gbp.overview(token).then(setOverview).catch((e) => setError((e as ApiError).message));
  }, []);
  useEffect(() => { loadOverview(); }, [loadOverview]);

  async function sync() {
    const token = getToken();
    if (!token) return;
    setBusy(true);
    try { const r = await api.gbp.sync(token); alert(r.message); loadOverview(); }
    catch (e) { alert((e as ApiError).message); } finally { setBusy(false); }
  }

  const locations = overview?.locations ?? [];

  return (
    <div>
      <PageHeader title="Google Reviews / GBP" subtitle="Reviews, posts, photos, insights and a health score for your Google Business Profile."
        action={<button className="btn-mini" disabled={busy} onClick={sync}>⟳ Sync from Google</button>} />

      {error && <div className="error" style={{ marginBottom: 12 }}>{error}</div>}
      <GbpConnectBar />

      <div style={{ display: "flex", gap: 6, borderBottom: "1px solid var(--border)", marginBottom: 16, flexWrap: "wrap" }}>
        {TABS.map((t) => (
          <button key={t.k} onClick={() => setTab(t.k)}
            style={{ border: "none", background: "none", padding: "10px 14px", cursor: "pointer", fontSize: 14, fontWeight: 600,
              color: tab === t.k ? "var(--accent)" : "var(--muted)",
              borderBottom: `2px solid ${tab === t.k ? "var(--accent)" : "transparent"}`, marginBottom: -1 }}>
            <span style={{ marginRight: 6 }}>{t.icon}</span>{t.label}
          </button>
        ))}
      </div>

      {tab === "overview" && <OverviewTab overview={overview} busy={busy} onSync={sync} onGoTab={setTab} />}
      {tab === "reviews" && <ReviewsTab overview={overview} />}
      {tab === "posts" && <PostsTab locations={locations} />}
      {tab === "photos" && <PhotosTab locations={locations} />}
      {tab === "insights" && <InsightsTab locations={locations} />}
      {tab === "health" && <HealthTab />}
    </div>
  );
}

/* ---------------- Overview (rich dashboard) ---------------- */
const impactColor = (p: string) =>
  p === "critical" ? { fg: "#c53030", bg: "#fdecec" }
    : p === "high" ? { fg: "#b45309", bg: "#fef3e2" }
    : p === "medium" ? { fg: "#0e7490", bg: "#e6f8fb" }
    : { fg: "var(--muted)", bg: "var(--bg)" };

function Donut({ segments, size = 140, thickness = 20, children }:
  { segments: { value: number; color: string }[]; size?: number; thickness?: number; children?: React.ReactNode }) {
  const r = (size - thickness) / 2;
  const c = size / 2;
  const circ = 2 * Math.PI * r;
  const total = segments.reduce((a, s) => a + s.value, 0);
  let offset = 0;
  return (
    <div style={{ position: "relative", width: size, height: size, flexShrink: 0 }}>
      <svg width={size} height={size}>
        <circle cx={c} cy={c} r={r} fill="none" stroke="var(--border)" strokeWidth={thickness} />
        {total > 0 && segments.map((s, i) => {
          if (s.value <= 0) return null;
          const len = (s.value / total) * circ;
          const node = (
            <circle key={i} cx={c} cy={c} r={r} fill="none" stroke={s.color} strokeWidth={thickness} strokeLinecap="butt"
              strokeDasharray={`${len} ${circ - len}`} strokeDashoffset={-offset} transform={`rotate(-90 ${c} ${c})`} />
          );
          offset += len;
          return node;
        })}
      </svg>
      <div style={{ position: "absolute", inset: 0, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", textAlign: "center", pointerEvents: "none" }}>{children}</div>
    </div>
  );
}

function LineChart({ labels, values, color, max }:
  { labels: string[]; values: (number | null)[]; color: string; max?: number }) {
  const W = 320, H = 150, padX = 26, padY = 20;
  const hi = max ?? Math.max(1, ...values.map((v) => v ?? 0));
  const n = values.length;
  const px = (i: number) => padX + (i / Math.max(1, n - 1)) * (W - padX * 2);
  const py = (v: number) => H - padY - (v / hi) * (H - padY * 2);
  const pts = values.map((v, i) => (v == null ? null : ([px(i), py(v)] as [number, number]))).filter(Boolean) as [number, number][];
  const line = pts.map((p, i) => `${i ? "L" : "M"}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(" ");
  const area = pts.length ? `${line} L${pts[pts.length - 1][0].toFixed(1)},${H - padY} L${pts[0][0].toFixed(1)},${H - padY} Z` : "";
  const gid = `g-${color.replace(/[^a-z0-9]/gi, "")}`;
  return (
    <svg viewBox={`0 0 ${W} ${H}`} style={{ width: "100%", height: 160 }} preserveAspectRatio="none">
      <defs>
        <linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity="0.22" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      {[0, 0.5, 1].map((f) => (
        <line key={f} x1={padX} x2={W - padX} y1={padY + f * (H - padY * 2)} y2={padY + f * (H - padY * 2)} stroke="var(--border)" strokeWidth="1" />
      ))}
      {area && <path d={area} fill={`url(#${gid})`} />}
      {line && <path d={line} fill="none" stroke={color} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />}
      {pts.map((p, i) => <circle key={i} cx={p[0]} cy={p[1]} r="2.5" fill={color} />)}
      {labels.map((l, i) => (
        <text key={i} x={px(i)} y={H - 4} fontSize="9" fill="var(--muted)" textAnchor="middle">{l}</text>
      ))}
    </svg>
  );
}

function StackedBars({ labels, series, max }:
  { labels: string[]; series: { values: number[]; color: string }[]; max?: number }) {
  const totals = labels.map((_, i) => series.reduce((a, s) => a + (s.values[i] ?? 0), 0));
  const hi = max ?? Math.max(1, ...totals);
  return (
    <div style={{ display: "flex", alignItems: "flex-end", gap: 4, height: 150, paddingTop: 6 }}>
      {labels.map((l, i) => (
        <div key={i} style={{ flex: 1, display: "flex", flexDirection: "column", alignItems: "center", height: "100%", justifyContent: "flex-end" }}>
          <div style={{ width: "100%", display: "flex", flexDirection: "column-reverse", flex: 1, justifyContent: "flex-start" }}>
            {series.map((s, si) => {
              const v = s.values[i] ?? 0;
              if (v <= 0) return null;
              return <div key={si} title={`${v}`} style={{ height: `${(v / hi) * 100}%`, background: s.color, minHeight: 2, borderRadius: si === series.length - 1 ? "3px 3px 0 0" : 0 }} />;
            })}
          </div>
          <span style={{ fontSize: 9, color: "var(--muted)", marginTop: 4 }}>{l}</span>
        </div>
      ))}
    </div>
  );
}

const STAR_COLORS: Record<number, string> = { 1: "#ef4444", 2: "#f97316", 3: "#f59e0b", 4: "#84cc16", 5: "#22c55e" };

function OverviewTab({ overview, busy, onSync, onGoTab }:
  { overview: GbpOverview | null; busy: boolean; onSync: () => void; onGoTab: (k: string) => void }) {
  if (!overview) return <div className="panel" style={{ padding: 28, textAlign: "center" }}><span className="muted">Loading your dashboard…</span></div>;

  const s = overview.stats;
  const sum = overview.summary;
  const health = overview.health;
  const charts = overview.charts;
  const recent = overview.recent_reviews ?? [];
  const locs = overview.locations ?? [];
  const total = s.total_reviews;
  const avg = s.average_rating;
  const replied = charts?.replied ?? s.replied ?? 0;
  const unreplied = charts?.unreplied ?? s.unreplied;
  const replyPct = total ? Math.round((replied / total) * 100) : 0;
  const dist = charts?.star_dist ?? s.rating_distribution ?? {};
  const score = health?.score ?? 0;
  const scoreColor = score >= 75 ? "#22c55e" : score >= 50 ? "#f59e0b" : "#ef4444";
  const scoreLabel = health?.grade ?? (score >= 75 ? "Healthy" : score >= 50 ? "Needs work" : "Critical");

  const hour = new Date().getHours();
  const greet = hour < 12 ? "Morning" : hour < 17 ? "Afternoon" : "Evening";
  const synced = sum?.last_synced_at ? new Date(sum.last_synced_at).toLocaleDateString() : null;

  const moduleCards = [
    { label: "Reviews", icon: "⭐", color: "#f59e0b", value: total, sub: `${avg.toFixed(1)}★ avg`, go: "reviews" },
    { label: "Google Posts", icon: "📝", color: "#4c57d8", value: sum?.posts ?? 0, sub: "content published", go: "posts" },
    { label: "Photos", icon: "📷", color: "#ec4899", value: sum?.photos ?? 0, sub: "uploaded", go: "photos" },
    { label: "Social Media", icon: "💬", color: "#8b5cf6", value: sum?.social ?? 0, sub: "across platforms", go: null },
    { label: "Leads", icon: "🎯", color: "#22c55e", value: sum?.leads ?? 0, sub: `${sum?.leads_converted ?? 0} converted`, go: null },
    { label: "Insights", icon: "📊", color: "#38bdf8", value: s.locations, sub: "performance data", go: "insights" },
  ];

  return (
    <div style={{ display: "grid", gap: 16 }}>
      {/* Hero */}
      <div className="gbp-hero">
        <div>
          <div className="gbp-hero-title">Good {greet} 👋</div>
          <div className="gbp-hero-sub">
            {new Date().toLocaleDateString(undefined, { weekday: "long", year: "numeric", month: "long", day: "numeric" })}
            {sum?.plan ? ` · ${sum.plan} plan` : ""}
            {total > 0 ? <> · <span className="gbp-live">● Live</span> Google Business Profile{synced ? ` · synced ${synced}` : ""}</> : " · Connect Google for live data"}
          </div>
        </div>
        <button className="gbp-hero-btn" disabled={busy} onClick={onSync}>⟳ {busy ? "Syncing…" : "Sync Live Data"}</button>
      </div>

      {/* Business health cockpit */}
      <div className="gbp-cockpit">
        <div style={{ textAlign: "center" }}>
          <Donut size={150} thickness={12} segments={[{ value: score, color: scoreColor }, { value: 100 - score, color: "var(--border)" }]}>
            <div style={{ fontSize: 40, fontWeight: 800, color: scoreColor, lineHeight: 1 }}>{score}</div>
            <div style={{ fontSize: 11, fontWeight: 700, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--muted)", marginTop: 2 }}>{scoreLabel}</div>
          </Donut>
          <div style={{ fontWeight: 800, fontSize: 16, marginTop: 10 }}>Business Health</div>
          <div className="muted" style={{ fontSize: 11.5, marginTop: 2, maxWidth: 190 }}>Reflects reviews, posts, photos, replies &amp; connections</div>
        </div>
        <div className="gbp-sub-grid">
          {(health?.sub_scores ?? []).map((sub) => {
            const pill = sub.score >= 70 ? { fg: "#0a7d47", bg: "#e7f7ef" } : sub.score >= 40 ? { fg: "#b45309", bg: "#fef3e2" } : { fg: "#c53030", bg: "#fdecec" };
            return (
              <div key={sub.key} className="gbp-sub-tile">
                <div style={{ display: "flex", alignItems: "center", gap: 6, marginBottom: 8 }}>
                  <span style={{ fontSize: 14 }}>{sub.icon}</span>
                  <span style={{ fontSize: 12, fontWeight: 700, flex: 1, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{sub.label}</span>
                  <span style={{ fontSize: 10.5, fontWeight: 700, padding: "2px 8px", borderRadius: 999, color: pill.fg, background: pill.bg }}>{sub.score}%</span>
                </div>
                <div style={{ height: 5, borderRadius: 4, background: "var(--border)", overflow: "hidden", marginBottom: 6 }}>
                  <div style={{ height: "100%", width: `${Math.min(100, sub.score)}%`, background: sub.color ?? "var(--accent)", borderRadius: 4 }} />
                </div>
                <div className="muted" style={{ fontSize: 11, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{sub.detail}</div>
              </div>
            );
          })}
        </div>
      </div>

      {/* What to do next */}
      {health?.actions && health.actions.length > 0 && (
        <div className="panel">
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 12, flexWrap: "wrap", marginBottom: 12 }}>
            <div>
              <h2 style={{ fontSize: 17, margin: 0 }}>What to Do Next</h2>
              <div className="muted" style={{ fontSize: 12, marginTop: 2 }}>Prioritized actions to grow your business health score</div>
            </div>
            <button className="btn-mini" onClick={() => onGoTab("health")}>⚡ One-Click Optimize</button>
          </div>
          <div style={{ display: "grid", gap: 6 }}>
            {health.actions.map((a, i) => {
              const ic = impactColor(a.priority);
              return (
                <div key={i} className="gbp-dn-row">
                  <span className="gbp-dn-rank">{i + 1}</span>
                  <span style={{ fontSize: 18, flexShrink: 0 }}>{a.icon ?? "•"}</span>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 13.5, fontWeight: 700 }}>{a.title ?? a.text}</div>
                    {a.why && <div className="muted" style={{ fontSize: 11.5, marginTop: 2 }}>{a.why}</div>}
                  </div>
                  <span style={{ fontSize: 10.5, fontWeight: 700, padding: "3px 10px", borderRadius: 999, color: ic.fg, background: ic.bg, whiteSpace: "nowrap", flexShrink: 0, textTransform: "capitalize" }}>{a.priority}</span>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Module summary cards */}
      <div className="gbp-modules">
        {moduleCards.map((m) => (
          <div key={m.label} className="gbp-module-card" onClick={m.go ? () => onGoTab(m.go!) : undefined} style={{ cursor: m.go ? "pointer" : "default" }}>
            <div style={{ display: "flex", alignItems: "center", gap: 6, marginBottom: 10 }}>
              <span style={{ width: 28, height: 28, borderRadius: 8, display: "grid", placeItems: "center", fontSize: 14, background: `${m.color}22`, color: m.color }}>{m.icon}</span>
              <span style={{ fontSize: 11, fontWeight: 700, color: "var(--muted)", textTransform: "uppercase", letterSpacing: ".03em" }}>{m.label}</span>
            </div>
            <div style={{ fontSize: 22, fontWeight: 800, lineHeight: 1 }}>{m.value}</div>
            <div className="muted" style={{ fontSize: 11, marginTop: 4 }}>{m.sub}</div>
            {m.go && <div style={{ fontSize: 11, fontWeight: 700, color: "var(--accent)", marginTop: 8 }}>Open →</div>}
          </div>
        ))}
      </div>

      {/* KPI cards */}
      <div className="gbp-kpis">
        <Kpi icon="🏢" grad="linear-gradient(135deg,#4c57d8,#6b8afd)" label="Business Accounts" value={Math.max(1, locs.length ? 1 : 0) || 1} sub="Google My Business" />
        <Kpi icon="📍" grad="linear-gradient(135deg,#22c55e,#4ade80)" label="Business Locations" value={s.locations} sub="Active locations" />
        <Kpi icon="⭐" grad="linear-gradient(135deg,#f59e0b,#fbbf24)" label="Total Reviews" value={total} sub={`Avg ${avg.toFixed(1)} / 5`} />
        <Kpi icon="⚡" grad="linear-gradient(135deg,#8b5cf6,#a78bfa)" label="AI Credits" value={(sum?.credit_balance ?? 0).toLocaleString("en-IN")} sub="Available for AI" valueColor="#8b5cf6" />
      </div>

      {/* Analytics row 1: rating · directory · replies */}
      <div className="gbp-grid-3">
        <div className="panel">
          <h2 style={{ fontSize: 15 }}>Average Rating and Breakdown</h2>
          <div style={{ display: "flex", gap: 16, alignItems: "center", flexWrap: "wrap" }}>
            <Donut size={130} thickness={16} segments={[{ value: avg, color: "#4c57d8" }, { value: Math.max(0, 5 - avg), color: "var(--border)" }]}>
              <div style={{ fontSize: 26, fontWeight: 800 }}>{avg.toFixed(2)}</div>
              <div className="muted" style={{ fontSize: 11 }}>{total} Reviews</div>
            </Donut>
            <div style={{ flex: 1, minWidth: 140, display: "grid", gap: 7 }}>
              {[5, 4, 3, 2, 1].map((star) => {
                const cnt = dist[star] ?? 0;
                const pct = total ? Math.round((cnt / total) * 100) : 0;
                return (
                  <div key={star} style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12 }}>
                    <span style={{ width: 28, fontWeight: 600 }}>{star} <span style={{ color: "#f59e0b" }}>★</span></span>
                    <div style={{ flex: 1, height: 8, background: "var(--bg)", borderRadius: 6, overflow: "hidden" }}>
                      <div style={{ width: `${pct}%`, height: "100%", background: "var(--brand-grad)", borderRadius: 6 }} />
                    </div>
                    <span style={{ fontSize: 11.5, fontWeight: 600, whiteSpace: "nowrap" }}>{cnt} <span className="muted" style={{ fontWeight: 500 }}>({pct}%)</span></span>
                  </div>
                );
              })}
            </div>
          </div>
        </div>

        <div className="panel">
          <h2 style={{ fontSize: 15 }}>Reviews by Directory</h2>
          <div style={{ display: "flex", gap: 16, alignItems: "center", flexWrap: "wrap" }}>
            <Donut size={130} thickness={16} segments={[
              { value: charts?.directory.Google ?? total, color: "#4c57d8" },
              { value: charts?.directory.Facebook ?? 0, color: "#f59e0b" },
              { value: charts?.directory.Others ?? 0, color: "#ef4444" },
            ]}>
              <div style={{ fontSize: 24, fontWeight: 800 }}>{total}</div>
              <div className="muted" style={{ fontSize: 11 }}>Total</div>
            </Donut>
            <div style={{ flex: 1, minWidth: 120, display: "grid", gap: 10 }}>
              <Legend color="#4c57d8" label="Google" value={charts?.directory.Google ?? total} />
              <Legend color="#f59e0b" label="Facebook" value={charts?.directory.Facebook ?? 0} />
              <Legend color="#ef4444" label="Others" value={charts?.directory.Others ?? 0} />
            </div>
          </div>
        </div>

        <div className="panel">
          <h2 style={{ fontSize: 15 }}>Replies and Breakdown</h2>
          <div style={{ display: "flex", gap: 16, alignItems: "center", flexWrap: "wrap" }}>
            <Donut size={130} thickness={16} segments={[{ value: replied, color: "#4c57d8" }, { value: unreplied, color: "#c7d0e8" }]}>
              <div style={{ fontSize: 24, fontWeight: 800 }}>{replyPct}%</div>
              <div className="muted" style={{ fontSize: 11 }}>Replied</div>
            </Donut>
            <div style={{ flex: 1, minWidth: 120, display: "grid", gap: 10 }}>
              <Legend color="#4c57d8" label="Replied" value={replied} />
              <Legend color="#c7d0e8" label="Unreplied" value={unreplied} />
            </div>
          </div>
        </div>
      </div>

      {/* Analytics row 2: response time · breakdown · trend */}
      {charts && (
        <div className="gbp-grid-3">
          <div className="panel">
            <h2 style={{ fontSize: 15 }}>Response Time on Avg</h2>
            <div style={{ fontSize: 22, fontWeight: 800, marginBottom: 6 }}>{charts.avg_response || "—"} <span className="muted" style={{ fontSize: 12, fontWeight: 500 }}>days avg</span></div>
            <LineChart labels={charts.trend_labels} values={charts.trend_resp} color="#4c57d8" />
          </div>
          <div className="panel">
            <h2 style={{ fontSize: 15 }}>Ratings &amp; Reviews Breakdown</h2>
            <div style={{ fontSize: 22, fontWeight: 800, marginBottom: 6 }}>{total} <span className="muted" style={{ fontSize: 12, fontWeight: 500 }}>total reviews</span></div>
            <StackedBars labels={charts.trend_labels} series={[5, 4, 3, 2, 1].map((st) => ({ values: charts.breakdown[st] ?? [], color: STAR_COLORS[st] }))} />
          </div>
          <div className="panel">
            <h2 style={{ fontSize: 15 }}>Avg Rating Trend</h2>
            <div style={{ fontSize: 22, fontWeight: 800, marginBottom: 6 }}>{avg.toFixed(1)} <span className="muted" style={{ fontSize: 12, fontWeight: 500 }}>current avg</span></div>
            <LineChart labels={charts.trend_labels} values={charts.trend_avg} color="#8b5cf6" max={5} />
          </div>
        </div>
      )}

      {/* Accounts & locations */}
      <div className="panel">
        <h2 style={{ fontSize: 15 }}>Your Accounts &amp; Locations</h2>
        {locs.length === 0 ? (
          <p className="muted" style={{ fontSize: 13 }}>No locations yet. Connect Google and Sync to pull your business locations.</p>
        ) : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(230px,1fr))", gap: 12 }}>
            {locs.map((l) => (
              <div key={l.id} className="gbp-acct">
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 10 }}>
                  <div style={{ width: 38, height: 38, borderRadius: 11, background: "#eef1ff", color: "var(--accent)", display: "grid", placeItems: "center", fontWeight: 700 }}>{(l.name ?? "?").slice(0, 1).toUpperCase()}</div>
                  <span style={{ fontSize: 10.5, fontWeight: 700, padding: "2px 9px", borderRadius: 999, background: l.is_verified ? "#e7f7ef" : "var(--bg)", color: l.is_verified ? "#0a7d47" : "var(--muted)" }}>{l.is_verified ? "Live" : "Synced"}</span>
                </div>
                <div style={{ fontWeight: 700, fontSize: 14, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{l.name}</div>
                <div className="muted" style={{ fontSize: 12, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{l.address ?? "—"}</div>
                <div style={{ display: "flex", gap: 16, marginTop: 12, paddingTop: 12, borderTop: "1px solid var(--border)" }}>
                  <div><b style={{ fontSize: 16 }}>{(l.avg_rating ?? l.rating ?? 0).toFixed(1)}</b><div className="muted" style={{ fontSize: 11 }}>★ Rating</div></div>
                  <div><b style={{ fontSize: 16 }}>{l.total_reviews ?? l.review_count ?? 0}</b><div className="muted" style={{ fontSize: 11 }}>Reviews</div></div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Recent reviews + quick actions */}
      <div className="gbp-grid-2">
        <div className="panel" style={{ padding: 0, overflow: "hidden" }}>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "14px 18px", borderBottom: "1px solid var(--border)" }}>
            <h2 style={{ fontSize: 15, margin: 0 }}>Recent Reviews</h2>
            <button className="btn-mini" onClick={() => onGoTab("reviews")}>All →</button>
          </div>
          {recent.length === 0 ? (
            <div style={{ padding: 24, textAlign: "center" }}><span className="muted">No reviews yet. Sync your Google Business Profile.</span></div>
          ) : recent.map((r, i) => (
            <div key={r.id} style={{ display: "flex", gap: 10, padding: "12px 18px", borderTop: i ? "1px solid var(--border)" : "none" }}>
              <div style={{ width: 34, height: 34, borderRadius: "50%", flexShrink: 0, display: "grid", placeItems: "center", fontSize: 12, fontWeight: 700,
                background: r.rating >= 4 ? "#e7f7ef" : r.rating >= 3 ? "#fef3e2" : "#fdecec", color: r.rating >= 4 ? "#0a7d47" : r.rating >= 3 ? "#b45309" : "#c53030" }}>
                {(r.author_name ?? "?").slice(0, 1).toUpperCase()}
              </div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
                  <strong style={{ fontSize: 13 }}>{r.author_name ?? "Anonymous"}</strong>
                  <span className="muted" style={{ fontSize: 11 }}>{r.reviewed_at ? new Date(r.reviewed_at).toLocaleDateString() : ""}</span>
                </div>
                <div style={{ fontSize: 12, color: "#f59e0b", marginTop: 2 }}>{stars(r.rating)} <span className="muted">{r.rating}/5</span></div>
                {r.comment && <div className="muted" style={{ fontSize: 12.5, marginTop: 4, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{r.comment}</div>}
              </div>
            </div>
          ))}
        </div>
        <div style={{ display: "grid", gap: 12, alignContent: "start" }}>
          <QuickAction icon="⚡" grad="linear-gradient(135deg,#f59e0b,#fbbf24)" title="One-Click Optimize" sub="AI handles pending tasks" onClick={() => onGoTab("health")} />
          <QuickAction icon="🩺" grad="linear-gradient(135deg,#8b5cf6,#a78bfa)" title="Health Score" sub="Score profile health" onClick={() => onGoTab("health")} />
          <QuickAction icon="📝" grad="linear-gradient(135deg,#22c55e,#4ade80)" title="Google Posts" sub="Manage posts & photos" onClick={() => onGoTab("posts")} />
          <QuickAction icon="📈" grad="linear-gradient(135deg,#4c57d8,#6b8afd)" title="Insights" sub="See performance data" onClick={() => onGoTab("insights")} />
        </div>
      </div>
    </div>
  );
}

function Kpi({ icon, grad, label, value, sub, valueColor }:
  { icon: string; grad: string; label: string; value: React.ReactNode; sub: string; valueColor?: string }) {
  return (
    <div className="panel" style={{ padding: "16px 18px" }}>
      <div style={{ width: 42, height: 42, borderRadius: 12, display: "grid", placeItems: "center", color: "#fff", fontSize: 18, background: grad, marginBottom: 12 }}>{icon}</div>
      <div style={{ fontSize: 11.5, fontWeight: 600, textTransform: "uppercase", letterSpacing: ".03em", color: "var(--muted)" }}>{label}</div>
      <div style={{ fontSize: 28, fontWeight: 800, lineHeight: 1, marginTop: 6, color: valueColor }}>{value}</div>
      <div className="muted" style={{ fontSize: 12, marginTop: 4 }}>{sub}</div>
    </div>
  );
}

function Legend({ color, label, value }: { color: string; label: string; value: number }) {
  return (
    <div style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13, color: "var(--muted)" }}>
      <span style={{ width: 10, height: 10, borderRadius: "50%", background: color, flexShrink: 0 }} />
      {label} <b style={{ marginLeft: "auto", color: "var(--text)" }}>{value}</b>
    </div>
  );
}

function QuickAction({ icon, grad, title, sub, onClick }: { icon: string; grad: string; title: string; sub: string; onClick: () => void }) {
  return (
    <div className="gbp-quick" onClick={onClick}>
      <div style={{ width: 44, height: 44, borderRadius: 12, display: "grid", placeItems: "center", color: "#fff", fontSize: 20, background: grad, flexShrink: 0 }}>{icon}</div>
      <div><div style={{ fontWeight: 700, fontSize: 14 }}>{title}</div><div className="muted" style={{ fontSize: 12, marginTop: 2 }}>{sub}</div></div>
    </div>
  );
}

/* ---------------- Reviews ---------------- */
function ReviewsTab({ overview }: { overview: GbpOverview | null }) {
  const [reviews, setReviews] = useState<GbpReviewItem[]>([]);
  const [filter, setFilter] = useState<{ unreplied: boolean; rating: number }>({ unreplied: false, rating: 0 });
  const [replyTo, setReplyTo] = useState<GbpReviewItem | null>(null);
  const [autoSuggest, setAutoSuggest] = useState(false);

  const loadReviews = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    try {
      const res = await api.gbp.reviews(token, { unreplied: filter.unreplied || undefined, rating: filter.rating || undefined });
      setReviews(res.reviews);
    } catch { /* surfaced elsewhere */ }
  }, [filter]);
  useEffect(() => { loadReviews(); }, [loadReviews]);

  const s = overview?.stats;
  const dist = s?.rating_distribution ?? {};
  const maxDist = Math.max(1, ...Object.values(dist));

  return (
    <>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(190px,1fr))", gap: 12, marginBottom: 18 }}>
        <Stat label="Average rating" value={`${s?.average_rating ?? 0} ★`} icon="⭐" bg="#fff4e6" />
        <Stat label="Total reviews" value={s?.total_reviews ?? 0} icon="💬" bg="#eef3ff" />
        <Stat label="Locations" value={s?.locations ?? 0} icon="📍" bg="#e9faf1" />
        <Stat label="Unreplied" value={s?.unreplied ?? 0} icon="✍️" bg="#fdecec" />
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "minmax(0,1fr) 300px", gap: 16, alignItems: "start" }}>
        <div>
          <div className="panel" style={{ padding: 12, marginBottom: 14, display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
            <label style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 13 }}>
              <input type="checkbox" checked={filter.unreplied} onChange={(e) => setFilter((f) => ({ ...f, unreplied: e.target.checked }))} /> Unreplied only
            </label>
            <select value={filter.rating} onChange={(e) => setFilter((f) => ({ ...f, rating: Number(e.target.value) }))}
              style={{ padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 13 }}>
              <option value={0}>All ratings</option>
              {[5, 4, 3, 2, 1].map((r) => <option key={r} value={r}>{r} star{r > 1 ? "s" : ""}</option>)}
            </select>
          </div>
          <div style={{ display: "grid", gap: 10 }}>
            {reviews.map((r) => (
              <div key={r.id} className="panel" style={{ padding: 16 }}>
                <div style={{ display: "flex", justifyContent: "space-between", gap: 10 }}>
                  <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
                    <div style={{ width: 38, height: 38, borderRadius: "50%", background: "var(--bg)", display: "grid", placeItems: "center", fontWeight: 700 }}>
                      {(r.author_name ?? "?").slice(0, 1).toUpperCase()}
                    </div>
                    <div>
                      <strong style={{ fontSize: 14 }}>{r.author_name ?? "Anonymous"}</strong>
                      <div style={{ fontSize: 13, color: "#f6a609", letterSpacing: 1 }}>{stars(r.rating)}</div>
                    </div>
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", alignItems: "flex-end", gap: 4 }}>
                    <span className="muted" style={{ fontSize: 12 }}>{r.location?.name}</span>
                    <SentimentChip s={r.sentiment} />
                  </div>
                </div>
                {r.comment && <p style={{ margin: "10px 0 0", fontSize: 14 }}>{r.comment}</p>}
                {r.reply ? (
                  <div style={{ marginTop: 10, background: "var(--bg)", borderRadius: 10, padding: "10px 12px", borderLeft: "3px solid var(--green)" }}>
                    <div style={{ fontSize: 11, fontWeight: 700, color: "var(--green)", marginBottom: 2 }}>Your reply</div>
                    <div style={{ fontSize: 13.5 }}>{r.reply}</div>
                  </div>
                ) : (
                  <div style={{ display: "flex", gap: 6, marginTop: 10 }}>
                    <button className="btn-mini" onClick={() => { setAutoSuggest(false); setReplyTo(r); }}>Reply</button>
                    <button className="btn-mini" onClick={() => { setAutoSuggest(true); setReplyTo(r); }}>✨ AI reply</button>
                  </div>
                )}
              </div>
            ))}
            {reviews.length === 0 && <div className="panel" style={{ padding: 28, textAlign: "center" }}><span className="muted">No reviews match. Sync your Google Business Profile to pull reviews.</span></div>}
          </div>
        </div>

        <div style={{ display: "grid", gap: 16 }}>
          <div className="panel">
            <h2 style={{ fontSize: 15 }}>Rating breakdown</h2>
            {[5, 4, 3, 2, 1].map((r) => (
              <div key={r} style={{ display: "flex", alignItems: "center", gap: 8, margin: "6px 0" }}>
                <span style={{ fontSize: 12, width: 28 }}>{r} ★</span>
                <div style={{ flex: 1, height: 8, background: "var(--bg)", borderRadius: 999 }}>
                  <div style={{ width: `${((dist[r] ?? 0) / maxDist) * 100}%`, height: "100%", background: "#f6a609", borderRadius: 999 }} />
                </div>
                <span className="muted" style={{ fontSize: 12, width: 20, textAlign: "right" }}>{dist[r] ?? 0}</span>
              </div>
            ))}
          </div>
          <div className="panel">
            <h2 style={{ fontSize: 15 }}>Locations</h2>
            {(overview?.locations ?? []).map((l) => (
              <div key={l.id} style={{ padding: "10px 0", borderTop: "1px solid var(--border)" }}>
                <div style={{ display: "flex", justifyContent: "space-between", gap: 8 }}>
                  <strong style={{ fontSize: 13.5 }}>{l.name}</strong>
                  {l.is_verified && <span style={{ fontSize: 10, color: "#0a7d47" }}>✔ Verified</span>}
                </div>
                <div className="muted" style={{ fontSize: 12 }}>{l.address}</div>
                <div style={{ fontSize: 12.5, marginTop: 2 }}><span style={{ color: "#f6a609" }}>{l.rating} ★</span> · {l.review_count} reviews</div>
              </div>
            ))}
            {(overview?.locations?.length ?? 0) === 0 && <p className="muted" style={{ fontSize: 13 }}>No locations connected yet.</p>}
          </div>
        </div>
      </div>

      {replyTo && <ReplyModal review={replyTo} autoSuggest={autoSuggest} onClose={() => setReplyTo(null)} onReplied={() => { setReplyTo(null); loadReviews(); }} />}
    </>
  );
}

/* ---------------- Posts ---------------- */
const StatusBadge = ({ s }: { s: string }) => {
  const c = s === "PUBLISHED" ? { bg: "#e7f7ef", fg: "#0a7d47" } : s === "FAILED" ? { bg: "#fdecec", fg: "#c53030" } : { bg: "#eef0ff", fg: "var(--accent-dark)" };
  return <span style={{ background: c.bg, color: c.fg, fontSize: 11, fontWeight: 700, padding: "2px 9px", borderRadius: 999 }}>{s}</span>;
};

function LocationPicker({ locations, value, onChange }: { locations: GbpLocationItem[]; value: string; onChange: (v: string) => void }) {
  return (
    <select value={value} onChange={(e) => onChange(e.target.value)} style={{ padding: "9px 11px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 13 }}>
      {locations.length === 0 && <option value="">No locations — sync first</option>}
      {locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
    </select>
  );
}

function PostsTab({ locations }: { locations: GbpLocationItem[] }) {
  const [posts, setPosts] = useState<GbpPostItem[]>([]);
  const [loc, setLoc] = useState("");
  const [form, setForm] = useState({ type: "STANDARD", summary: "", image_url: "", cta_type: "NONE", cta_url: "" });
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [genBusy, setGenBusy] = useState(false);

  useEffect(() => { if (locations.length && !loc) setLoc(locations[0].id); }, [locations, loc]);
  const load = useCallback(() => { const t = getToken(); if (t) api.gbp.posts(t).then((r) => setPosts(r.posts)).catch(() => {}); }, []);
  useEffect(() => { load(); }, [load]);

  async function generate() {
    const t = getToken(); if (!t) return;
    const topic = (form.summary.trim() || window.prompt("What should this post be about?") || "").trim();
    if (!topic) return;
    setGenBusy(true); setErr(null);
    try {
      const r = await api.gbp.generatePost(t, { topic, type: form.type, location: loc || undefined });
      setForm((f) => ({ ...f, summary: r.text }));
    } catch (e) { setErr((e as ApiError).message); } finally { setGenBusy(false); }
  }

  async function create(e: React.FormEvent) {
    e.preventDefault();
    const t = getToken(); if (!t) return;
    if (!loc) { setErr("Add a location first (Sync from Google)."); return; }
    setBusy(true); setErr(null);
    try {
      await api.gbp.createPost(t, { location: loc, summary: form.summary, type: form.type, image_url: form.image_url || null, cta_type: form.cta_type, cta_url: form.cta_url || null });
      setForm({ type: "STANDARD", summary: "", image_url: "", cta_type: "NONE", cta_url: "" });
      load();
    } catch (e2) { setErr((e2 as ApiError).message); } finally { setBusy(false); }
  }
  async function publish(id: string) { const t = getToken(); if (!t) return; try { await api.gbp.publishPost(t, id); load(); } catch (e) { alert((e as ApiError).message); } }
  async function remove(id: string) { const t = getToken(); if (!t || !confirm("Delete this post?")) return; try { await api.gbp.deletePost(t, id); load(); } catch (e) { alert((e as ApiError).message); } }

  return (
    <div style={{ display: "grid", gridTemplateColumns: "340px minmax(0,1fr)", gap: 16, alignItems: "start" }}>
      <form className="panel" onSubmit={create}>
        <h2 style={{ fontSize: 15, marginTop: 0 }}>New post</h2>
        {err && <div className="error">{err}</div>}
        <div className="field"><label>Location</label><LocationPicker locations={locations} value={loc} onChange={setLoc} /></div>
        <div className="field"><label>Type</label>
          <select value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))} style={inp}>
            {POST_TYPES.map((t) => <option key={t} value={t}>{t.charAt(0) + t.slice(1).toLowerCase()}</option>)}
          </select>
        </div>
        <div className="field">
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
            <label style={{ marginBottom: 0 }}>Post text</label>
            <button type="button" className="btn-mini" disabled={genBusy} onClick={generate}>{genBusy ? "Writing…" : "✨ Write with AI"}</button>
          </div>
          <textarea value={form.summary} onChange={(e) => setForm((f) => ({ ...f, summary: e.target.value }))} required rows={4} maxLength={1500} placeholder="What's new with your business? (or let AI write it)" style={{ ...inp, resize: "vertical", fontFamily: "inherit", marginTop: 6 }} />
        </div>
        <div className="field"><label>Image URL <span className="muted">(optional)</span></label>
          <input value={form.image_url} onChange={(e) => setForm((f) => ({ ...f, image_url: e.target.value }))} placeholder="https://…" style={inp} />
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <div className="field" style={{ flex: 1 }}><label>Button</label>
            <select value={form.cta_type} onChange={(e) => setForm((f) => ({ ...f, cta_type: e.target.value }))} style={inp}>
              {CTA_TYPES.map((c) => <option key={c} value={c}>{c === "NONE" ? "None" : c.replace(/_/g, " ").toLowerCase().replace(/\b\w/g, (m) => m.toUpperCase())}</option>)}
            </select>
          </div>
          {form.cta_type !== "NONE" && <div className="field" style={{ flex: 1 }}><label>Button URL</label>
            <input value={form.cta_url} onChange={(e) => setForm((f) => ({ ...f, cta_url: e.target.value }))} placeholder="https://…" style={inp} /></div>}
        </div>
        <button className="btn" disabled={busy}>{busy ? "Saving…" : "Save draft"}</button>
      </form>

      <div style={{ display: "grid", gap: 10 }}>
        {posts.map((p) => (
          <div key={p.id} className="panel" style={{ padding: 16 }}>
            <div style={{ display: "flex", justifyContent: "space-between", gap: 10, marginBottom: 8 }}>
              <div style={{ display: "flex", gap: 8, alignItems: "center" }}><StatusBadge s={p.status} /><span className="muted" style={{ fontSize: 12 }}>{p.type} · {p.location?.name}</span></div>
              <div style={{ display: "flex", gap: 6 }}>
                {p.status !== "PUBLISHED" && <button className="btn-mini" onClick={() => publish(p.id)}>Publish</button>}
                <button className="btn-mini danger" onClick={() => remove(p.id)}>Delete</button>
              </div>
            </div>
            {p.image_url && <img src={p.image_url} alt="" style={{ width: "100%", maxHeight: 180, objectFit: "cover", borderRadius: 10, marginBottom: 8 }} />}
            <p style={{ margin: 0, fontSize: 14 }}>{p.summary}</p>
            {p.cta_type && <div style={{ marginTop: 8, fontSize: 12.5, color: "var(--accent)" }}>▸ {p.cta_type.replace(/_/g, " ")}{p.cta_url ? ` → ${p.cta_url}` : ""}</div>}
          </div>
        ))}
        {posts.length === 0 && <div className="panel" style={{ padding: 28, textAlign: "center" }}><span className="muted">No posts yet. Draft one on the left, then Publish to push it to Google.</span></div>}
      </div>
    </div>
  );
}

/* ---------------- Photos ---------------- */
function PhotosTab({ locations }: { locations: GbpLocationItem[] }) {
  const [photos, setPhotos] = useState<GbpPhotoItem[]>([]);
  const [loc, setLoc] = useState("");
  const [form, setForm] = useState({ image_url: "", caption: "", category: "ADDITIONAL" });
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [genBusy, setGenBusy] = useState(false);

  useEffect(() => { if (locations.length && !loc) setLoc(locations[0].id); }, [locations, loc]);
  const load = useCallback(() => { const t = getToken(); if (t) api.gbp.photos(t).then((r) => setPhotos(r.photos)).catch(() => {}); }, []);
  useEffect(() => { load(); }, [load]);

  async function genCaption() {
    const t = getToken(); if (!t) return;
    const topic = (form.caption.trim() || window.prompt("What is this photo of?") || "").trim();
    if (!topic) return;
    setGenBusy(true); setErr(null);
    try {
      const r = await api.gbp.captionPhoto(t, { topic, location: loc || undefined });
      setForm((f) => ({ ...f, caption: r.text }));
    } catch (e) { setErr((e as ApiError).message); } finally { setGenBusy(false); }
  }

  async function create(e: React.FormEvent) {
    e.preventDefault();
    const t = getToken(); if (!t) return;
    if (!loc) { setErr("Add a location first (Sync from Google)."); return; }
    setBusy(true); setErr(null);
    try { await api.gbp.createPhoto(t, { location: loc, image_url: form.image_url, caption: form.caption || null, category: form.category }); setForm({ image_url: "", caption: "", category: "ADDITIONAL" }); load(); }
    catch (e2) { setErr((e2 as ApiError).message); } finally { setBusy(false); }
  }
  async function publish(id: string) { const t = getToken(); if (!t) return; try { await api.gbp.publishPhoto(t, id); load(); } catch (e) { alert((e as ApiError).message); } }
  async function remove(id: string) { const t = getToken(); if (!t || !confirm("Delete this photo?")) return; try { await api.gbp.deletePhoto(t, id); load(); } catch (e) { alert((e as ApiError).message); } }

  return (
    <div style={{ display: "grid", gridTemplateColumns: "340px minmax(0,1fr)", gap: 16, alignItems: "start" }}>
      <form className="panel" onSubmit={create}>
        <h2 style={{ fontSize: 15, marginTop: 0 }}>Add photo</h2>
        {err && <div className="error">{err}</div>}
        <div className="field"><label>Location</label><LocationPicker locations={locations} value={loc} onChange={setLoc} /></div>
        <div className="field"><label>Image URL</label><input value={form.image_url} onChange={(e) => setForm((f) => ({ ...f, image_url: e.target.value }))} required placeholder="https://…" style={inp} /></div>
        <div className="field"><label>Category</label>
          <select value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))} style={inp}>
            {PHOTO_CATS.map((c) => <option key={c} value={c}>{c.replace(/_/g, " ").toLowerCase().replace(/\b\w/g, (m) => m.toUpperCase())}</option>)}
          </select>
        </div>
        <div className="field">
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
            <label style={{ marginBottom: 0 }}>Caption <span className="muted">(optional)</span></label>
            <button type="button" className="btn-mini" disabled={genBusy} onClick={genCaption}>{genBusy ? "Writing…" : "✨ AI"}</button>
          </div>
          <input value={form.caption} onChange={(e) => setForm((f) => ({ ...f, caption: e.target.value }))} style={{ ...inp, marginTop: 6 }} />
        </div>
        {form.image_url && <img src={form.image_url} alt="" style={{ width: "100%", borderRadius: 10, marginBottom: 10, maxHeight: 160, objectFit: "cover" }} />}
        <button className="btn" disabled={busy}>{busy ? "Saving…" : "Save photo"}</button>
      </form>

      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(160px,1fr))", gap: 12 }}>
        {photos.map((p) => (
          <div key={p.id} className="panel" style={{ padding: 10 }}>
            <img src={p.image_url} alt={p.caption ?? ""} style={{ width: "100%", height: 120, objectFit: "cover", borderRadius: 8 }} />
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 8 }}>
              <StatusBadge s={p.status} />
              <div style={{ display: "flex", gap: 4 }}>
                {p.status !== "PUBLISHED" && <button className="btn-mini" style={{ padding: "4px 8px" }} onClick={() => publish(p.id)}>Publish</button>}
                <button className="btn-mini danger" style={{ padding: "4px 8px" }} onClick={() => remove(p.id)}>✕</button>
              </div>
            </div>
            {p.caption && <div style={{ fontSize: 12, marginTop: 6 }}>{p.caption}</div>}
          </div>
        ))}
        {photos.length === 0 && <div className="panel" style={{ padding: 28, textAlign: "center", gridColumn: "1/-1" }}><span className="muted">No photos yet. Add one on the left, then Publish to Google.</span></div>}
      </div>
    </div>
  );
}

/* ---------------- Insights ---------------- */
function InsightsTab({ locations }: { locations: GbpLocationItem[] }) {
  const [data, setData] = useState<GbpInsights | null>(null);
  const [days, setDays] = useState(30);
  const [err, setErr] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    const t = getToken(); if (!t) return;
    setLoading(true);
    api.gbp.insights(t, days).then(setData).catch((e) => setErr((e as ApiError).message)).finally(() => setLoading(false));
  }, [days]);
  useEffect(() => { load(); }, [load]);

  async function exportCsv() {
    const t = getToken(); if (!t) return;
    const base = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api/v1";
    const res = await fetch(`${base}/gbp/insights/export?days=${days}`, { headers: { Authorization: `Bearer ${t}` } });
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a"); a.href = url; a.download = "gbp-insights.csv"; a.click(); URL.revokeObjectURL(url);
  }

  const metricCards = [
    { k: "impressions", icon: "👁️", bg: "#eef3ff" },
    { k: "CALL_CLICKS", icon: "📞", bg: "#e9faf1" },
    { k: "WEBSITE_CLICKS", icon: "🌐", bg: "#fff4e6" },
    { k: "BUSINESS_DIRECTION_REQUESTS", icon: "🧭", bg: "#f3eeff" },
  ];
  const series = data?.series ?? [];
  const maxV = Math.max(1, ...series.map((s) => s.value));

  return (
    <div>
      <div className="panel" style={{ padding: 12, marginBottom: 16, display: "flex", gap: 12, alignItems: "center", flexWrap: "wrap" }}>
        <span style={{ fontSize: 13, fontWeight: 600 }}>Period</span>
        <select value={days} onChange={(e) => setDays(Number(e.target.value))} style={{ padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 13 }}>
          {[7, 30, 90].map((d) => <option key={d} value={d}>Last {d} days</option>)}
        </select>
        <div style={{ flex: 1 }} />
        <button className="btn-mini" onClick={exportCsv} disabled={!data?.connected}>⬇ Export CSV</button>
      </div>

      {err && <div className="error">{err}</div>}
      {loading && <div className="panel" style={{ padding: 28, textAlign: "center" }}><span className="muted">Loading insights…</span></div>}

      {!loading && data && !data.connected && (
        <div className="panel" style={{ padding: 28, textAlign: "center" }}>
          <div style={{ fontSize: 34 }}>📈</div>
          <p className="muted" style={{ maxWidth: 420, margin: "8px auto 0" }}>{data.message ?? "Connect your Google Business Profile to see live performance insights."}</p>
        </div>
      )}

      {!loading && data?.connected && (
        <>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(190px,1fr))", gap: 12, marginBottom: 16 }}>
            {metricCards.map((m) => <Stat key={m.k} label={data.labels[m.k] ?? m.k} value={(data.metrics[m.k] ?? 0).toLocaleString("en-IN")} icon={m.icon} bg={m.bg} />)}
          </div>
          <div className="panel">
            <h2 style={{ fontSize: 15 }}>Daily interactions</h2>
            {series.length === 0 ? <p className="muted" style={{ fontSize: 13 }}>No data for this period yet.</p> : (
              <div style={{ display: "flex", alignItems: "flex-end", gap: 2, height: 160, marginTop: 10 }}>
                {series.map((s) => (
                  <div key={s.date} title={`${s.date}: ${s.value}`} style={{ flex: 1, background: "var(--brand-grad)", borderRadius: "3px 3px 0 0", height: `${(s.value / maxV) * 100}%`, minHeight: 2 }} />
                ))}
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}

/* ---------------- Health Score ---------------- */
function HealthTab() {
  const [data, setData] = useState<GbpHealth | null>(null);
  const [loading, setLoading] = useState(true);
  const [opt, setOpt] = useState<GbpOptimizeResult | null>(null);
  const [optBusy, setOptBusy] = useState(false);
  const [optErr, setOptErr] = useState<string | null>(null);
  useEffect(() => { const t = getToken(); if (!t) return; api.gbp.health(t).then(setData).finally(() => setLoading(false)); }, []);

  async function optimize() {
    const t = getToken(); if (!t) return;
    setOptBusy(true); setOptErr(null);
    try { const r = await api.gbp.optimize(t); setOpt(r); if (r.health) setData(r.health); }
    catch (e) { setOptErr((e as ApiError).message); } finally { setOptBusy(false); }
  }

  if (loading) return <div className="panel" style={{ padding: 28, textAlign: "center" }}><span className="muted">Scoring your profile…</span></div>;
  if (!data) return null;

  const ring = `conic-gradient(var(--accent) ${data.score * 3.6}deg, var(--border) 0deg)`;
  const prio = (p: string) => p === "critical" ? "#c53030" : p === "high" ? "#d97706" : "#0a7d47";

  return (
    <div style={{ display: "grid", gridTemplateColumns: "280px minmax(0,1fr)", gap: 16, alignItems: "start" }}>
      <div className="panel" style={{ textAlign: "center" }}>
        <div style={{ width: 150, height: 150, borderRadius: "50%", background: ring, margin: "8px auto", display: "grid", placeItems: "center" }}>
          <div style={{ width: 118, height: 118, borderRadius: "50%", background: "var(--card)", display: "grid", placeItems: "center" }}>
            <div><div style={{ fontSize: 38, fontWeight: 800 }}>{data.score}</div><div className="muted" style={{ fontSize: 12 }}>/ 100</div></div>
          </div>
        </div>
        <div style={{ fontWeight: 800, fontSize: 18, color: "var(--accent)" }}>{data.grade}</div>
        <p className="muted" style={{ fontSize: 12.5 }}>Google Business Profile health</p>
        <button className="btn" style={{ width: "100%", marginTop: 8 }} disabled={optBusy} onClick={optimize}>{optBusy ? "Optimizing…" : "⚡ One-Click Optimize"}</button>
        <p className="muted" style={{ fontSize: 11, marginTop: 6 }}>AI builds a prioritized action plan (3 credits).</p>
        {optErr && <div className="error" style={{ marginTop: 8 }}>{optErr}</div>}
      </div>

      <div style={{ display: "grid", gap: 16 }}>
        {opt && (
          <div className="panel" style={{ borderLeft: "3px solid var(--accent)" }}>
            <h2 style={{ fontSize: 15, marginTop: 0 }}>⚡ AI optimization plan {opt.source === "fallback" && <span className="muted" style={{ fontSize: 11, fontWeight: 400 }}>(AI off — basic plan)</span>}</h2>
            <p style={{ fontSize: 13.5, margin: "4px 0 10px" }}>{opt.summary}</p>
            <div style={{ display: "grid", gap: 8 }}>
              {opt.actions.map((a, i) => (
                <div key={i} style={{ display: "flex", gap: 10, alignItems: "center", fontSize: 13.5 }}>
                  <span style={{ fontSize: 10, fontWeight: 800, textTransform: "uppercase", color: "#fff", background: prio(a.priority), padding: "2px 7px", borderRadius: 999, flex: "none" }}>{a.priority}</span>
                  {a.text ?? a.title}
                </div>
              ))}
            </div>
          </div>
        )}
        <div className="panel">
          <h2 style={{ fontSize: 15 }}>Sub-scores</h2>
          {data.sub_scores.map((s) => (
            <div key={s.key} style={{ margin: "10px 0" }}>
              <div style={{ display: "flex", justifyContent: "space-between", fontSize: 13, marginBottom: 4 }}>
                <span>{s.label} <span className="muted">· {s.detail}</span></span><strong>{s.score}</strong>
              </div>
              <div style={{ height: 8, background: "var(--bg)", borderRadius: 999 }}>
                <div style={{ width: `${s.score}%`, height: "100%", background: "var(--brand-grad)", borderRadius: 999 }} />
              </div>
            </div>
          ))}
        </div>
        <div className="panel">
          <h2 style={{ fontSize: 15 }}>Do next</h2>
          {data.actions.length === 0 ? <p className="muted" style={{ fontSize: 13 }}>🎉 Your profile is in great shape — keep it up!</p> : (
            <div style={{ display: "grid", gap: 8 }}>
              {data.actions.map((a, i) => (
                <div key={i} style={{ display: "flex", gap: 10, alignItems: "center", fontSize: 13.5 }}>
                  <span style={{ fontSize: 10, fontWeight: 800, textTransform: "uppercase", color: "#fff", background: prio(a.priority), padding: "2px 7px", borderRadius: 999, flex: "none" }}>{a.priority}</span>
                  {a.text ?? a.title}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/* ---------------- shared ---------------- */
const inp: React.CSSProperties = { width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 };

function GbpConnectBar() {
  const [conn, setConn] = useState<GbpConnection | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  const load = useCallback(() => { const token = getToken(); if (!token) return; api.gbp.connection(token).then(setConn).catch(() => {}); }, []);
  useEffect(() => { load(); }, [load]);
  useEffect(() => {
    const p = new URLSearchParams(window.location.search).get("gbp_connect");
    if (p) { setMsg(p === "success" ? "Google Business Profile connected. Click “Sync from Google” to pull your locations and reviews." : `Connection ${p.replace(/_/g, " ")}.`); window.history.replaceState({}, "", window.location.pathname); }
  }, []);

  async function connect() { const token = getToken(); if (!token) return; setBusy(true); try { const r = await api.gbp.connect(token); window.location.href = r.url; } catch (e) { setMsg((e as ApiError).message); setBusy(false); } }
  async function disconnect() { const token = getToken(); if (!token || !confirm("Disconnect Google Business Profile?")) return; setBusy(true); try { await api.gbp.disconnect(token); load(); } catch (e) { setMsg((e as ApiError).message); } finally { setBusy(false); } }

  if (!conn) return null;
  const connected = conn.connected;
  return (
    <div className="panel" style={{ padding: "12px 16px", marginBottom: 14, display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap", borderLeft: `3px solid ${connected ? "var(--green)" : "var(--border)"}` }}>
      <span style={{ fontSize: 20 }}>{connected ? "🟢" : "🔗"}</span>
      <div style={{ flex: 1, minWidth: 200 }}>
        <strong style={{ fontSize: 14 }}>Google Business Profile</strong>
        <div className="muted" style={{ fontSize: 12.5 }}>
          {!conn.configured ? "Not configured on the server (set GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET)."
            : connected ? `Connected${conn.last_synced_at ? ` · last synced ${new Date(conn.last_synced_at).toLocaleString()}` : ""}.`
            : "Connect your Google account to sync locations & reviews and post to Google."}
        </div>
        {msg && <div className="muted" style={{ fontSize: 12.5, marginTop: 4, color: "var(--green)" }}>{msg}</div>}
      </div>
      {conn.configured && (connected
        ? <button className="btn-mini" disabled={busy} onClick={disconnect}>Disconnect</button>
        : <button className="btn-mini" disabled={busy} onClick={connect}>{busy ? "Redirecting…" : "Connect Google"}</button>)}
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

function SentimentChip({ s }: { s: GbpReviewItem["sentiment"] }) {
  if (!s) return null;
  const c = s === "positive" ? { bg: "#e7f7ef", fg: "#0a7d47", t: "😊 Positive" }
    : s === "negative" ? { bg: "#fdecec", fg: "#c53030", t: "😟 Negative" }
    : { bg: "#eef1f2", fg: "#667781", t: "😐 Neutral" };
  return <span style={{ background: c.bg, color: c.fg, fontSize: 10, fontWeight: 700, padding: "2px 8px", borderRadius: 999 }}>{c.t}</span>;
}

function ReplyModal({ review, autoSuggest, onClose, onReplied }: { review: GbpReviewItem; autoSuggest?: boolean; onClose: () => void; onReplied: () => void }) {
  const [text, setText] = useState(review.draft_reply ?? "");
  const [saving, setSaving] = useState(false);
  const [suggesting, setSuggesting] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);

  const suggest = useCallback(async () => {
    const token = getToken(); if (!token) return;
    setSuggesting(true); setErr(null); setNote(null);
    try {
      const res = await api.gbp.suggestReply(token, review.id);
      setText(res.draft);
      setNote(res.source === "fallback"
        ? "AI isn't configured — inserted a suggested template. No credits charged."
        : `AI draft inserted.${res.credits != null ? ` ${res.credits} credits left.` : ""} Edit it, then post.`);
    } catch (e) { setErr((e as ApiError).message); } finally { setSuggesting(false); }
  }, [review.id]);

  // Auto-run the AI suggestion when opened via the "✨ AI reply" button.
  useEffect(() => { if (autoSuggest && !review.draft_reply) suggest(); }, [autoSuggest, review.draft_reply, suggest]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken(); if (!token) return;
    setSaving(true); setErr(null);
    try { await api.gbp.reply(token, review.id, text); onReplied(); }
    catch (e2) { setErr((e2 as ApiError).message); } finally { setSaving(false); }
  }

  return (
    <div className="msg-info-overlay" onClick={onClose} style={{ zIndex: 200 }}>
      <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 520 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <h2 style={{ margin: 0 }}>Reply to {review.author_name ?? "review"}</h2>
          <button className="btn-mini" onClick={onClose}>Close</button>
        </div>
        <div style={{ margin: "10px 0", fontSize: 13, color: "#f6a609", display: "flex", gap: 8, alignItems: "center" }}>{stars(review.rating)} <SentimentChip s={review.sentiment} /></div>
        {review.comment && <p className="muted" style={{ fontSize: 13.5 }}>{review.comment}</p>}
        {err && <div className="error">{err}</div>}
        {note && <div className="panel" style={{ background: "#eef3ff", color: "var(--accent-dark)", fontSize: 12.5, padding: "8px 10px", marginBottom: 8 }}>{note}</div>}
        <form onSubmit={submit}>
          <div style={{ display: "flex", justifyContent: "flex-end", marginBottom: 6 }}>
            <button type="button" className="btn-mini" disabled={suggesting} onClick={suggest}>{suggesting ? "Thinking…" : "✨ Suggest with AI"}</button>
          </div>
          <textarea value={text} onChange={(e) => setText(e.target.value)} required rows={4} placeholder="Write a public reply…"
            style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontFamily: "inherit", resize: "vertical", marginBottom: 10 }} />
          <button className="btn" disabled={saving} style={{ width: "auto", padding: "11px 24px" }}>{saving ? "Posting…" : "Post reply"}</button>
        </form>
      </div>
    </div>
  );
}
