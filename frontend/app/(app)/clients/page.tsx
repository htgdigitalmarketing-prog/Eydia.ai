"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, AdminClient } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";
import { toast, toastError, confirmDialog } from "@/lib/ui";

const BLANK = { name: "", logo_url: "", website: "", sort_order: 0, is_active: true };

export default function ClientsPage() {
  const user = useUser();
  const [clients, setClients] = useState<AdminClient[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ ...BLANK });
  const [editingId, setEditingId] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    const t = getToken();
    if (!t) return;
    setLoading(true);
    try {
      setClients((await api.admin.clients(t)).clients);
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
      if (editingId) { await api.admin.updateClient(t, editingId, body); toast("Client updated.", "success"); }
      else { await api.admin.createClient(t, body); toast("Client added.", "success"); }
      setForm({ ...BLANK }); setEditingId(null);
      await load();
    } catch (e) { toastError(e as ApiError); }
    finally { setSaving(false); }
  }

  function edit(c: AdminClient) {
    setEditingId(c.id);
    setForm({ name: c.name, logo_url: c.logo_url ?? "", website: c.website ?? "", sort_order: c.sort_order, is_active: c.is_active });
    if (typeof window !== "undefined") document.querySelector(".main-content")?.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function remove(c: AdminClient) {
    const t = getToken();
    if (!t || !(await confirmDialog({ message: `Remove "${c.name}" from Our Clients?`, danger: true }))) return;
    try { await api.admin.deleteClient(t, c.id); toast("Client removed.", "success"); await load(); }
    catch (e) { toastError(e as ApiError); }
  }

  async function toggle(c: AdminClient) {
    const t = getToken();
    if (!t) return;
    try { await api.admin.updateClient(t, c.id, { is_active: !c.is_active }); await load(); }
    catch (e) { toastError(e as ApiError); }
  }

  return (
    <>
      <PageHeader title="Our Clients" subtitle="Brands shown in the 'Our Clients' section of your public landing page." />

      {/* Add / edit form */}
      <div className="panel" style={{ maxWidth: 680 }}>
        <h2 style={{ marginTop: 0 }}>{editingId ? "Edit client" : "Add a client"}</h2>
        <form onSubmit={submit}>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            <div className="field" style={{ flex: 2, minWidth: 200 }}>
              <label>Client / brand name</label>
              <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required placeholder="Acme Dental Care" />
            </div>
            <div className="field" style={{ flex: 1, minWidth: 110 }}>
              <label>Order</label>
              <input type="number" min={0} value={form.sort_order} onChange={(e) => setForm((f) => ({ ...f, sort_order: Number(e.target.value) }))} />
            </div>
          </div>
          <div className="field">
            <label>Logo image URL <span className="muted">(recommended — transparent PNG/SVG)</span></label>
            <input value={form.logo_url} onChange={(e) => setForm((f) => ({ ...f, logo_url: e.target.value }))} placeholder="https://…/logo.png" />
          </div>
          <div className="field">
            <label>Website <span className="muted">(optional)</span></label>
            <input value={form.website} onChange={(e) => setForm((f) => ({ ...f, website: e.target.value }))} placeholder="https://client.com" />
          </div>
          {form.logo_url.trim() && (
            <div style={{ margin: "4px 0 12px" }}>
              <div className="muted" style={{ fontSize: 12, marginBottom: 4 }}>Preview</div>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={form.logo_url} alt="logo preview" style={{ height: 48, maxWidth: 180, objectFit: "contain", background: "#fff", border: "1px solid var(--border)", borderRadius: 10, padding: 8 }} />
            </div>
          )}
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn" disabled={saving} style={{ width: "auto", padding: "10px 22px" }}>{saving ? "Saving…" : editingId ? "Save changes" : "+ Add client"}</button>
            {editingId && <button type="button" className="btn-mini" onClick={() => { setEditingId(null); setForm({ ...BLANK }); }}>Cancel</button>}
          </div>
        </form>
      </div>

      {/* List */}
      <div className="panel">
        <h2 style={{ marginTop: 0 }}>Clients ({clients.length})</h2>
        {loading ? <LoadingBlock label="Loading clients…" /> : clients.length === 0 ? (
          <p className="muted">No clients yet. Add your first client above — it appears on your landing page.</p>
        ) : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(230px, 1fr))", gap: 12 }}>
            {clients.map((c) => (
              <div key={c.id} className="panel" style={{ display: "flex", flexDirection: "column", gap: 8, opacity: c.is_active ? 1 : 0.55 }}>
                <div style={{ height: 56, display: "grid", placeItems: "center", background: "#fff", border: "1px solid var(--border)", borderRadius: 10 }}>
                  {c.logo_url
                    // eslint-disable-next-line @next/next/no-img-element
                    ? <img src={c.logo_url} alt={c.name} style={{ maxHeight: 44, maxWidth: "85%", objectFit: "contain" }} />
                    : <span style={{ fontWeight: 800, color: "var(--muted)" }}>{c.name}</span>}
                </div>
                <div style={{ fontWeight: 700, fontSize: 14 }}>{c.name}</div>
                <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                  <button className="btn-mini" onClick={() => edit(c)}>Edit</button>
                  <button className="btn-mini" onClick={() => toggle(c)}>{c.is_active ? "Hide" : "Show"}</button>
                  <button className="btn-mini" style={{ color: "#c53030" }} onClick={() => remove(c)}>Delete</button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </>
  );
}
