"use client";

import { useCallback, useEffect, useState } from "react";
import { useUser } from "@/lib/user-context";
import { AccessDenied, hasFeature } from "@/components/PlanGate";
import { PageHeader } from "@/components/PageHeader";
import { api, ApiError, GbpAutomationResponse, GbpAutomationSettings } from "@/lib/api";
import { getToken } from "@/lib/auth";

const FEATURES: { key: keyof GbpAutomationSettings; title: string; desc: string; icon: string }[] = [
  { key: "auto_review_replies", title: "AI Review Replies", desc: "Automatically draft & post warm, professional replies to new customer reviews.", icon: "💬" },
  { key: "auto_posts", title: "Auto Post Scheduling", desc: "Keep your profile active — AI writes & schedules Google posts at your chosen cadence.", icon: "📝" },
  { key: "auto_photos", title: "AI Photo Generation", desc: "Attach an AI-generated image to each scheduled post automatically.", icon: "🖼️" },
  { key: "qr_review_assist", title: "QR Review Assistant", desc: "A scan-to-review page where AI helps customers write their Google review in seconds.", icon: "📱" },
];

export default function GbpAutomationPage() {
  const user = useUser();
  if (!hasFeature(user, "dash_gbp")) return <AccessDenied dashboard="Google Reviews / GBP" />;
  return <Dashboard />;
}

