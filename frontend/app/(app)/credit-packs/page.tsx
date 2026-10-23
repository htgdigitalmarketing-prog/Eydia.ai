"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, AdminCreditPack } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

const inr = (n: number) => "₹" + n.toLocaleString("en-IN", { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });

type Form = { name: string; credits: string; price: string; gst_rate: string; sort_order: string; is_active: boolean };
const BLANK: Form = { name: "", credits: "", price: "", gst_rate: "18", sort_order: "0", is_active: true };

export default function CreditPacksPage() {
  const me = useUser();
  const [packs, setPacks] = useState<AdminCreditPack[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState<Form>(BLANK);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    const t = getToken(); if (!t) return;
    setLoading(true);
    try { setPacks((await api.admin.creditPacks(t)).packs); }
    catch (e) { setError((e as ApiError).message); } finally { setLoading(false); }
  }, []);
  useEffect(() => { load(); }, [load]);

  function flash(m: string) { setNotice(m); setTimeout(() => setNotice(null), 3000); }
  function openNew() { setEditingId(null); setForm(BLANK); setShowForm(true); }
  function openEdit(p: AdminCreditPack) {
    setEditingId(p.id);
    setForm({ name: p.name, credits: String(p.credits), price: String(p.price), gst_rate: String(p.gst_rate), sort_order: String(p.sort_order), is_active: p.is_active });
    setShowForm(true);
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    const t = getToken(); if (!t) return;
    setSaving(true); setError(null);
    const body = { name: form.name, credits: Number(form.credits), price: Number(form.price), gst_rate: Number(form.gst_rate), sort_order: Number(form.sort_order), is_active: form.is_active };
    try {
      if (editingId) { await api.admin.updateCreditPack(t, editingId, body); flash("Pack updated."); }
      else { await api.admin.createCreditPack(t, body); flash("Pack created."); }
      setShowForm(false); load();
    } catch (e2) { setError((e2 as ApiError).message); } finally { setSaving(false); }
  }
  async function toggle(p: AdminCreditPack) { const t = getToken(); if (!t) return; try { const r = await api.admin.toggleCreditPack(t, p.id); setPacks((prev) => prev.map((x) => x.id === p.id ? r.pack : x)); } catch (e) { setError((e as ApiError).message); } }
  async function remove(p: AdminCreditPack) { const t = getToken(); if (!t || !confirm(`Delete "${p.name}"?`)) return; try { await api.admin.removeCreditPack(t, p.id); setPacks((prev) => prev.filter((x) => x.id !== p.id)); flash("Pack deleted."); } catch (e) { setError((e as ApiError).message); } }

  if (!me.is_super_admin) return <div className="panel" style={{ padding: 28, textAlign: "center" }}><span className="muted">Super admin only.</span></div>;

  const price = Number(form.price) || 0;
  const rate = Number(form.gst_rate) || 0;
  const base = price / (1 + rate / 100);
  const gst = price - base;

  return (
    <div>
      <PageHeader title="Credit Packs" subtitle="Create credit packs clients can buy. Pause any pack to hide it."
        action={<button className="btn" onClick={openNew}>+ New package</button>} />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      {loading ? <LoadingBlock /> : packs.length === 0 ? (
        <div className="panel" style={{ textAlign: "center", padding: 40 }}>
          <div style={{ fontSize: 40 }}>⚡</div>
          <h3 style={{ margin: "12px 0 4px" }}>No credit packs yet</h3>
          <p className="muted" style={{ marginBottom: 16 }}>Create packs your clients can buy to top up AI credits.</p>
          <button className="btn" onClick={openNew}>Create your first pack</button>
        </div>
      ) : (
        <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(290px,1fr))", gap: 14 }}>
          {packs.map((p) => (
            <div key={p.id} className="panel">
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: 8 }}>
                <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
                  <span style={{ width: 38, height: 38, borderRadius: 10, display: "grid", placeItems: "center", fontSize: 18, background: "#f3eeff", color: "#7b2ff7" }}>⚡</span>
                  <div>
                    <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                      <strong>{p.name}</strong>
                      <span style={{ fontSize: 11, fontWeight: 700, padding: "2px 8px", borderRadius: 999, background: p.is_active ? "#e7f7ef" : "#eef1f2", color: p.is_active ? "#0a7d47" : "#667781" }}>{p.is_active ? "Active" : "Paused"}</span>
                    </div>
                    <div className="muted" style={{ fontSize: 12 }}>Sort: {p.sort_order}</div>
                  </div>
                </div>
              </div>
              <div style={{ display: "flex", justifyContent: "space-between", gap: 8, marginTop: 14, textAlign: "center" }}>
                <div><div style={{ fontSize: 18, fontWeight: 800, color: "var(--accent)" }}>{p.credits.toLocaleString("en-IN")}</div><div className="muted" style={{ fontSize: 11 }}>Credits</div></div>
                <div><div style={{ fontSize: 18, fontWeight: 800, color: "var(--accent)" }}>{inr(p.price)}</div><div className="muted" style={{ fontSize: 11 }}>Price (incl. GST)</div></div>
                <div><div style={{ fontSize: 18, fontWeight: 800, color: "var(--accent)" }}>{inr(p.gst)}</div><div className="muted" style={{ fontSize: 11 }}>GST ({p.gst_rate}%)</div></div>
              </div>
              <div style={{ display: "flex", gap: 8, marginTop: 14 }}>
                <button className="btn-mini" onClick={() => toggle(p)}>{p.is_active ? "⏸ Pause" : "▶ Activate"}</button>
                <button className="btn-mini" onClick={() => openEdit(p)}>✎ Edit</button>
                <button className="btn-mini danger" style={{ marginLeft: "auto" }} onClick={() => remove(p)}>🗑</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {showForm && (
        <div className="msg-info-overlay" onClick={() => setShowForm(false)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ width: "min(460px, 94vw)", maxWidth: "94vw" }}>
            <h2 style={{ marginTop: 0 }}>{editingId ? "Edit package" : "New package"}</h2>
            <form onSubmit={save}>
              <div className="field"><label>Package name</label>
                <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required placeholder="e.g. Booster Pack" style={inp} /></div>
              <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
                <div className="field" style={{ flex: "1 1 120px", minWidth: 0 }}><label>Credits</label>
                  <input type="number" min={1} value={form.credits} onChange={(e) => setForm((f) => ({ ...f, credits: e.target.value }))} required style={inp} /></div>
                <div className="field" style={{ flex: "1 1 120px", minWidth: 0 }}><label>Price ₹ (incl GST)</label>
                  <input type="number" min={0} step="0.01" value={form.price} onChange={(e) => setForm((f) => ({ ...f, price: e.target.value }))} required style={inp} /></div>
                <div className="field" style={{ flex: "1 1 80px", minWidth: 0 }}><label>GST %</label>
                  <input type="number" min={0} max={50} value={form.gst_rate} onChange={(e) => setForm((f) => ({ ...f, gst_rate: e.target.value }))} style={inp} /></div>
              </div>
              <div className="panel" style={{ background: "#eef1ff", color: "var(--accent-dark)", fontSize: 13, padding: "8px 12px", marginBottom: 10 }}>
                Base {inr(Number(base.toFixed(2)))} + {rate}% GST {inr(Number(gst.toFixed(2)))} = <b>{inr(Number(price.toFixed(2)))}</b>
              </div>
              <div style={{ display: "flex", gap: 10, alignItems: "flex-end", flexWrap: "wrap" }}>
                <div className="field" style={{ flex: "1 1 120px", minWidth: 0 }}><label>Sort order</label>
                  <input type="number" min={0} value={form.sort_order} onChange={(e) => setForm((f) => ({ ...f, sort_order: e.target.value }))} style={inp} /></div>
                <label style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 14, padding: "10px 0" }}>
                  <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} /> Active
                </label>
              </div>
              <div style={{ display: "flex", gap: 8, justifyContent: "flex-end", marginTop: 8 }}>
                <button type="button" className="btn-mini" onClick={() => setShowForm(false)}>Cancel</button>
                <button className="btn" disabled={saving} style={{ width: "auto", padding: "11px 22px" }}>{saving ? "Saving…" : editingId ? "Save package" : "Create package"}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

const inp: React.CSSProperties = { width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, minWidth: 0 };
