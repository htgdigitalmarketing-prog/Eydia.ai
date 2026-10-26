"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, InvoiceSettings, AiSettings } from "@/lib/api";
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

      {!loading && <AiProviderCard />}
    </div>
  );
}

const PROVIDERS = [
  { k: "gemini", label: "Google Gemini", hint: "AI Studio key · generativelanguage API" },
  { k: "anthropic", label: "Anthropic Claude", hint: "console.anthropic.com key" },
  { k: "openai", label: "OpenAI", hint: "platform.openai.com key" },
] as const;

type ProviderKey = (typeof PROVIDERS)[number]["k"];

/** Super Admin → AI Provider: pick one provider, set its key + model, activate & test. */
function AiProviderCard() {
  const [ai, setAi] = useState<AiSettings | null>(null);
  const [active, setActive] = useState<ProviderKey>("gemini");
  const [isActive, setIsActive] = useState(false);
  const [keys, setKeys] = useState<Record<ProviderKey, string>>({ gemini: "", anthropic: "", openai: "" });
  const [models, setModels] = useState<Record<ProviderKey, string>>({ gemini: "", anthropic: "", openai: "" });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  const load = useCallback(async () => {
    const token = getToken(); if (!token) return;
    setLoading(true);
    try {
      const { ai } = await api.admin.aiSettings(token);
      setAi(ai);
      setIsActive(ai.is_active);
      setActive((ai.active_provider as ProviderKey) || (ai.effective_provider as ProviderKey) || "gemini");
      setModels({
        gemini: ai.providers.gemini.model, anthropic: ai.providers.anthropic.model, openai: ai.providers.openai.model,
      });
    } catch { /* non-super admins never render this */ }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { load(); }, [load]);

  async function save() {
    const token = getToken(); if (!token) return;
    setSaving(true); setMsg(null);
    try {
      const body: Record<string, unknown> = { active_provider: active, is_active: isActive };
      (["gemini", "anthropic", "openai"] as ProviderKey[]).forEach((p) => {
        body[`${p}_model`] = models[p];
        if (keys[p].trim()) body[`${p}_api_key`] = keys[p].trim();
      });
      const { ai } = await api.admin.updateAiSettings(token, body);
      setAi(ai); setIsActive(ai.is_active); setKeys({ gemini: "", anthropic: "", openai: "" });
      setMsg({ ok: true, text: "AI provider settings saved." });
    } catch (e) { setMsg({ ok: false, text: (e as ApiError).message }); }
    finally { setSaving(false); }
  }

  async function test() {
    const token = getToken(); if (!token) return;
    setTesting(true); setMsg(null);
    try {
      const { result } = await api.admin.testAiProvider(token, active);
      setMsg(result.ok
        ? { ok: true, text: `✓ ${PROVIDERS.find((p) => p.k === active)?.label} works (${result.model}): "${result.sample}"` }
        : { ok: false, text: `✗ ${result.message ?? "Test failed."}` });
    } catch (e) { setMsg({ ok: false, text: (e as ApiError).message }); }
    finally { setTesting(false); }
  }

  if (loading || !ai) return null;
  const info = ai.providers[active];

  return (
    <div className="panel" style={{ maxWidth: 640, marginTop: 18 }}>
      <h3 style={{ marginTop: 0, display: "flex", alignItems: "center", gap: 8 }}>
        🤖 AI Provider
        <span style={{ fontSize: 11.5, fontWeight: 700, padding: "2px 9px", borderRadius: 999, background: isActive ? "#e7f7ef" : "#eef1f2", color: isActive ? "#0a7d47" : "#667781" }}>
          {isActive ? `Active · ${ai.effective_provider ?? "—"}` : "Auto (using .env keys)"}
        </span>
      </h3>
      <p className="muted" style={{ marginTop: 0, fontSize: 13 }}>
        Choose which AI runs everything (chatbot, SEO, captions, images). Pick one, paste its key, set the model, then Activate.
      </p>

      {/* Provider selector */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))", gap: 10, margin: "12px 0" }}>
        {PROVIDERS.map((p) => {
          const pinfo = ai.providers[p.k];
          const selected = active === p.k;
          return (
            <button key={p.k} type="button" onClick={() => { setActive(p.k); setMsg(null); }} style={{
              textAlign: "left", cursor: "pointer", padding: "11px 13px", borderRadius: 12,
              border: `2px solid ${selected ? "var(--accent)" : "var(--border)"}`,
              background: selected ? "color-mix(in srgb, var(--accent) 8%, var(--card))" : "var(--card)",
            }}>
              <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 6 }}>
                <span style={{ fontWeight: 700, fontSize: 13.5 }}>{p.label}</span>
                {pinfo.has_key && <span style={{ fontSize: 10, fontWeight: 700, color: "#0a7d47" }}>● key set</span>}
              </div>
              <div className="muted" style={{ fontSize: 11, marginTop: 2 }}>{p.hint}</div>
            </button>
          );
        })}
      </div>

      {/* Selected provider config */}
      <div className="field">
        <label>API key {info.has_key && <span className="muted" style={{ fontWeight: 400 }}>· saved (ends …{info.key_last4}) {info.key_source === "env" && "from .env"}</span>}</label>
        <input type="password" autoComplete="off" value={keys[active]} onChange={(e) => setKeys((k) => ({ ...k, [active]: e.target.value }))}
          placeholder={info.has_key ? "•••••••• (leave blank to keep current)" : "Paste the API key"} />
      </div>
      <div className="field">
        <label>Model name</label>
        <input value={models[active]} onChange={(e) => setModels((m) => ({ ...m, [active]: e.target.value }))}
          placeholder={info.model_default} />
        <div className="muted" style={{ fontSize: 11.5, marginTop: 4 }}>Default: {info.model_default}</div>
      </div>
      <label style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 14, cursor: "pointer", margin: "6px 0 14px" }}>
        <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} style={{ width: "auto" }} />
        Use <strong>{PROVIDERS.find((p) => p.k === active)?.label}</strong> as the only active AI provider
      </label>

      {msg && <div className="panel" style={{ marginBottom: 12, background: msg.ok ? "#e7f7ef" : "#fdecec", color: msg.ok ? "#0a7d47" : "#c0392b", fontSize: 13 }}>{msg.text}</div>}

      <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
        <button className="btn" type="button" onClick={save} disabled={saving} style={{ width: "auto", padding: "11px 22px" }}>{saving ? "Saving…" : "Save & activate"}</button>
        <button className="btn-mini" type="button" onClick={test} disabled={testing}>{testing ? "Testing…" : "Test connection"}</button>
      </div>
      <p className="muted" style={{ fontSize: 11.5, marginTop: 10 }}>
        Keys are stored encrypted and never shown again. When no provider is active, the app falls back to the keys in the server .env.
      </p>
    </div>
  );
}
