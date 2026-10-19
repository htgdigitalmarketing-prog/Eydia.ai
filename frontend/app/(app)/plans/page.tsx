"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, AdminPlan } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

const LIMIT_LABELS: Record<string, string> = {
  max_agents: "Max agents", max_contacts: "Max contacts", max_campaigns: "Max campaigns",
  max_chatbots: "Max chatbots", max_templates: "Max templates",
  max_locations: "Max GBP locations", max_leads: "Max leads",
};
const FEATURE_LABELS: Record<string, string> = {
  dash_whatsapp: "WhatsApp Marketing", dash_gbp: "Google Reviews / GBP", dash_leads: "Lead Management",
  reports: "Reports", advanced_reports: "Advanced reports", export: "Export",
  social: "Social publishing", chatbot: "Chatbot", automations: "Automations", api_access: "API access",
};
// The three dashboard-access flags, surfaced as a dedicated control so a plan
// explicitly decides which dashboards it unlocks.
const DASHBOARD_KEYS = ["dash_whatsapp", "dash_gbp", "dash_leads"];
const DASHBOARD_ICONS: Record<string, string> = { dash_whatsapp: "💬", dash_gbp: "⭐", dash_leads: "🎯" };
const label = (map: Record<string, string>, k: string) => map[k] ?? k.replace(/_/g, " ");

type FormState = {
  name: string; price: string; billing_period: string; description: string;
  limits: Record<string, string>; features: Record<string, boolean>;
  is_active: boolean; is_public: boolean;
};