function Dashboard() {
  const [data, setData] = useState<GbpAutomationResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [qr, setQr] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const load = useCallback(async () => {
    const token = getToken(); if (!token) return;
    setLoading(true);
    try { setData(await api.gbp.automation(token)); }
    catch (e) { setError((e as ApiError).message); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { load(); }, [load]);

  const a = data?.automation;
  const assistUrl = data?.assist_url || data?.assist_url_preview || "";

  useEffect(() => {
    if (!assistUrl) { setQr(null); return; }
    let alive = true;
    import("qrcode").then((QR) => QR.toDataURL(assistUrl, { margin: 1, width: 240 })
      .then((d) => { if (alive) setQr(d); }).catch(() => setQr(null))).catch(() => setQr(null));
    return () => { alive = false; };
  }, [assistUrl]);

  async function patch(body: Partial<GbpAutomationSettings>, label: string) {
    const token = getToken(); if (!token) return;
    setSaving(label); setError(null); setNotice(null);
    try {
      const res = await api.gbp.updateAutomation(token, body);
      setData(res);
      setNotice(res.message);
      setTimeout(() => setNotice(null), 2500);
    } catch (e) { setError((e as ApiError).message); }
    finally { setSaving(null); }
  }

  function copyAssist() {
    if (!assistUrl) return;
    navigator.clipboard?.writeText(assistUrl).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1800); }).catch(() => {});
  }

  if (loading || !a) {
    return <div><PageHeader title="Automation" subtitle="AI runs your Google Business Profile on autopilot." /><div className="loading-block"><span className="spinner" /><span>Loading…</span></div></div>;
  }

  const activeCount = FEATURES.filter((f) => a[f.key]).length;

  return (
    <div>
      <PageHeader
        title="Automation"
        subtitle="Switch on the AI features that run your Google Business Profile automatically — reviews, posts, photos and reviews-via-QR."
      />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      {!data?.has_location && (
        <div className="panel" style={{ background: "#fff4e5", color: "#8a5a00", marginBottom: 16 }}>
          No Google Business Profile location yet. Connect & sync Google (Reviews → Connect) or add Business Info first — then automation has something to work with.
        </div>
      )}

      <div className="panel" style={{ marginBottom: 16, display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap", borderLeft: "4px solid var(--accent)" }}>
        <div style={{ fontSize: 26 }}>⚡</div>
        <div style={{ flex: 1, minWidth: 220 }}>
          <div style={{ fontWeight: 700 }}>{activeCount} of {FEATURES.length} automations active{data?.business ? ` · ${data.business}` : ""}</div>
          <div className="muted" style={{ fontSize: 12.5 }}>
            {a.last_run_at ? `Last run ${new Date(a.last_run_at).toLocaleString()}` : "Automations run in the background once enabled."}
          </div>
        </div>
      </div>

      {/* Feature toggles */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(290px, 1fr))", gap: 14 }}>
        {FEATURES.map((f) => {
          const on = !!a[f.key];
          return (
            <div key={f.key} className="panel" style={{ display: "flex", gap: 12 }}>
              <div style={{ fontSize: 24, lineHeight: 1 }}>{f.icon}</div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8 }}>
                  <h3 style={{ margin: 0, fontSize: 15 }}>{f.title}</h3>
                  <Toggle on={on} busy={saving === f.key} onChange={(v) => patch({ [f.key]: v } as Partial<GbpAutomationSettings>, f.key)} />
                </div>
                <p className="muted" style={{ margin: "6px 0 0", fontSize: 12.5 }}>{f.desc}</p>
              </div>
            </div>
          );
        })}
      </div>

      {/* Content strategy */}
      <div className="panel" style={{ marginTop: 16, maxWidth: 640 }}>
        <h3 style={{ marginTop: 0 }}>Content strategy</h3>
        <div style={{ display: "flex", gap: 14, flexWrap: "wrap" }}>
          <div className="field" style={{ flex: 1, minWidth: 180 }}>
            <label>Post every (days)</label>
            <input type="number" min={1} max={30} defaultValue={a.post_frequency_days}
              onBlur={(e) => { const v = Number(e.target.value) || a.post_frequency_days; if (v !== a.post_frequency_days) patch({ post_frequency_days: v }, "freq"); }} />
          </div>
          <div className="field" style={{ flex: 1, minWidth: 180 }}>
            <label>Keep scheduled posts</label>
            <input type="number" min={1} max={12} defaultValue={a.post_queue_target}
              onBlur={(e) => { const v = Number(e.target.value) || a.post_queue_target; if (v !== a.post_queue_target) patch({ post_queue_target: v }, "target"); }} />
          </div>
        </div>
        <div className="field">
          <label>Reply tone</label>
          <input defaultValue={a.reply_tone}
            onBlur={(e) => { const v = e.target.value.trim(); if (v && v !== a.reply_tone) patch({ reply_tone: v }, "tone"); }}
            placeholder="warm, friendly and professional" />
        </div>
        <p className="muted" style={{ fontSize: 11.5 }}>Changes save automatically when you click away.</p>
      </div>

      {/* QR review assistant */}
      <div className="panel" style={{ marginTop: 16, borderLeft: "4px solid var(--brand-grad-end, #ad17e6)" }}>
        <h3 style={{ marginTop: 0 }}>📱 QR Review Assistant</h3>
        <p className="muted" style={{ marginTop: 0, fontSize: 13, maxWidth: 620 }}>
          Print this QR at your counter. A customer scans it, taps their star rating, and AI drafts a natural review they can tweak, copy and post on Google — in seconds.
        </p>
        {!a.qr_review_assist && (
          <div className="muted" style={{ fontSize: 12.5, marginBottom: 12 }}>Turn on <strong>QR Review Assistant</strong> above to share this with customers. (Preview shown below.)</div>
        )}
        {!data?.review_url && (
          <div className="panel" style={{ background: "#fff4e5", color: "#8a5a00", fontSize: 12.5 }}>
            Add your Google review link in <strong>Business Info → Get more reviews</strong> first, then the QR will point customers to the right place.
          </div>
        )}
        {assistUrl && (
          <div style={{ display: "flex", gap: 18, flexWrap: "wrap", alignItems: "flex-start", marginTop: 6 }}>
            <div style={{ textAlign: "center" }}>
              {qr ? <img src={qr} alt="Review QR" width={176} height={176} style={{ border: "1px solid var(--border)", borderRadius: 12, background: "#fff" }} />
                  : <div style={{ width: 176, height: 176, border: "1px dashed var(--border)", borderRadius: 12, display: "grid", placeItems: "center", color: "var(--muted)" }}>…</div>}
              {qr && <div><a className="btn-mini" href={qr} download="review-qr.png" style={{ textDecoration: "none", marginTop: 8, display: "inline-block" }}>Download QR</a></div>}
            </div>
            <div style={{ flex: "1 1 280px", minWidth: 260 }}>
              <div className="field"><label>Review-assist link</label>
                <div style={{ display: "flex", gap: 8 }}>
                  <input readOnly value={assistUrl} style={{ flex: 1, minWidth: 0 }} />
                  <button className="btn" style={{ width: "auto", flex: "0 0 auto", padding: "0 16px" }} onClick={copyAssist}>{copied ? "✓" : "Copy"}</button>
                </div>
              </div>
              <a className="btn-mini" href={assistUrl} target="_blank" rel="noreferrer" style={{ textDecoration: "none" }}>Open preview ↗</a>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function Toggle({ on, busy, onChange }: { on: boolean; busy: boolean; onChange: (v: boolean) => void }) {
  return (
    <button type="button" role="switch" aria-checked={on} disabled={busy} onClick={() => onChange(!on)}
      style={{
        width: 46, height: 26, borderRadius: 999, border: "none", cursor: busy ? "wait" : "pointer", position: "relative",
        background: on ? "var(--accent)" : "#cbd2e0", transition: "background .15s", flexShrink: 0, opacity: busy ? 0.6 : 1,
      }}>
      <span style={{ position: "absolute", top: 3, left: on ? 23 : 3, width: 20, height: 20, borderRadius: "50%", background: "#fff", transition: "left .15s", boxShadow: "0 1px 3px rgba(0,0,0,.25)" }} />
    </button>
  );
}
