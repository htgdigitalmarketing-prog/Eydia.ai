"use client";

import { useCallback, useEffect, useState } from "react";
import { useUser } from "@/lib/user-context";
import { AccessDenied, hasFeature } from "@/components/PlanGate";
import { PageHeader } from "@/components/PageHeader";
import { api, ApiError, GbpProfile, GbpHours } from "@/lib/api";
import { getToken } from "@/lib/auth";

const TABS = [
  { k: "about", label: "About", icon: "ℹ️" },
  { k: "contact", label: "Contact", icon: "📞" },
  { k: "location", label: "Location", icon: "📍" },
  { k: "hours", label: "Hours", icon: "🕒" },
  { k: "more", label: "More", icon: "➕" },
] as const;

const DAY_LABEL: Record<string, string> = {
  MONDAY: "Monday", TUESDAY: "Tuesday", WEDNESDAY: "Wednesday", THURSDAY: "Thursday",
  FRIDAY: "Friday", SATURDAY: "Saturday", SUNDAY: "Sunday",
};

export default function GbpProfilePage() {
  const user = useUser();
  if (!hasFeature(user, "dash_gbp")) return <AccessDenied dashboard="Google Reviews / GBP" />;
  return <Editor />;
}

function Editor() {
  const [form, setForm] = useState<GbpProfile | null>(null);
  const [groups, setGroups] = useState<string[]>([]);
  const [connected, setConnected] = useState(false);
  const [tab, setTab] = useState<(typeof TABS)[number]["k"]>("about");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [qr, setQr] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const load = useCallback(async () => {
    const token = getToken(); if (!token) return;
    setLoading(true); setError(null);
    try {
      const res = await api.gbp.profile(token);
      setForm(res.profile); setGroups(res.attribute_groups); setConnected(res.connected);
    } catch (e) { setError((e as ApiError).message ?? "Failed to load profile."); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { load(); }, [load]);

  // Render the review QR code offline, whenever the review link changes.
  useEffect(() => {
    const url = form?.review_url;
    if (!url) { setQr(null); return; }
    let alive = true;
    import("qrcode").then((QR) =>
      QR.toDataURL(url, { margin: 1, width: 240 }).then((d) => { if (alive) setQr(d); }).catch(() => setQr(null))
    ).catch(() => setQr(null));
    return () => { alive = false; };
  }, [form?.review_url]);

  const set = <K extends keyof GbpProfile>(k: K, v: GbpProfile[K]) =>
    setForm((f) => (f ? { ...f, [k]: v } : f));

  async function save() {
    const token = getToken(); if (!token || !form) return;
    setSaving(true); setError(null); setNotice(null);
    try {
      const res = await api.gbp.updateProfile(token, form);
      setForm(res.profile);
      setNotice(res.message);
    } catch (e) { setError((e as ApiError).message ?? "Save failed."); }
    finally { setSaving(false); }
  }

  function copyLink() {
    if (!form?.review_url) return;
    navigator.clipboard?.writeText(form.review_url).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1800); }).catch(() => {});
  }

  if (loading || !form) {
    return (
      <div>
        <PageHeader title="Business information" subtitle="Edit and manage your Google Business Profile." />
        <div className="loading-block"><span className="spinner" /><span>Loading…</span></div>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Business information"
        subtitle="Edit and manage your Google Business Profile — it keeps your listing, website and reports in sync."
        action={<button className="btn" disabled={saving} onClick={save}>{saving ? "Saving…" : "Save changes"}</button>}
      />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      <div className="panel" style={{ marginBottom: 16, display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
        <span style={{ fontSize: 12.5, fontWeight: 700, padding: "3px 10px", borderRadius: 999, background: connected ? "#e7f7ef" : "#fff4e5", color: connected ? "#0a7d47" : "#8a5a00" }}>
          {connected ? "🟢 Connected to Google" : "⚪ Not connected to Google"}
        </span>
        <span className="muted" style={{ fontSize: 12.5 }}>
          {connected
            ? "Changes are saved in Eydia and pushed to your live Google listing."
            : "Changes are saved in Eydia and power your website & reports. Connect Google (Reviews → Connect) to push them live."}
        </span>
      </div>

      <ReviewCard profile={form} qr={qr} copied={copied} onCopy={copyLink} onReviewUrl={(v) => set("review_url", v)} onSave={save} saving={saving} />

      {/* Tabs */}
      <div style={{ display: "flex", gap: 6, borderBottom: "1px solid var(--border)", margin: "18px 0 16px", flexWrap: "wrap" }}>
        {TABS.map((t) => (
          <button key={t.k} onClick={() => setTab(t.k)} style={{
            border: "none", background: "none", cursor: "pointer", padding: "9px 14px", fontSize: 14, fontWeight: 700,
            color: tab === t.k ? "var(--accent)" : "var(--muted, #667781)",
            borderBottom: tab === t.k ? "2px solid var(--accent)" : "2px solid transparent", marginBottom: -1,
          }}>
            <span style={{ marginRight: 6 }}>{t.icon}</span>{t.label}
          </button>
        ))}
      </div>

      <div className="panel">
        {tab === "about" && (
          <>
            <div className="field"><label>Business name</label>
              <input value={form.name} onChange={(e) => set("name", e.target.value)} placeholder="e.g. VezetoLMS" /></div>
            <div className="field"><label>Primary category</label>
              <input value={form.category} onChange={(e) => set("category", e.target.value)} placeholder="e.g. Software company" /></div>
            <div className="field"><label>From the business (description)</label>
              <textarea rows={5} value={form.description} onChange={(e) => set("description", e.target.value)}
                placeholder="Tell customers what makes your business unique…" />
              <div className="muted" style={{ fontSize: 11.5, marginTop: 4 }}>{form.description.length}/750 recommended</div></div>
            <div className="field"><label>Opening date</label>
              <input value={form.opening_date} onChange={(e) => set("opening_date", e.target.value)} placeholder="YYYY-MM-DD" /></div>
          </>
        )}

        {tab === "contact" && (
          <>
            <div className="field"><label>Phone number</label>
              <input value={form.phone} onChange={(e) => set("phone", e.target.value)} placeholder="099997 34593" /></div>
            <div className="field"><label>Chat (WhatsApp link)</label>
              <input value={form.chat_url} onChange={(e) => set("chat_url", e.target.value)} placeholder="https://wa.me/919999734593" /></div>
            <div className="field"><label>Website</label>
              <input value={form.website} onChange={(e) => set("website", e.target.value)} placeholder="https://www.example.com/" /></div>
          </>
        )}

        {tab === "location" && (
          <>
            <div className="field"><label>Business address</label>
              <textarea rows={2} value={form.address} onChange={(e) => set("address", e.target.value)}
                placeholder="Street, area, city, state, PIN" /></div>
            <TagList label="Service areas" placeholder="Add a city or area…" items={form.service_area} onChange={(v) => set("service_area", v)} />
          </>
        )}

        {tab === "hours" && (
          <HoursEditor hours={form.hours} onChange={(v) => set("hours", v)} />
        )}

        {tab === "more" && (
          <>
            <TagList label="Labels (internal)" placeholder="Add a label…" items={form.labels} onChange={(v) => set("labels", v)} />
            <div style={{ height: 8 }} />
            {groups.map((g) => (
              <TagList key={g} label={g} placeholder={`Add a ${g.toLowerCase()} highlight…`}
                items={form.attributes[g] ?? []}
                onChange={(v) => set("attributes", { ...form.attributes, [g]: v })} />
            ))}
          </>
        )}
      </div>

      <div style={{ display: "flex", justifyContent: "flex-end", marginTop: 16 }}>
        <button className="btn" disabled={saving} onClick={save} style={{ width: "auto", padding: "0 28px" }}>{saving ? "Saving…" : "Save changes"}</button>
      </div>
    </div>
  );
}

/** "Get more reviews" — review link, share buttons and QR. */
function ReviewCard({ profile, qr, copied, onCopy, onReviewUrl, onSave, saving }: {
  profile: GbpProfile; qr: string | null; copied: boolean;
  onCopy: () => void; onReviewUrl: (v: string) => void; onSave: () => void; saving: boolean;
}) {
  return (
    <div className="panel" style={{ borderLeft: "4px solid var(--accent)", background: "linear-gradient(120deg, color-mix(in srgb, var(--accent) 7%, var(--card)), var(--card) 60%)" }}>
      <h3 style={{ margin: 0 }}>⭐ Get more reviews</h3>
      <p className="muted" style={{ margin: "4px 0 14px", fontSize: 13 }}>
        Give customers a link to review your business on Google — reviews build trust and help you stand out on Search &amp; Maps.
      </p>
      <div style={{ display: "flex", gap: 18, flexWrap: "wrap", alignItems: "flex-start" }}>
        <div style={{ flex: "1 1 320px", minWidth: 280 }}>
          <div className="field"><label>Review link</label>
            <div style={{ display: "flex", gap: 8 }}>
              <input value={profile.review_url} onChange={(e) => onReviewUrl(e.target.value)} placeholder="https://g.page/r/…/review" style={{ flex: 1, minWidth: 0 }} />
              <button className="btn" style={{ whiteSpace: "nowrap", width: "auto", flex: "0 0 auto", padding: "0 18px" }} onClick={() => { onCopy(); }} disabled={!profile.review_url}>{copied ? "✓ Copied" : "Copy"}</button>
            </div>
            <div className="muted" style={{ fontSize: 11.5, marginTop: 4 }}>
              Paste your Google “Get more reviews” link (g.page/r/…/review). Save to keep it and share it anywhere.
            </div>
          </div>
          {profile.review_url ? (
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginTop: 4 }}>
              <a className="btn-mini" href={profile.share.whatsapp} target="_blank" rel="noreferrer" style={{ textDecoration: "none" }}>🟢 WhatsApp</a>
              <a className="btn-mini" href={profile.share.email} target="_blank" rel="noreferrer" style={{ textDecoration: "none" }}>✉️ Email</a>
              <a className="btn-mini" href={profile.share.facebook} target="_blank" rel="noreferrer" style={{ textDecoration: "none" }}>🔵 Facebook</a>
            </div>
          ) : (
            <button className="btn-mini" onClick={onSave} disabled={saving} style={{ marginTop: 4 }}>Save to enable sharing</button>
          )}
        </div>
        <div style={{ textAlign: "center" }}>
          <div style={{ fontSize: 12.5, fontWeight: 700, marginBottom: 6 }}>Share QR code</div>
          {qr ? (
            <>
              <img src={qr} alt="Review QR code" width={160} height={160} style={{ border: "1px solid var(--border)", borderRadius: 10, background: "#fff" }} />
              <div><a className="btn-mini" href={qr} download="review-qr.png" style={{ textDecoration: "none", marginTop: 8, display: "inline-block" }}>Download QR</a></div>
            </>
          ) : (
            <div style={{ width: 160, height: 160, display: "grid", placeItems: "center", border: "1px dashed var(--border)", borderRadius: 10, fontSize: 12, color: "var(--muted, #667781)", padding: 10 }}>
              Add a review link to generate a QR code
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/** Weekly opening-hours grid. */
function HoursEditor({ hours, onChange }: { hours: GbpHours[]; onChange: (v: GbpHours[]) => void }) {
  const update = (i: number, patch: Partial<GbpHours>) =>
    onChange(hours.map((h, idx) => (idx === i ? { ...h, ...patch } : h)));
  return (
    <div>
      <div className="field" style={{ marginBottom: 6 }}><label>Opening hours</label></div>
      {hours.map((h, i) => (
        <div key={h.day} style={{ display: "flex", alignItems: "center", gap: 12, padding: "8px 0", borderBottom: "1px solid var(--border)", flexWrap: "wrap" }}>
          <div style={{ width: 110, fontWeight: 600, fontSize: 13.5 }}>{DAY_LABEL[h.day] ?? h.day}</div>
          <label style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 13, cursor: "pointer" }}>
            <input type="checkbox" checked={!h.closed} onChange={(e) => update(i, { closed: !e.target.checked })} style={{ width: "auto" }} />
            {h.closed ? "Closed" : "Open"}
          </label>
          {!h.closed && (
            <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
              <input type="time" value={h.open} onChange={(e) => update(i, { open: e.target.value })} style={{ width: 130 }} />
              <span className="muted">–</span>
              <input type="time" value={h.close} onChange={(e) => update(i, { close: e.target.value })} style={{ width: 130 }} />
            </div>
          )}
        </div>
      ))}
    </div>
  );
}

/** A simple add/remove list of short text items (tags). */
function TagList({ label, placeholder, items, onChange }: {
  label: string; placeholder: string; items: string[]; onChange: (v: string[]) => void;
}) {
  const [draft, setDraft] = useState("");
  const add = () => { const v = draft.trim(); if (v && !items.includes(v)) { onChange([...items, v]); setDraft(""); } };
  return (
    <div className="field">
      <label>{label}</label>
      <div style={{ display: "flex", gap: 8 }}>
        <input value={draft} placeholder={placeholder} onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); add(); } }} style={{ flex: 1 }} />
        <button className="btn-mini" type="button" onClick={add}>+ Add</button>
      </div>
      {items.length > 0 && (
        <div style={{ display: "flex", flexWrap: "wrap", gap: 6, marginTop: 8 }}>
          {items.map((it) => (
            <span key={it} style={{ display: "inline-flex", alignItems: "center", gap: 6, fontSize: 12.5, padding: "4px 10px", borderRadius: 999, border: "1px solid var(--border)", background: "var(--card)" }}>
              {it}
              <button type="button" onClick={() => onChange(items.filter((x) => x !== it))}
                style={{ border: "none", background: "none", cursor: "pointer", color: "var(--muted, #667781)", fontSize: 14, lineHeight: 1 }}>×</button>
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