export default function PlansPage() {
  const me = useUser();
  const isSuper = !!me.is_super_admin;
  const [plans, setPlans] = useState<AdminPlan[]>([]);
  const [limitKeys, setLimitKeys] = useState<string[]>([]);
  const [featureKeys, setFeatureKeys] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState<FormState>(blank([], []));
  const [saving, setSaving] = useState(false);

  function blank(lk: string[], fk: string[]): FormState {
    return {
      name: "", price: "0", billing_period: "monthly", description: "",
      limits: Object.fromEntries(lk.map((k) => [k, ""])),
      features: Object.fromEntries(fk.map((k) => [k, false])),
      is_active: true, is_public: true,
    };
  }

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const res = await api.admin.plans(token);
      setPlans(res.plans);
      setLimitKeys(res.limit_keys);
      setFeatureKeys(res.feature_keys);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { if (isSuper) load(); else setLoading(false); }, [isSuper, load]);

  function flash(m: string) { setNotice(m); setTimeout(() => setNotice(null), 3500); }

  function openAdd() {
    setEditingId(null);
    setForm(blank(limitKeys, featureKeys));
    setShowForm(true);
  }

  function openEdit(p: AdminPlan) {
    setEditingId(p.id);
    const limits: Record<string, string> = {};
    limitKeys.forEach((k) => { const v = p.limits[k]; limits[k] = v === undefined || v < 0 ? "" : String(v); });
    const features: Record<string, boolean> = {};
    featureKeys.forEach((k) => { features[k] = !!p.features[k]; });
    setForm({
      name: p.name, price: String(p.price), billing_period: p.billing_period, description: p.description ?? "",
      limits, features, is_active: p.is_active, is_public: p.is_public,
    });
    setShowForm(true);
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSaving(true);
    setError(null);
    const body = {
      name: form.name,
      price: Number(form.price) || 0,
      billing_period: form.billing_period,
      description: form.description || null,
      limits: form.limits, // "" → unlimited (backend stores -1)
      features: form.features,
      is_active: form.is_active,
      is_public: form.is_public,
    };
    try {
      if (editingId) { await api.admin.updatePlan(token, editingId, body); flash("Plan updated."); }
      else { await api.admin.createPlan(token, body); flash("Plan created."); }
      setShowForm(false);
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSaving(false);
    }
  }

  async function toggle(p: AdminPlan) {
    const token = getToken();
    if (!token) return;
    try { const r = await api.admin.togglePlan(token, p.id); setPlans((prev) => prev.map((x) => x.id === p.id ? r.plan : x)); }
    catch (err) { setError((err as ApiError).message); }
  }

  async function remove(p: AdminPlan) {
    const token = getToken();
    if (!token) return;
    if (!confirm(`Delete the "${p.name}" plan?`)) return;
    try { await api.admin.removePlan(token, p.id); setPlans((prev) => prev.filter((x) => x.id !== p.id)); flash("Plan deleted."); }
    catch (err) { setError((err as ApiError).message); }
  }

  const showLimit = (v: number | undefined) => (v === undefined || v < 0 ? "Unlimited" : v.toLocaleString("en-IN"));

  if (!isSuper) {
    return <div><PageHeader title="Plans" /><div className="panel"><p className="muted">Super admin only.</p></div></div>;
  }

  return (
    <div>
      <PageHeader title="Plans" subtitle="Create subscription plans with dynamic limits and features." action={<button className="btn" onClick={openAdd}>+ New plan</button>} />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      {loading ? (
        <LoadingBlock label="Loading plans…" />
      ) : plans.length === 0 ? (
        <div className="panel" style={{ textAlign: "center", padding: 40 }}>
          <div style={{ fontSize: 40 }}>💳</div>
          <h3 style={{ margin: "12px 0 4px" }}>No plans yet</h3>
          <p className="muted" style={{ marginBottom: 16 }}>Create your first subscription plan.</p>
          <button className="btn" onClick={openAdd}>Create a plan</button>
        </div>
      ) : (
        <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(280px, 1fr))", gap: 14 }}>
          {plans.map((p) => (
            <div key={p.id} className="panel" style={{ opacity: p.is_active ? 1 : 0.6 }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start" }}>
                <h3 style={{ margin: 0 }}>{p.name}</h3>
                <span style={{ background: p.is_active ? "#e7f7ef" : "#eef1f2", color: p.is_active ? "#0a7d47" : "#667781", padding: "2px 9px", borderRadius: 999, fontSize: 11, fontWeight: 700 }}>{p.is_active ? "Active" : "Off"}</span>
              </div>
              <div style={{ fontSize: 22, fontWeight: 800, margin: "6px 0" }}>
                ₹{p.price.toLocaleString("en-IN")}<span className="muted" style={{ fontSize: 13, fontWeight: 400 }}> / {p.billing_period === "yearly" ? "yr" : "mo"}</span>
              </div>
              <div style={{ display: "flex", flexWrap: "wrap", gap: 4, margin: "8px 0 10px" }}>
                {DASHBOARD_KEYS.map((k) => (
                  <span key={k} title={label(FEATURE_LABELS, k)}
                    style={{ background: p.features[k] ? "#e7f7ef" : "#f3f4f6", color: p.features[k] ? "#0a7d47" : "#9aa3ab", padding: "2px 9px", borderRadius: 999, fontSize: 11, fontWeight: 700, textDecoration: p.features[k] ? "none" : "line-through" }}>
                    {DASHBOARD_ICONS[k]} {label(FEATURE_LABELS, k).replace(" Marketing", "").replace(" / GBP", "").replace(" Management", "")}
                  </span>
                ))}
              </div>
              <div style={{ fontSize: 13, color: "#54656f", display: "grid", gap: 3, marginBottom: 10 }}>
                {limitKeys.map((k) => <div key={k}>{label(LIMIT_LABELS, k)}: <strong>{showLimit(p.limits[k])}</strong></div>)}
              </div>
              <div style={{ display: "flex", flexWrap: "wrap", gap: 4, marginBottom: 12 }}>
                {featureKeys.filter((k) => !DASHBOARD_KEYS.includes(k)).filter((k) => p.features[k]).map((k) => (
                  <span key={k} style={{ background: "#e7f0ff", color: "#1877f2", padding: "1px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600 }}>{label(FEATURE_LABELS, k)}</span>
                ))}
              </div>
              <div className="muted" style={{ fontSize: 12, marginBottom: 10 }}>{p.subscribers} companies on this plan</div>
              <div style={{ display: "flex", gap: 8 }}>
                <button className="btn-mini" onClick={() => openEdit(p)}>Edit</button>
                <button className="btn-mini" onClick={() => toggle(p)}>{p.is_active ? "Deactivate" : "Activate"}</button>
                <button className="btn-mini" style={{ color: "#c53030" }} onClick={() => remove(p)}>Delete</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {showForm && (
        <div className="msg-info-overlay" onClick={() => setShowForm(false)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 520 }}>
            <h2 style={{ marginTop: 0 }}>{editingId ? "Edit plan" : "New plan"}</h2>
            <form onSubmit={submit}>
              <div style={{ display: "flex", gap: 10 }}>
                <div className="field" style={{ flex: 2 }}>
                  <label>Plan name</label>
                  <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required placeholder="Professional" />
                </div>
                <div className="field" style={{ flex: 1 }}>
                  <label>Price (₹)</label>
                  <input type="number" min={0} value={form.price} onChange={(e) => setForm((f) => ({ ...f, price: e.target.value }))} />
                </div>
                <div className="field" style={{ flex: 1 }}>
                  <label>Billing</label>
                  <select value={form.billing_period} onChange={(e) => setForm((f) => ({ ...f, billing_period: e.target.value }))}>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                  </select>
                </div>
              </div>

              <div className="field">
                <label>Limits <span className="muted">(leave blank = unlimited)</span></label>
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8 }}>
                  {limitKeys.map((k) => (
                    <div key={k} style={{ display: "flex", alignItems: "center", gap: 6 }}>
                      <span style={{ fontSize: 12, flex: 1 }}>{label(LIMIT_LABELS, k)}</span>
                      <input type="number" min={0} value={form.limits[k] ?? ""} placeholder="∞"
                        onChange={(e) => setForm((f) => ({ ...f, limits: { ...f.limits, [k]: e.target.value } }))}
                        style={{ width: 90, padding: "6px 8px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }} />
                    </div>
                  ))}
                </div>
              </div>

              <div className="field">
                <label>Dashboard access <span className="muted">(which dashboards this plan unlocks)</span></label>
                <div style={{ display: "grid", gridTemplateColumns: "1fr", gap: 6, border: "1px solid var(--border)", borderRadius: 10, padding: 12 }}>
                  {DASHBOARD_KEYS.filter((k) => featureKeys.includes(k)).map((k) => (
                    <label key={k} style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13.5, cursor: "pointer", fontWeight: 600 }}>
                      <input type="checkbox" checked={!!form.features[k]} onChange={(e) => setForm((f) => ({ ...f, features: { ...f.features, [k]: e.target.checked } }))} />
                      <span>{DASHBOARD_ICONS[k]} {label(FEATURE_LABELS, k)}</span>
                    </label>
                  ))}
                </div>
              </div>

              <div className="field">
                <label>Features <span className="muted">(per-dashboard capabilities)</span></label>
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 6, border: "1px solid var(--border)", borderRadius: 10, padding: 12 }}>
                  {featureKeys.filter((k) => !DASHBOARD_KEYS.includes(k)).map((k) => (
                    <label key={k} style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13, cursor: "pointer" }}>
                      <input type="checkbox" checked={!!form.features[k]} onChange={(e) => setForm((f) => ({ ...f, features: { ...f.features, [k]: e.target.checked } }))} />
                      <span>{label(FEATURE_LABELS, k)}</span>
                    </label>
                  ))}
                </div>
              </div>

              <label style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 12 }}>
                <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} /> Active
              </label>

              <div style={{ display: "flex", gap: 8 }}>
                <button className="btn" disabled={saving}>{saving ? "Saving…" : editingId ? "Save plan" : "Create plan"}</button>
                <button type="button" className="btn-mini" onClick={() => setShowForm(false)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
