"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, InvoiceSettings } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

const EMPTY: InvoiceSettings = {
  company_name: "", address: "", gstin: "", email: "", phone: "",
  tax_details: "", invoice_prefix: "INV", gst_rate: 18,
};

export default function SettingsPage() {
  const me = useUser();
  const isSuper = !!me.is_super_admin;
  const [form, setForm] = useState<InvoiceSettings>(EMPTY);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const res = await api.admin.settings(token);
      setForm({ ...EMPTY, ...res.settings });
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { if (isSuper) load(); else setLoading(false); }, [isSuper, load]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSaving(true);
    setError(null);
    try {
      const res = await api.admin.updateSettings(token, form);
      setForm({ ...EMPTY, ...res.settings });
      setNotice("Settings saved.");
      setTimeout(() => setNotice(null), 3000);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSaving(false);
    }
  }

  const set = <K extends keyof InvoiceSettings>(k: K, v: InvoiceSettings[K]) => setForm((f) => ({ ...f, [k]: v }));

  if (!isSuper) {
    return <div><PageHeader title="Settings" /><div className="panel"><p className="muted">Super admin only.</p></div></div>;
  }

  return (
    <div>
      <PageHeader title="Settings" subtitle="Seller / company details printed on every invoice." />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      {loading ? (
        <LoadingBlock label="Loading settings…" />
      ) : (
        <form onSubmit={save} className="panel" style={{ maxWidth: 640 }}>
          <h3 style={{ marginTop: 0 }}>Invoice / Seller details</h3>
          <div className="field">
            <label>Company / Business name</label>
            <input value={form.company_name ?? ""} onChange={(e) => set("company_name", e.target.value)} placeholder="Eydia.ai" />
          </div>
          <div className="field">
            <label>Business address</label>
            <textarea value={form.address ?? ""} onChange={(e) => set("address", e.target.value)} rows={3} placeholder="Street, City, State, PIN" style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, fontFamily: "inherit", resize: "vertical" }} />
          </div>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            <div className="field" style={{ flex: 1, minWidth: 200 }}>
              <label>GSTIN</label>
              <input value={form.gstin ?? ""} onChange={(e) => set("gstin", e.target.value)} placeholder="22AAAAA0000A1Z5" />
            </div>
            <div className="field" style={{ flex: 1, minWidth: 200 }}>
              <label>Tax details <span className="muted">(PAN / CIN)</span></label>
              <input value={form.tax_details ?? ""} onChange={(e) => set("tax_details", e.target.value)} placeholder="PAN / CIN" />
            </div>
          </div>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            <div className="field" style={{ flex: 1, minWidth: 200 }}>
              <label>Email</label>
              <input type="email" value={form.email ?? ""} onChange={(e) => set("email", e.target.value)} placeholder="billing@heltog.com" />
            </div>
            <div className="field" style={{ flex: 1, minWidth: 200 }}>
              <label>Phone</label>
              <input value={form.phone ?? ""} onChange={(e) => set("phone", e.target.value)} placeholder="+91…" />
            </div>
          </div>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            <div className="field" style={{ flex: 1, minWidth: 160 }}>
              <label>Invoice prefix</label>
              <input value={form.invoice_prefix ?? ""} onChange={(e) => set("invoice_prefix", e.target.value)} placeholder="INV" />
            </div>
            <div className="field" style={{ flex: 1, minWidth: 160 }}>
              <label>GST rate (%)</label>
              <input type="number" min={0} max={100} value={form.gst_rate} onChange={(e) => set("gst_rate", Number(e.target.value) || 0)} />
            </div>
          </div>
          <button className="btn" style={{ width: "auto", padding: "11px 24px" }} disabled={saving}>{saving ? "Saving…" : "Save settings"}</button>
        </form>
      )}
    </div>
  );
}
