"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, AdminBillingSettings, AdminCoupon } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";

const EMPTY_COUPON = { code: "", type: "percent", value: "", max_discount: "", min_amount: "", max_uses: "", expires_at: "", is_active: true };

export default function PricingAdminPage() {
  const user = useUser();
  const [settings, setSettings] = useState<AdminBillingSettings | null>(null);
  const [coupons, setCoupons] = useState<AdminCoupon[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState({ ...EMPTY_COUPON });

  const load = useCallback(async () => {
    const t = getToken();
    if (!t) return;
    try {
      const [s, c] = await Promise.all([api.admin.billingSettings(t), api.admin.coupons(t)]);
      setSettings(s.settings);
      setCoupons(c.coupons);
    } catch (e) { setError((e as ApiError).message); }
  }, []);
  useEffect(() => { load(); }, [load]);

  if (!user.is_super_admin) return <div className="panel" style={{ textAlign: "center", padding: 40 }}>Super admin only.</div>;
  if (!settings) return <div className="panel">Loading…</div>;

  function setSetting<K extends keyof AdminBillingSettings>(key: K, value: AdminBillingSettings[K]) {
    setSettings((s) => (s ? { ...s, [key]: value } : s));
  }
  function setCycleDiscount(cycle: string, pct: number) {
    setSettings((s) => (s ? { ...s, cycle_discounts: { ...s.cycle_discounts, [cycle]: pct } } : s));
  }
  function toggleCycle(cycle: string, on: boolean) {
    setSettings((s) => {
      if (!s) return s;
      const set = new Set(s.enabled_cycles);
      if (on) set.add(cycle); else set.delete(cycle);
      return { ...s, enabled_cycles: s.available_cycles.map((c) => c.key).filter((k) => set.has(k)) };
    });
  }

  async function saveSettings() {
    const t = getToken();
    if (!t || !settings) return;
    setError(null); setNotice(null);
    try {
      const res = await api.admin.updateBillingSettings(t, {
        cycle_discounts: settings.cycle_discounts,
        enabled_cycles: settings.enabled_cycles,
        offer_label: settings.offer_label,
        offer_ends_at: settings.offer_ends_at,
        offer_active: settings.offer_active,
      });
      setSettings(res.settings);
      setNotice("Pricing settings saved.");
    } catch (e) { setError((e as ApiError).message); }
  }

  async function createCoupon(e: React.FormEvent) {
    e.preventDefault();
    const t = getToken();
    if (!t) return;
    setError(null); setNotice(null);
    try {
      const body: Record<string, unknown> = {
        code: form.code, type: form.type, value: Number(form.value),
        is_active: form.is_active,
      };
      if (form.max_discount) body.max_discount = Number(form.max_discount);
      if (form.min_amount) body.min_amount = Number(form.min_amount);
      if (form.max_uses) body.max_uses = Number(form.max_uses);
      if (form.expires_at) body.expires_at = form.expires_at;
      await api.admin.createCoupon(t, body);
      setForm({ ...EMPTY_COUPON });
      setNotice("Coupon created.");
      await load();
    } catch (err) {
      const e2 = err as ApiError;
      setError(e2.errors ? Object.values(e2.errors).flat().join(". ") : e2.message);
    }
  }

  async function deleteCoupon(id: string) {
    const t = getToken();
    if (!t || !confirm("Delete this coupon?")) return;
    try { await api.admin.deleteCoupon(t, id); await load(); } catch (e) { setError((e as ApiError).message); }
  }

  const toLocal = (iso: string | null) => (iso ? new Date(iso).toISOString().slice(0, 16) : "");

  return (
    <>
      <PageHeader title="Pricing & Coupons" subtitle="Per-cycle discounts, the launch-offer countdown, and coupon codes — all live on the pricing page." />
      {notice && <div className="panel" style={{ background: "#e7f7ef", borderColor: "#b6e6cd", color: "#0a7d47" }}>{notice}</div>}
      {error && <div className="error">{error}</div>}

      {/* Cycle discounts */}
      <div className="panel">
        <h2>Billing cycles &amp; discounts</h2>
        <p className="muted" style={{ marginTop: 0, fontSize: 13 }}>Discount % applies to every plan for that cycle. Untick a cycle to hide it on the pricing page.</p>
        <div style={{ display: "grid", gap: 10 }}>
          {settings.available_cycles.map((c) => (
            <div key={c.key} style={{ display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
              <label style={{ display: "flex", alignItems: "center", gap: 8, minWidth: 160 }}>
                <input type="checkbox" checked={settings.enabled_cycles.includes(c.key)} onChange={(e) => toggleCycle(c.key, e.target.checked)} />
                <strong>{c.label}</strong> <span className="muted" style={{ fontSize: 12 }}>({c.months} mo)</span>
              </label>
              <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                <input type="number" min={0} max={100} value={settings.cycle_discounts[c.key] ?? 0}
                  onChange={(e) => setCycleDiscount(c.key, Number(e.target.value))}
                  style={{ width: 90, padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8 }} />
                <span className="muted">% off</span>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Launch offer */}
      <div className="panel">
        <h2>Launch offer (countdown)</h2>
        <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
          <label style={{ display: "flex", alignItems: "center", gap: 8 }}>
            <input type="checkbox" checked={settings.offer_active} onChange={(e) => setSetting("offer_active", e.target.checked)} /> Offer active
          </label>
          <div className="field" style={{ margin: 0, flex: 1, minWidth: 220 }}>
            <label>Badge text</label>
            <input value={settings.offer_label ?? ""} onChange={(e) => setSetting("offer_label", e.target.value)} placeholder="LAUNCH PRICE — LIMITED TIME" />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label>Ends at</label>
            <input type="datetime-local" value={toLocal(settings.offer_ends_at)}
              onChange={(e) => setSetting("offer_ends_at", e.target.value ? new Date(e.target.value).toISOString() : null)} />
          </div>
        </div>
      </div>

      <button className="btn" style={{ width: "auto", padding: "11px 26px" }} onClick={saveSettings}>Save pricing settings</button>

      {/* Coupons */}
      <div className="panel" style={{ marginTop: 18 }}>
        <h2>Coupons</h2>
        <form onSubmit={createCoupon} style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "flex-end", marginBottom: 16 }}>
          <div className="field" style={{ margin: 0 }}><label>Code</label><input value={form.code} onChange={(e) => setForm((f) => ({ ...f, code: e.target.value.toUpperCase() }))} required placeholder="LAUNCH50" style={{ width: 130 }} /></div>
          <div className="field" style={{ margin: 0 }}><label>Type</label>
            <select value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))} style={{ padding: "9px 10px", border: "1px solid var(--border)", borderRadius: 8 }}>
              <option value="percent">% off</option><option value="flat">₹ flat</option>
            </select>
          </div>
          <div className="field" style={{ margin: 0 }}><label>Value</label><input type="number" min={0} value={form.value} onChange={(e) => setForm((f) => ({ ...f, value: e.target.value }))} required style={{ width: 90 }} /></div>
          <div className="field" style={{ margin: 0 }}><label>Max ₹ off</label><input type="number" min={0} value={form.max_discount} onChange={(e) => setForm((f) => ({ ...f, max_discount: e.target.value }))} placeholder="cap" style={{ width: 90 }} /></div>
          <div className="field" style={{ margin: 0 }}><label>Min ₹</label><input type="number" min={0} value={form.min_amount} onChange={(e) => setForm((f) => ({ ...f, min_amount: e.target.value }))} style={{ width: 90 }} /></div>
          <div className="field" style={{ margin: 0 }}><label>Max uses</label><input type="number" min={1} value={form.max_uses} onChange={(e) => setForm((f) => ({ ...f, max_uses: e.target.value }))} placeholder="∞" style={{ width: 80 }} /></div>
          <div className="field" style={{ margin: 0 }}><label>Expires</label><input type="datetime-local" value={form.expires_at} onChange={(e) => setForm((f) => ({ ...f, expires_at: e.target.value ? new Date(e.target.value).toISOString() : "" }))} /></div>
          <button className="btn" style={{ width: "auto", padding: "10px 18px" }}>+ Add coupon</button>
        </form>

        {coupons.length === 0 ? <p className="muted">No coupons yet.</p> : (
          <div className="row" style={{ fontWeight: 600, fontSize: 13 }}>
            <span style={{ flex: 1 }}>Code</span><span style={{ flex: 1 }}>Discount</span><span style={{ flex: 1 }}>Used</span><span style={{ flex: 1 }}>Status</span><span style={{ width: 80 }} />
          </div>
        )}
        {coupons.map((c) => (
          <div key={c.id} className="row" style={{ alignItems: "center", fontSize: 13.5 }}>
            <span style={{ flex: 1, fontWeight: 700 }}>{c.code}</span>
            <span style={{ flex: 1 }}>{c.type === "percent" ? `${c.value}%${c.max_discount ? ` (max ₹${c.max_discount})` : ""}` : `₹${c.value}`}{c.min_amount ? ` · min ₹${c.min_amount}` : ""}</span>
            <span style={{ flex: 1 }}>{c.used_count}{c.max_uses ? ` / ${c.max_uses}` : ""}</span>
            <span style={{ flex: 1 }}>
              <span className="badge" style={{ background: c.live ? "#e7f7ef" : "#fdecec", color: c.live ? "#0a7d47" : "#c53030" }}>{c.live ? "Live" : "Off"}</span>
            </span>
            <span style={{ width: 80, textAlign: "right" }}><button className="btn-mini danger" onClick={() => deleteCoupon(c.id)}>Delete</button></span>
          </div>
        ))}
      </div>
    </>
  );
}
