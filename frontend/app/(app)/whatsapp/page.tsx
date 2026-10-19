"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, WaConfig, WaNumber } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { PageHeader } from "@/components/PageHeader";
import { EmbeddedSignupButton } from "@/components/EmbeddedSignupButton";

const QUALITY: Record<string, { label: string; color: string; bg: string; hint: string }> = {
  GREEN: { label: "High", color: "#0a7d47", bg: "#e7f7ef", hint: "Meta quality rating: GREEN (high)." },
  YELLOW: { label: "Medium", color: "#b7791f", bg: "#fdf4dd", hint: "Meta quality rating: YELLOW (medium). Reduce blocks/reports to improve it." },
  RED: { label: "Low", color: "#c53030", bg: "#fdecec", hint: "Meta quality rating: RED (low). Sending limits may be reduced." },
};

function QualityBadge({ rating }: { rating: string | null }) {
  const q = rating ? QUALITY[rating.toUpperCase()] : undefined;
  const label = q?.label ?? (rating ? "Not rated yet" : "—");
  const hint = q?.hint ?? "Meta has not rated this number yet. New numbers get a quality rating after they send enough messages (usually a few days of activity). Use Sync to refresh.";
  return (
    <span title={hint} style={{ background: q?.bg ?? "#eef1f2", color: q?.color ?? "#667781", padding: "2px 9px", borderRadius: 999, fontSize: 12, fontWeight: 600, cursor: "help" }}>
      {label}
    </span>
  );
}

const EMPTY_FORM = { waba_id: "", phone_number_id: "", display_phone_number: "", access_token: "", name: "" };

