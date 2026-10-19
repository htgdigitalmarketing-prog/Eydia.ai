"use client";

import { useCallback, useEffect, useState } from "react";
import { useUser } from "@/lib/user-context";
import { AccessDenied, hasFeature } from "@/components/PlanGate";
import { PageHeader } from "@/components/PageHeader";
import {
  api, ApiError, GbpConnection, GbpOverview, GbpReviewItem,
  GbpPostItem, GbpPhotoItem, GbpInsights, GbpHealth, GbpLocationItem,
} from "@/lib/api";
import { getToken } from "@/lib/auth";

const stars = (n: number) => "★★★★★".slice(0, n) + "☆☆☆☆☆".slice(0, 5 - n);
const POST_TYPES = ["STANDARD", "EVENT", "OFFER", "ALERT"];
const CTA_TYPES = ["NONE", "LEARN_MORE", "CALL", "BOOK", "ORDER", "SIGN_UP", "SHOP"];
const PHOTO_CATS = ["ADDITIONAL", "COVER", "PROFILE", "LOGO", "EXTERIOR", "INTERIOR", "PRODUCT", "AT_WORK", "FOOD_AND_DRINK"];

const TABS = [
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
  const [tab, setTab] = useState("reviews");
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

      {tab === "reviews" && <ReviewsTab overview={overview} />}
      {tab === "posts" && <PostsTab locations={locations} />}
      {tab === "photos" && <PhotosTab locations={locations} />}
      {tab === "insights" && <InsightsTab locations={locations} />}
      {tab === "health" && <HealthTab />}
    </div>
  );
}

/* ---------------- Reviews ---------------- */
function ReviewsTab({ overview }: { overview: GbpOverview | null }) {
  const [reviews, setReviews] = useState<GbpReviewItem[]>([]);
  const [filter, setFilter] = useState<{ unreplied: boolean; rating: number }>({ unreplied: false, rating: 0 });
  const [replyTo, setReplyTo] = useState<GbpReviewItem | null>(null);

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
                  <span className="muted" style={{ fontSize: 12 }}>{r.location?.name}</span>
                </div>
                {r.comment && <p style={{ margin: "10px 0 0", fontSize: 14 }}>{r.comment}</p>}
                {r.reply ? (
                  <div style={{ marginTop: 10, background: "var(--bg)", borderRadius: 10, padding: "10px 12px", borderLeft: "3px solid var(--green)" }}>
                    <div style={{ fontSize: 11, fontWeight: 700, color: "var(--green)", marginBottom: 2 }}>Your reply</div>
                    <div style={{ fontSize: 13.5 }}>{r.reply}</div>
                  </div>
                ) : (
                  <button className="btn-mini" style={{ marginTop: 10 }} onClick={() => setReplyTo(r)}>Reply</button>
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

      {replyTo && <ReplyModal review={replyTo} onClose={() => setReplyTo(null)} onReplied={() => { setReplyTo(null); loadReviews(); }} />}
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

  useEffect(() => { if (locations.length && !loc) setLoc(locations[0].id); }, [locations, loc]);
  const load = useCallback(() => { const t = getToken(); if (t) api.gbp.posts(t).then((r) => setPosts(r.posts)).catch(() => {}); }, []);
  useEffect(() => { load(); }, [load]);

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
        <div className="field"><label>Post text</label>
          <textarea value={form.summary} onChange={(e) => setForm((f) => ({ ...f, summary: e.target.value }))} required rows={4} maxLength={1500} placeholder="What's new with your business?" style={{ ...inp, resize: "vertical", fontFamily: "inherit" }} />
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

  useEffect(() => { if (locations.length && !loc) setLoc(locations[0].id); }, [locations, loc]);
  const load = useCallback(() => { const t = getToken(); if (t) api.gbp.photos(t).then((r) => setPhotos(r.photos)).catch(() => {}); }, []);
  useEffect(() => { load(); }, [load]);

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
        <div className="field"><label>Caption <span className="muted">(optional)</span></label><input value={form.caption} onChange={(e) => setForm((f) => ({ ...f, caption: e.target.value }))} style={inp} /></div>
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
  useEffect(() => { const t = getToken(); if (!t) return; api.gbp.health(t).then(setData).finally(() => setLoading(false)); }, []);

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
      </div>

      <div style={{ display: "grid", gap: 16 }}>
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
                  {a.text}
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

function ReplyModal({ review, onClose, onReplied }: { review: GbpReviewItem; onClose: () => void; onReplied: () => void }) {
  const [text, setText] = useState("");
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);

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
        <div style={{ margin: "10px 0", fontSize: 13, color: "#f6a609" }}>{stars(review.rating)}</div>
        {review.comment && <p className="muted" style={{ fontSize: 13.5 }}>{review.comment}</p>}
        {err && <div className="error">{err}</div>}
        <form onSubmit={submit}>
          <textarea value={text} onChange={(e) => setText(e.target.value)} required rows={4} placeholder="Write a public reply…"
            style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontFamily: "inherit", resize: "vertical", marginBottom: 10 }} />
          <button className="btn" disabled={saving} style={{ width: "auto", padding: "11px 24px" }}>{saving ? "Posting…" : "Post reply"}</button>
        </form>
      </div>
    </div>
  );
}
