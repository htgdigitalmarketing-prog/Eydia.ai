"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, TeamMember, RoleOption, FeatureOption } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

type FormState = { name: string; email: string; password: string; role: string; features: string[] };
// New agents start with the most common customer-facing tools ticked.
const EMPTY: FormState = { name: "", email: "", password: "", role: "agent", features: ["inbox", "contacts"] };

export default function TeamPage() {
  const me = useUser();
  const [members, setMembers] = useState<TeamMember[]>([]);
  const [roles, setRoles] = useState<RoleOption[]>([]);
  const [features, setFeatures] = useState<FeatureOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY);
  const [submitting, setSubmitting] = useState(false);

  const canManage = (me.permissions ?? []).includes("team.invite");
  const canRemove = (me.permissions ?? []).includes("team.remove");
  const featureLabel = (k: string) => features.find((f) => f.key === k)?.label ?? k;

  // A feature checkbox is only offered if the company's plan includes it.
  const FEATURE_PLAN_MAP: Record<string, string> = { social: "social", chatbot: "chatbot", automations: "automations", analytics: "reports" };
  const planFeatures = me.plan_features;
  const visibleFeatures = features.filter((f) => {
    const req = FEATURE_PLAN_MAP[f.key];
    return !req || planFeatures == null || planFeatures.includes(req);
  });

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const res = await api.team.list(token);
      setMembers(res.members);
      setRoles(res.roles);
      setFeatures(res.features);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  function flash(msg: string) {
    setNotice(msg);
    setTimeout(() => setNotice(null), 3500);
  }

  function openAdd() {
    setEditingId(null);
    setForm({ ...EMPTY });
    setShowForm(true);
  }

  function openEdit(m: TeamMember) {
    setEditingId(m.id);
    setForm({ name: m.name, email: m.email, password: "", role: m.role, features: [...m.features] });
    setShowForm(true);
  }

  function toggleFeature(key: string) {
    setForm((f) => ({ ...f, features: f.features.includes(key) ? f.features.filter((k) => k !== key) : [...f.features, key] }));
  }

  function allFeatures(on: boolean) {
    setForm((f) => ({ ...f, features: on ? visibleFeatures.map((x) => x.key) : [] }));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSubmitting(true);
    setError(null);
    const feats = form.role === "admin" ? [] : form.features;
    try {
      if (editingId) {
        await api.team.update(token, editingId, { name: form.name, role: form.role, features: feats, password: form.password || undefined });
        flash(`${form.name} updated.`);
      } else {
        await api.team.create(token, { name: form.name, email: form.email, password: form.password, role: form.role, features: feats });
        flash(`${form.name} added.`);
      }
      setShowForm(false);
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSubmitting(false);
    }
  }

  async function toggleActive(m: TeamMember) {
    const token = getToken();
    if (!token) return;
    try {
      const res = await api.team.toggle(token, m.id);
      setMembers((prev) => prev.map((x) => (x.id === m.id ? res.member : x)));
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function removeMember(m: TeamMember) {
    const token = getToken();
    if (!token) return;
    if (!confirm(`Remove ${m.name}? They will lose access.`)) return;
    try {
      await api.team.remove(token, m.id);
      setMembers((prev) => prev.filter((x) => x.id !== m.id));
      flash(`${m.name} removed.`);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  const initials = (name: string) => name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase();

  return (
    <div>
      <PageHeader
        title="Team"
        subtitle="Add agents and choose exactly which features each one can use."
        action={canManage ? <button className="btn" onClick={openAdd}>+ Add member</button> : undefined}
      />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      {loading ? (
        <LoadingBlock label="Loading team…" />
      ) : (
        <div className="panel" style={{ padding: 0, overflow: "hidden" }}>
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", fontSize: 12, color: "#667781", background: "#f8fafb", borderBottom: "1px solid #eef1f2" }}>
                <th style={{ padding: "12px 14px" }}>Member</th>
                <th style={{ padding: "12px 14px" }}>Role</th>
                <th style={{ padding: "12px 14px" }}>Access</th>
                <th style={{ padding: "12px 14px" }}>Status</th>
                <th style={{ padding: "12px 14px", textAlign: "right" }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {members.map((m) => {
                const isMe = m.email === me.email;
                return (
                  <tr key={m.id} style={{ borderBottom: "1px solid #f4f6f7" }}>
                    <td style={{ padding: "12px 14px" }}>
                      <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                        <div style={{ width: 36, height: 36, borderRadius: "50%", background: "linear-gradient(135deg,#3b82f6,#22c7f5)", color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, fontSize: 13, overflow: "hidden", flexShrink: 0 }}>
                          {m.avatar_url
                            ? /* eslint-disable-next-line @next/next/no-img-element */ <img src={m.avatar_url} alt="" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                            : initials(m.name)}
                        </div>
                        <div>
                          <div style={{ fontWeight: 600 }}>{m.name} {isMe && <span className="muted" style={{ fontSize: 12 }}>(you)</span>}</div>
                          <div className="muted" style={{ fontSize: 12 }}>{m.email}</div>
                        </div>
                      </div>
                    </td>
                    <td style={{ padding: "12px 14px" }}>
                      <span style={{
                        background: m.role === "admin" ? "#e7f0ff" : "#eef1f2",
                        color: m.role === "admin" ? "#1877f2" : "#54656f",
                        padding: "3px 10px", borderRadius: 999, fontSize: 12, fontWeight: 700,
                      }}>{m.role_label}</span>
                    </td>
                    <td style={{ padding: "12px 14px", maxWidth: 300 }}>
                      {m.role === "admin" ? (
                        <span className="muted" style={{ fontSize: 12 }}>Full access</span>
                      ) : m.features.length === 0 ? (
                        <span className="muted" style={{ fontSize: 12 }}>No features</span>
                      ) : (
                        <div style={{ display: "flex", flexWrap: "wrap", gap: 4 }}>
                          {m.features.slice(0, 5).map((k) => (
                            <span key={k} style={{ background: "#eef1f2", color: "#54656f", padding: "1px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600 }}>{featureLabel(k)}</span>
                          ))}
                          {m.features.length > 5 && <span className="muted" style={{ fontSize: 11 }}>+{m.features.length - 5}</span>}
                        </div>
                      )}
                    </td>
                    <td style={{ padding: "12px 14px" }}>
                      <span style={{
                        background: m.status === "active" ? "#e7f7ef" : "#fdecec",
                        color: m.status === "active" ? "#0a7d47" : "#c53030",
                        padding: "3px 10px", borderRadius: 999, fontSize: 12, fontWeight: 600, textTransform: "capitalize",
                      }}>{m.status}</span>
                    </td>
                    <td style={{ padding: "12px 14px", textAlign: "right", whiteSpace: "nowrap" }}>
                      {canManage && !isMe && <button className="btn-mini" onClick={() => openEdit(m)}>Edit</button>}
                      {canManage && !isMe && <button className="btn-mini" style={{ marginLeft: 6 }} onClick={() => toggleActive(m)}>{m.status === "active" ? "Deactivate" : "Activate"}</button>}
                      {canRemove && !isMe && <button className="btn-mini" style={{ color: "#c53030", marginLeft: 6 }} onClick={() => removeMember(m)}>Remove</button>}
                      {isMe && <span className="muted" style={{ fontSize: 12 }}>—</span>}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {showForm && (
        <div className="msg-info-overlay" onClick={() => setShowForm(false)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 480 }}>
            <h2 style={{ marginTop: 0 }}>{editingId ? "Edit member" : "Add member"}</h2>
            <form onSubmit={submit}>
              <div className="field">
                <label>Full name</label>
                <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required placeholder="Jane Doe" />
              </div>
              {!editingId && (
                <>
                  <div className="field">
                    <label>Email</label>
                    <input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} required placeholder="jane@company.com" />
                  </div>
                  <div className="field">
                    <label>Temporary password</label>
                    <input type="text" value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} required minLength={8} placeholder="Min 8 characters" />
                    <span className="muted" style={{ fontSize: 12 }}>Share this with them; they can change it later.</span>
                  </div>
                </>
              )}
              {editingId && (
                <div className="field">
                  <label>Reset password <span className="muted">(optional)</span></label>
                  <input type="text" value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} minLength={8} placeholder="Leave blank to keep current password" />
                  <span className="muted" style={{ fontSize: 12 }}>Enter a new password (min 8 chars) to change this agent&apos;s login.</span>
                </div>
              )}
              {roles.length > 1 ? (
                <div className="field">
                  <label>Role</label>
                  <select value={form.role} onChange={(e) => setForm((f) => ({ ...f, role: e.target.value }))}>
                    {roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                  </select>
                  <span className="muted" style={{ fontSize: 12 }}>
                    {form.role === "admin" ? "Admins get full access to everything in the workspace." : "Agents see only the features you tick below."}
                  </span>
                </div>
              ) : (
                <div className="field">
                  <label>Role</label>
                  <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                    <span style={{ background: "#eef1f2", color: "#54656f", padding: "4px 12px", borderRadius: 999, fontSize: 13, fontWeight: 700 }}>Agent</span>
                    <span className="muted" style={{ fontSize: 12 }}>Agents see only the features you tick below.</span>
                  </div>
                </div>
              )}

              {form.role === "agent" && (
                <div className="field">
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8 }}>
                    <label style={{ margin: 0 }}>Features this agent can access</label>
                    <div style={{ display: "flex", gap: 8 }}>
                      <button type="button" className="btn-mini" onClick={() => allFeatures(true)}>All</button>
                      <button type="button" className="btn-mini" onClick={() => allFeatures(false)}>None</button>
                    </div>
                  </div>
                  <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8, border: "1px solid var(--border)", borderRadius: 10, padding: 12, maxHeight: 240, overflowY: "auto" }}>
                    {visibleFeatures.map((f) => (
                      <label key={f.key} style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13, cursor: "pointer", padding: "4px 2px" }}>
                        <input type="checkbox" checked={form.features.includes(f.key)} onChange={() => toggleFeature(f.key)} />
                        <span>{f.label}</span>
                      </label>
                    ))}
                  </div>
                  <span className="muted" style={{ fontSize: 12, marginTop: 6, display: "block" }}>
                    {form.features.length} of {visibleFeatures.length} selected. Dashboard is always visible.
                  </span>
                </div>
              )}

              <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
                <button className="btn" disabled={submitting}>{submitting ? "Saving…" : editingId ? "Save changes" : "Add member"}</button>
                <button type="button" className="btn-mini" onClick={() => setShowForm(false)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
