"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, AdminWorkItem } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";
import { toast, toastError, confirmDialog } from "@/lib/ui";

const BLANK = { title: "", category: "Websites", image_url: "", link: "", description: "", sort_order: 0, is_active: true };

export default function WorkPage() {
  const user = useUser();
  const [items, setItems] = useState<AdminWorkItem[]>([]);
  const [categories, setCategories] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ ...BLANK });
  const [editingId, setEditingId] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    const t = getToken();
    if (!t) return;
    setLoading(true);
    try {
      const r = await api.admin.work(t);
      setItems(r.items); setCategories(r.categories);
    } catch (e) { toastError(e as ApiError); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { load(); }, [load]);

  if (!user.is_super_admin) {
    return <div className="panel" style={{ textAlign: "center", padding: 40 }}>This area is for the platform super admin only.</div>;
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const t = getToken();
    if (!t) return;
    setSaving(true);
    try {
      const body = { ...form, sort_order: Number(form.sort_order) || 0 };
      if (editingId) { await api.admin.updateWork(t, editingId, body); toast("Work updated.", "success"); }
      else { await api.admin.createWork(t, body); toast("Work added.", "success"); }
      setForm({ ...BLANK }); setEditingId(null);
      await load();
    } catch (e) { toastError(e as ApiError); }
    finally { setSaving(false); }
  }

  function edit(w: AdminWorkItem) {
    setEditingId(w.id);
    setForm({ title: w.title, category: w.category, image_url: w.image_url ?? "", link: w.link ?? "", description: w.description ?? "", sort_order: w.sort_order, is_active: w.is_active });
    if (typeof window !== "undefined") document.querySelector(".main-content")?.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function remove(w: AdminWorkItem) {
    const t = getToken();
    if (!t || !(await confirmDialog({ message: `Remove "${w.title}" from Our Work?`, danger: true }))) return;
    try { await api.admin.deleteWork(t, w.id); toast("Work removed.", "success"); await load(); }
    catch (e) { toastError(e as ApiError); }
  }

  async function toggle(w: AdminWorkItem) {
    const t = getToken();
    if (!t) return;
    try { await api.admin.updateWork(t, w.id, { is_active: !w.is_active }); await load(); }
    catch (e) { toastError(e as ApiError); }
  }

  // Group items by category for display.
  const grouped = items.reduce<Record<string, AdminWorkItem[]>>((acc, w) => {
    (acc[w.category] ||= []).push(w);
    return acc;
  }, {});
  const catList = Array.from(new Set([...categories, ...Object.keys(grouped)]));

  return (
    <>
      <PageHeader title="Our Work" subtitle="Your portfolio, shown category-wise on the public landing page." />

      {/* Add / edit form */}
      <div className="panel" style={{ maxWidth: 720 }}>
        <h2 style={{ marginTop: 0 }}>{editingId ? "Edit work" : "Add a work item"}</h2>
        <form onSubmit={submit}>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            <div className="field" style={{ flex: 2, minWidth: 200 }}>
              <label>Title</label>
              <input value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} required placeholder="Acme Realty website" />
            </div>
            <div className="field" style={{ flex: 1, minWidth: 160 }}>
              <label>Category</label>
              <input list="work-cats" value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))} required placeholder="Websites" />
              <datalist id="work-cats">{categories.map((c) => <option key={c} value={c} />)}</datalist>
            </div>
            <div className="field" style={{ flex: 1, minWidth: 90 }}>
              <label>Order</label>
              <input type="number" min={0} value={form.sort_order} onChange={(e) => setForm((f) => ({ ...f, sort_order: Number(e.target.value) }))} />
            </div>
          </div>
          <div className="field">
            <label>Image URL <span className="muted">(thumbnail / screenshot)</span></label>
            <input value={form.image_url} onChange={(e) => setForm((f) => ({ ...f, image_url: e.target.value }))} placeholder="https://…/screenshot.png" />
          </div>
          <div className="field">
            <label>Link <span className="muted">(live site / case study — optional)</span></label>
            <input value={form.link} onChange={(e) => setForm((f) => ({ ...f, link: e.target.value }))} placeholder="https://client.com" />
          </div>
          <div className="field">
            <label>Description <span className="muted">(optional)</span></label>
            <textarea value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} rows={2} placeholder="What you built / delivered" style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, fontFamily: "inherit", resize: "vertical" }} />
          </div>
          {form.image_url.trim() && (
            <div style={{ margin: "4px 0 12px" }}>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={form.image_url} alt="preview" style={{ height: 80, maxWidth: 220, objectFit: "cover", border: "1px solid var(--border)", borderRadius: 10 }} />
            </div>
          )}
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn" disabled={saving} style={{ width: "auto", padding: "10px 22px" }}>{saving ? "Saving…" : editingId ? "Save changes" : "+ Add work"}</button>
            {editingId && <button type="button" className="btn-mini" onClick={() => { setEditingId(null); setForm({ ...BLANK }); }}>Cancel</button>}
          </div>
        </form>
      </div>

      {/* List grouped by category */}
      {loading ? <LoadingBlock label="Loading work…" /> : items.length === 0 ? (
        <div className="panel"><p className="muted">No work added yet. Add your first project above — it appears on your landing page under its category.</p></div>
      ) : (
        catList.filter((c) => grouped[c]?.length).map((cat) => (
          <div key={cat} className="panel">
            <h2 style={{ marginTop: 0 }}>{cat} <span className="muted" style={{ fontSize: 13, fontWeight: 400 }}>({grouped[cat].length})</span></h2>
            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(220px, 1fr))", gap: 12 }}>
              {grouped[cat].map((w) => (
                <div key={w.id} style={{ border: "1px solid var(--border)", borderRadius: 14, overflow: "hidden", display: "flex", flexDirection: "column", opacity: w.is_active ? 1 : 0.55 }}>
                  <div style={{ aspectRatio: "16 / 10", background: "#eef1ff", display: "grid", placeItems: "center" }}>
                    {w.image_url
                      // eslint-disable-next-line @next/next/no-img-element
                      ? <img src={w.image_url} alt={w.title} style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                      : <span style={{ fontWeight: 800, color: "var(--accent)" }}>{w.category}</span>}
                  </div>
                  <div style={{ padding: 10, display: "flex", flexDirection: "column", gap: 6, flex: 1 }}>
                    <div style={{ fontWeight: 700, fontSize: 14 }}>{w.title}</div>
                    {w.description && <div className="muted" style={{ fontSize: 12, display: "-webkit-box", WebkitLineClamp: 2, WebkitBoxOrient: "vertical", overflow: "hidden" }}>{w.description}</div>}
                    <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginTop: "auto", paddingTop: 4 }}>
                      <button className="btn-mini" onClick={() => edit(w)}>Edit</button>
                      <button className="btn-mini" onClick={() => toggle(w)}>{w.is_active ? "Hide" : "Show"}</button>
                      <button className="btn-mini" style={{ color: "#c53030" }} onClick={() => remove(w)}>Delete</button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        ))
      )}
    </>
  );
}