export default function WhatsAppPage() {
  const [numbers, setNumbers] = useState<WaNumber[]>([]);
  const [config, setConfig] = useState<WaConfig | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ ...EMPTY_FORM });
  const [submitting, setSubmitting] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [sendId, setSendId] = useState<string | null>(null);
  const [sendTo, setSendTo] = useState("");
  const [sending, setSending] = useState(false);
  const [regId, setRegId] = useState<string | null>(null);
  const [regPin, setRegPin] = useState("");
  const [registering, setRegistering] = useState(false);
  const [tokenId, setTokenId] = useState<string | null>(null);
  const [tokenVal, setTokenVal] = useState("");
  const [updatingToken, setUpdatingToken] = useState(false);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    setError(null);
    try {
      const [n, c] = await Promise.all([api.whatsapp.numbers(token), api.whatsapp.config(token)]);
      setNumbers(n.numbers);
      setConfig(c);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  async function connect(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSubmitting(true);
    setError(null);
    try {
      await api.whatsapp.connectManual(token, form);
      setForm({ ...EMPTY_FORM });
      setShowForm(false);
      setNotice("WhatsApp number connected successfully.");
      await load();
    } catch (err) {
      const e = err as ApiError;
      const fieldErrors = e.errors ? Object.values(e.errors).flat().join(". ") : "";
      setError(fieldErrors || e.message);
    } finally {
      setSubmitting(false);
    }
  }

  async function onEmbeddedComplete(r: { code: string; waba_id: string; phone_number_id: string | null }) {
    const token = getToken();
    if (!token) return;
    setError(null);
    try {
      await api.whatsapp.embeddedSignup(token, r);
      setNotice("WhatsApp connected via Meta Embedded Signup.");
      setShowForm(false);
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function sync(id: string) {
    const token = getToken();
    if (!token) return;
    try {
      await api.whatsapp.sync(token, id);
      setNotice("Number metadata synced from Meta.");
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function subscribeApp(id: string) {
    const token = getToken();
    if (!token) return;
    setError(null);
    try {
      const res = await api.whatsapp.subscribeApp(token, id);
      setNotice(res.message);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function sendTest(id: string) {
    const token = getToken();
    if (!token || !sendTo.trim()) return;
    setSending(true);
    setError(null);
    try {
      const res = await api.whatsapp.sendTest(token, id, {
        to: sendTo,
        type: "template",
        template: "test_welcome",
        language: "en",
      });
      setNotice(`Message sent to ${res.to} (id: ${res.wamid ?? "queued"}). Check WhatsApp on that phone.`);
      setSendId(null);
      setSendTo("");
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSending(false);
    }
  }

  async function registerNumber(id: string) {
    const token = getToken();
    if (!token || regPin.length !== 6) return;
    setRegistering(true);
    setError(null);
    try {
      await api.whatsapp.register(token, id, regPin);
      setNotice("Phone number registered on WhatsApp Cloud API. You can now send messages.");
      setRegId(null);
      setRegPin("");
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setRegistering(false);
    }
  }

  async function updateToken(id: string) {
    const token = getToken();
    if (!token || !tokenVal.trim()) return;
    setUpdatingToken(true);
    setError(null);
    try {
      const res = await api.whatsapp.updateToken(token, id, tokenVal.trim());
      setNotice(res.message);
      setTokenId(null);
      setTokenVal("");
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setUpdatingToken(false);
    }
  }

  async function disconnect(id: string) {
    const token = getToken();
    if (!token) return;
    try {
      await api.whatsapp.disconnect(token, id);
      setNotice("Number disconnected.");
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  function update(key: keyof typeof form, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  return (
    <>
      <PageHeader
        title="WhatsApp"
        subtitle="Connect and manage your WhatsApp Business numbers (Meta Cloud API)"
        action={<button className="btn" style={{ width: "auto", padding: "10px 18px" }} onClick={() => { setShowForm((v) => !v); setNotice(null); }}>{showForm ? "Close" : "+ Connect WhatsApp"}</button>}
      />

      {notice && <div className="panel" style={{ background: "#e7f7ef", borderColor: "#b6e6cd", color: "#0a7d47" }}>{notice}</div>}
      {error && (
        <div className="error">
          {error}
          {error.includes("#200") && (
            <div style={{ marginTop: 8, fontSize: 13 }}>
              Your WhatsApp access token cannot send on this number. Re-connect with a permanent System User token that has <b>whatsapp_business_messaging</b> + <b>whatsapp_business_management</b> permissions and whose System User is assigned to this WhatsApp Business Account. — Meta: (#200) You do not have the necessary permissions to send messages on behalf of this WhatsApp Business Account
            </div>
          )}
        </div>
      )}

      {showForm && (
        <div className="panel">
          <h2>Connect a WhatsApp number</h2>
          {config?.embedded_signup_configured && config.app_id && config.config_id && (
            <div style={{ marginBottom: 18 }}>
              <p className="muted" style={{ marginTop: 0 }}>
                One-click connect with Meta. A Facebook window opens where you pick your
                WhatsApp Business Account and number.
              </p>
              <EmbeddedSignupButton
                appId={config.app_id}
                configId={config.config_id}
                apiVersion={config.api_version}
                onComplete={onEmbeddedComplete}
                onError={(m) => setError(m)}
              />
              <div style={{ display: "flex", alignItems: "center", gap: 12, margin: "20px 0 4px" }}>
                <div style={{ flex: 1, height: 1, background: "var(--border)" }} />
                <span className="muted" style={{ fontSize: 12 }}>or connect manually</span>
                <div style={{ flex: 1, height: 1, background: "var(--border)" }} />
              </div>
            </div>
          )}
          {!config?.embedded_signup_configured && (
            <p className="muted" style={{ marginTop: 0 }}>
              One-click Embedded Signup activates once META_APP_ID and META_CONFIG_ID are set and
              META_USE_FAKE=false. Meanwhile, connect manually using your Cloud API credentials.
            </p>
          )}
          <form onSubmit={connect}>
            <div className="field"><label>WABA ID</label><input value={form.waba_id} onChange={(e) => update("waba_id", e.target.value)} required placeholder="WhatsApp Business Account ID" /></div>
            <div className="field"><label>Phone number ID</label><input value={form.phone_number_id} onChange={(e) => update("phone_number_id", e.target.value)} required placeholder="Meta phone_number_id" /></div>
            <div className="field"><label>Display phone number</label><input value={form.display_phone_number} onChange={(e) => update("display_phone_number", e.target.value)} required placeholder="+91 98765 43210" /></div>
            <div className="field"><label>System User access token</label><input value={form.access_token} onChange={(e) => update("access_token", e.target.value)} required placeholder="Permanent / 60-day token (stored encrypted)" /></div>
            <div className="field"><label>Label (optional)</label><input value={form.name} onChange={(e) => update("name", e.target.value)} placeholder="e.g. Sales line" /></div>
            <button className="btn" disabled={submitting}>{submitting ? "Connecting…" : "Connect number"}</button>
          </form>
        </div>
      )}

      <div className="panel">
        <h2>Connected numbers</h2>
        {loading ? (
          <p className="muted">Loading…</p>
        ) : numbers.length === 0 ? (
          <p className="muted">No WhatsApp numbers connected yet. Click “Connect WhatsApp” to add one.</p>
        ) : (
          <div>
            <div className="row" style={{ fontWeight: 600 }}>
              <span style={{ flex: 2 }}>Number</span>
              <span style={{ flex: 1 }}>Quality</span>
              <span style={{ flex: 1 }}>Status</span>
              <span style={{ flex: 1.4, textAlign: "right" }}>Actions</span>
            </div>
            {numbers.map((n) => (
              <div key={n.id}>
                <div className="row" style={{ alignItems: "center" }}>
                  <span style={{ flex: 2 }}>
                    <b>{n.display_phone_number}</b>{n.is_default && <span className="badge" style={{ marginLeft: 8 }}>default</span>}
                    <div className="muted" style={{ fontSize: 12 }}>{n.waba?.name}</div>
                  </span>
                  <span style={{ flex: 1 }}><QualityBadge rating={n.quality_rating} /></span>
                  <span style={{ flex: 1 }}>
                    <span style={{ textTransform: "capitalize" }}>{n.status}</span>
                    {!n.is_registered && <div style={{ fontSize: 11, color: "#c53030" }}>Not registered</div>}
                  </span>
                  <span style={{ flex: 1.6, textAlign: "right", display: "flex", gap: 8, justifyContent: "flex-end", flexWrap: "wrap" }}>
                    {!n.is_registered && (
                      <button className="btn-mini" style={{ background: "#ff9800", color: "#fff", border: "none" }} onClick={() => { setRegId(regId === n.id ? null : n.id); setRegPin(""); setNotice(null); }}>Register</button>
                    )}
                    <button className="btn-mini" onClick={() => { setSendId(sendId === n.id ? null : n.id); setSendTo(""); setNotice(null); }}>Send test</button>
                    <button className="btn-mini" onClick={() => subscribeApp(n.id)} title="Subscribe the app to this WhatsApp Business Account — fixes Meta #200 'no permission to send'">Enable sending</button>
                    <button className="btn-mini" style={{ background: "#7c3aed", color: "#fff", border: "none" }} onClick={() => { setTokenId(tokenId === n.id ? null : n.id); setTokenVal(""); setNotice(null); }} title="Update the System User access token for this number">Update Token</button>
                    <button className="btn-mini" onClick={() => sync(n.id)}>Sync</button>
                    <button className="btn-mini danger" onClick={() => disconnect(n.id)}>Disconnect</button>
                  </span>
                </div>
                {regId === n.id && (
                  <div style={{ padding: "12px 0 16px", display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
                    <input
                      value={regPin}
                      onChange={(e) => setRegPin(e.target.value.replace(/\D/g, "").slice(0, 6))}
                      placeholder="6-digit PIN"
                      maxLength={6}
                      style={{ width: 140, padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, letterSpacing: 4, textAlign: "center" }}
                    />
                    <button className="btn" style={{ width: "auto", padding: "10px 18px", background: "#ff9800" }} disabled={registering || regPin.length !== 6} onClick={() => registerNumber(n.id)}>
                      {registering ? "Registering…" : "Register number"}
                    </button>
                    <span className="muted" style={{ fontSize: 12, width: "100%" }}>
                      Enter a 6-digit PIN for two-step verification. This registers your phone number on the WhatsApp Cloud API so you can send messages.
                    </span>
                  </div>
                )}
                {tokenId === n.id && (
                  <div style={{ padding: "12px 0 16px", display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
                    <input
                      value={tokenVal}
                      onChange={(e) => setTokenVal(e.target.value)}
                      placeholder="Paste new System User access token"
                      style={{ flex: 1, minWidth: 260, padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}
                    />
                    <button className="btn" style={{ width: "auto", padding: "10px 18px", background: "#7c3aed" }} disabled={updatingToken || !tokenVal.trim()} onClick={() => updateToken(n.id)}>
                      {updatingToken ? "Updating…" : "Save Token"}
                    </button>
                    <span className="muted" style={{ fontSize: 12, width: "100%" }}>
                      Get a permanent System User token from <b>Meta Business Suite → Settings → System Users</b> with <b>whatsapp_business_messaging</b> + <b>whatsapp_business_management</b> permissions. Assign the System User to this WhatsApp Business Account first.
                    </span>
                  </div>
                )}
                {sendId === n.id && (
                  <div style={{ padding: "12px 0 16px", display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
                    <input
                      value={sendTo}
                      onChange={(e) => setSendTo(e.target.value)}
                      placeholder="Recipient number, e.g. 9198XXXXXXXX (with country code)"
                      style={{ flex: 1, minWidth: 260, padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}
                    />
                    <button className="btn" style={{ width: "auto", padding: "10px 18px" }} disabled={sending || !sendTo.trim()} onClick={() => sendTest(n.id)}>
                      {sending ? "Sending…" : "Send test_welcome"}
                    </button>
                    <span className="muted" style={{ fontSize: 12, width: "100%" }}>
                      Sends the pre-approved <b>test_welcome</b> template — the reliable way to start a chat.
                    </span>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </>
  );
}
