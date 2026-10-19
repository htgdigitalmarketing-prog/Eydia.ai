"use client";

import { useCallback, useEffect, useState } from "react";
import {
  api, ApiError, ChatbotItem, ChatbotDetail, ChatbotRuleItem, WaNumber, TemplateItem,
} from "@/lib/api";
import { getToken } from "@/lib/auth";
import { PageHeader } from "@/components/PageHeader";
import { UpgradePrompt, planAllows } from "@/components/PlanGate";
import { useUser } from "@/lib/user-context";

const MATCH_TYPES = [
  { value: "contains", label: "Contains" },
  { value: "exact", label: "Exact match" },
  { value: "starts_with", label: "Starts with" },
  { value: "regex", label: "Regex" },
];

type View = "list" | "editor";

const EMPTY_RULE = {
  keyword: "",
  match_type: "contains",
  response_text: "",
  response_type: "text",
  template_name: "",
  priority: 100,
  is_active: true,
};

export default function ChatbotPage() {
  const gateUser = useUser();
  const [view, setView] = useState<View>("list");
  const [bots, setBots] = useState<ChatbotItem[]>([]);
  const [numbers, setNumbers] = useState<WaNumber[]>([]);
  const [templates, setTemplates] = useState<TemplateItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Create
  const [showCreate, setShowCreate] = useState(false);
  const [form, setForm] = useState({ name: "", phone_number_id: "", welcome_message: "", fallback_message: "" });
  const [submitting, setSubmitting] = useState(false);

  // Editor
  const [bot, setBot] = useState<ChatbotDetail | null>(null);
  const [ruleForm, setRuleForm] = useState({ ...EMPTY_RULE });
  const [editingRuleId, setEditingRuleId] = useState<string | null>(null);
  const [savingRule, setSavingRule] = useState(false);

  // AI settings (per bot)
  const [ai, setAi] = useState({ enabled: false, instructions: "" });
  const [aiSaving, setAiSaving] = useState(false);

  // Follow-up settings (per bot)
  const [followup, setFollowup] = useState({ enabled: false, message: "" });
  const [followupSaving, setFollowupSaving] = useState(false);

  useEffect(() => {
    if (bot) {
      setAi({ enabled: !!bot.ai_enabled, instructions: bot.ai_instructions ?? "" });
      setFollowup({ enabled: !!bot.followup_enabled, message: bot.followup_message ?? "" });
    }
  }, [bot]);

  async function saveAi() {
    const token = getToken();
    if (!token || !bot) return;
    setAiSaving(true);
    setError(null);
    try {
      const res = await api.chatbot.update(token, bot.id, { ai_enabled: ai.enabled, ai_instructions: ai.instructions || null });
      setBot((b) => (b ? { ...b, ai_enabled: res.chatbot.ai_enabled, ai_instructions: res.chatbot.ai_instructions } : b));
      flash("AI settings saved.");
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setAiSaving(false);
    }
  }

  async function saveFollowup() {
    const token = getToken();
    if (!token || !bot) return;
    setFollowupSaving(true);
    setError(null);
    try {
      const res = await api.chatbot.update(token, bot.id, { followup_enabled: followup.enabled, followup_message: followup.message || null });
      setBot((b) => (b ? { ...b, followup_enabled: res.chatbot.followup_enabled, followup_message: res.chatbot.followup_message } : b));
      flash("Follow-up settings saved.");
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setFollowupSaving(false);
    }
  }

  const loadBots = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const [botsRes, numsRes, tplRes] = await Promise.all([
        api.chatbot.list(token),
        api.whatsapp.numbers(token).catch(() => ({ numbers: [] as WaNumber[] })),
        api.templates.list(token, { status: "APPROVED" }).catch(() => ({ templates: [] as TemplateItem[] })),
      ]);
      setBots(botsRes.chatbots);
      setNumbers(numsRes.numbers);
      setTemplates(tplRes.templates);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadBots(); }, [loadBots]);

  function flash(msg: string) {
    setNotice(msg);
    setTimeout(() => setNotice(null), 3000);
  }

  async function createBot(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSubmitting(true);
    setError(null);
    try {
      const res = await api.chatbot.create(token, {
        name: form.name,
        phone_number_id: form.phone_number_id || null,
        welcome_message: form.welcome_message || null,
        fallback_message: form.fallback_message || null,
      });
      setShowCreate(false);
      setForm({ name: "", phone_number_id: "", welcome_message: "", fallback_message: "" });
      await loadBots();
      openEditor(res.chatbot.id);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSubmitting(false);
    }
  }

  async function openEditor(id: string) {
    const token = getToken();
    if (!token) return;
    setError(null);
    try {
      const res = await api.chatbot.get(token, id);
      setBot(res.chatbot);
      setView("editor");
      resetRuleForm();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function toggleBot(id: string) {
    const token = getToken();
    if (!token) return;
    try {
      await api.chatbot.toggle(token, id);
      await loadBots();
      if (bot?.id === id) await openEditor(id);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function removeBot(id: string) {
    const token = getToken();
    if (!token) return;
    if (!confirm("Delete this chatbot and all its rules?")) return;
    try {
      await api.chatbot.remove(token, id);
      flash("Chatbot deleted.");
      if (bot?.id === id) { setView("list"); setBot(null); }
      await loadBots();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  function resetRuleForm() {
    setRuleForm({ ...EMPTY_RULE });
    setEditingRuleId(null);
  }

  function editRule(rule: ChatbotRuleItem) {
    setEditingRuleId(rule.id);
    setRuleForm({
      keyword: rule.keyword,
      match_type: rule.match_type,
      response_text: rule.response_text ?? "",
      response_type: rule.response_type,
      template_name: rule.template_name ?? "",
      priority: rule.priority,
      is_active: rule.is_active,
    });
  }

  async function saveRule(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token || !bot) return;
    setSavingRule(true);
    setError(null);
    const payload = {
      keyword: ruleForm.keyword,
      match_type: ruleForm.match_type,
      response_text: ruleForm.response_type === "text" ? ruleForm.response_text || null : null,
      response_type: ruleForm.response_type,
      template_name: ruleForm.response_type === "template" ? ruleForm.template_name || null : null,
      priority: Number(ruleForm.priority) || 100,
      is_active: ruleForm.is_active,
    };
    try {
      if (editingRuleId) {
        await api.chatbot.updateRule(token, bot.id, editingRuleId, payload);
      } else {
        await api.chatbot.addRule(token, bot.id, payload);
      }
      await openEditor(bot.id);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSavingRule(false);
    }
  }

  async function deleteRule(ruleId: string) {
    const token = getToken();
    if (!token || !bot) return;
    if (!confirm("Delete this rule?")) return;
    try {
      await api.chatbot.deleteRule(token, bot.id, ruleId);
      await openEditor(bot.id);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  if (!planAllows(gateUser.plan_limits, "max_chatbots")) return <UpgradePrompt title="Chatbot" feature="Chatbot" />;

  // ---------- List view ----------
  if (view === "list") {
    return (
      <div>
        <PageHeader
          title="Chatbot"
          subtitle="Keyword auto-replies — answer common questions instantly, 24/7."
          action={<button className="btn" onClick={() => setShowCreate(true)}>New chatbot</button>}
        />

        {error && <div className="error">{error}</div>}
        {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

        {loading ? (
          <div className="loading-block"><span className="spinner" /><span>Loading…</span></div>
        ) : bots.length === 0 ? (
          <div className="panel" style={{ textAlign: "center", padding: 40 }}>
            <div style={{ fontSize: 40 }}>🤖</div>
            <h3 style={{ margin: "12px 0 4px" }}>No chatbots yet</h3>
            <p className="muted" style={{ marginBottom: 16 }}>Create a chatbot to auto-reply to incoming messages by keyword.</p>
            <button className="btn" onClick={() => setShowCreate(true)}>Create your first chatbot</button>
          </div>
        ) : (
          <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(280px, 1fr))", gap: 14 }}>
            {bots.map((b) => (
              <div key={b.id} className="panel" style={{ cursor: "pointer" }} onClick={() => openEditor(b.id)}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: 8 }}>
                  <h3 style={{ margin: 0 }}>{b.name}</h3>
                  <span style={{
                    background: b.is_active ? "#e7f7ef" : "#eef1f2",
                    color: b.is_active ? "#0a7d47" : "#667781",
                    padding: "2px 9px", borderRadius: 999, fontSize: 12, fontWeight: 600,
                  }}>
                    {b.is_active ? "Active" : "Off"}
                  </span>
                </div>
                <p className="muted" style={{ margin: "8px 0 0", fontSize: 13 }}>
                  {b.rules_count ?? 0} rule{(b.rules_count ?? 0) === 1 ? "" : "s"}
                  {b.phone_number ? ` · ${b.phone_number.display_phone_number}` : " · no number"}
                </p>
                <div style={{ display: "flex", gap: 8, marginTop: 12 }} onClick={(e) => e.stopPropagation()}>
                  <button className="btn-mini" onClick={() => toggleBot(b.id)}>{b.is_active ? "Turn off" : "Turn on"}</button>
                  <button className="btn-mini" onClick={() => openEditor(b.id)}>Edit</button>
                  <button className="btn-mini" style={{ color: "#c53030" }} onClick={() => removeBot(b.id)}>Delete</button>
                </div>
              </div>
            ))}
          </div>
        )}

        {showCreate && (
          <div className="msg-info-overlay" onClick={() => setShowCreate(false)}>
            <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 460 }}>
              <h2 style={{ marginTop: 0 }}>New chatbot</h2>
              <form onSubmit={createBot}>
                <div className="field">
                  <label>Name</label>
                  <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required placeholder="Support bot" />
                </div>
                <div className="field">
                  <label>WhatsApp number</label>
                  <select value={form.phone_number_id} onChange={(e) => setForm((f) => ({ ...f, phone_number_id: e.target.value }))}>
                    <option value="">— Any / not set —</option>
                    {numbers.map((n) => (
                      <option key={n.id} value={n.id}>{n.display_phone_number}{n.verified_name ? ` (${n.verified_name})` : ""}</option>
                    ))}
                  </select>
                </div>
                <div className="field">
                  <label>Welcome message <span className="muted">(optional)</span></label>
                  <textarea value={form.welcome_message} onChange={(e) => setForm((f) => ({ ...f, welcome_message: e.target.value }))} rows={2} placeholder="Hi! How can we help?" />
                </div>
                <div className="field">
                  <label>Fallback message <span className="muted">(when no rule matches)</span></label>
                  <textarea value={form.fallback_message} onChange={(e) => setForm((f) => ({ ...f, fallback_message: e.target.value }))} rows={2} placeholder="Sorry, I didn't understand. An agent will reply soon." />
                </div>
                <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
                  <button className="btn" disabled={submitting}>{submitting ? "Creating…" : "Create chatbot"}</button>
                  <button type="button" className="btn-mini" onClick={() => setShowCreate(false)}>Cancel</button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    );
  }

  // ---------- Editor view ----------
  if (!bot) return null;

  return (
    <div>
      <PageHeader
        title={bot.name}
        subtitle={`${bot.rules.length} rule${bot.rules.length === 1 ? "" : "s"} · ${bot.is_active ? "Active" : "Off"}`}
        action={
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn-mini" onClick={() => toggleBot(bot.id)}>{bot.is_active ? "Turn off" : "Turn on"}</button>
            <button className="btn-mini" onClick={() => { setView("list"); setBot(null); loadBots(); }}>← Back</button>
          </div>
        }
      />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      {/* AI assistant */}
      <div className="panel" style={{ marginBottom: 16, borderLeft: "3px solid var(--accent)" }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", flexWrap: "wrap", gap: 8 }}>
          <div>
            <h3 style={{ margin: 0 }}>🤖 AI Assistant <span className="muted" style={{ fontSize: 12, fontWeight: 400 }}>(answers when no keyword rule matches)</span></h3>
            <p className="muted" style={{ fontSize: 13, margin: "4px 0 0" }}>Let AI reply to customers using your business info below — in the customer&apos;s own language.</p>
          </div>
          <label style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 14, fontWeight: 600, cursor: "pointer", whiteSpace: "nowrap" }}>
            <input type="checkbox" checked={ai.enabled} onChange={(e) => setAi((a) => ({ ...a, enabled: e.target.checked }))} />
            AI answers {ai.enabled ? "ON" : "OFF"}
          </label>
        </div>
        <div className="field" style={{ marginTop: 12, marginBottom: 0 }}>
          <label>Business info &amp; instructions <span className="muted">(what the AI should know & how to answer)</span></label>
          <textarea
            value={ai.instructions}
            onChange={(e) => setAi((a) => ({ ...a, instructions: e.target.value }))}
            rows={6}
            maxLength={8000}
            placeholder={"e.g.\nWe are Eydia.ai Store, open Mon–Sat 10am–8pm.\nWe sell mobile accessories; delivery across India in 3–5 days.\nReturns accepted within 7 days.\nFor prices, ask the customer which product they mean.\nIf someone wants to place an order, collect name, address and product."}
            style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, fontFamily: "inherit", resize: "vertical" }}
          />
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 8 }}>
            <span className="muted" style={{ fontSize: 12 }}>{ai.instructions.length}/8000 · Needs an AI API key (Gemini or Claude) configured on the server.</span>
            <button className="btn" style={{ width: "auto", padding: "9px 18px" }} disabled={aiSaving} onClick={saveAi}>{aiSaving ? "Saving…" : "Save AI settings"}</button>
          </div>
        </div>
      </div>

      {/* Auto follow-ups */}
      <div className="panel" style={{ marginBottom: 16, borderLeft: "3px solid #e8622d" }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", flexWrap: "wrap", gap: 8 }}>
          <div>
            <h3 style={{ margin: 0 }}>🔔 Auto Follow-up <span className="muted" style={{ fontSize: 12, fontWeight: 400 }}>(nudge quiet customers)</span></h3>
            <p className="muted" style={{ fontSize: 13, margin: "4px 0 0" }}>Agar customer reply na kare, to 24 ghante mein <b>2 baar</b> auto message jaata hai — <b>6 ghante</b> aur <b>12 ghante</b> par. Customer ke reply karte hi ruk jaata hai.</p>
          </div>
          <label style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 14, fontWeight: 600, cursor: "pointer", whiteSpace: "nowrap" }}>
            <input type="checkbox" checked={followup.enabled} onChange={(e) => setFollowup((f) => ({ ...f, enabled: e.target.checked }))} />
            Follow-up {followup.enabled ? "ON" : "OFF"}
          </label>
        </div>
        <div className="field" style={{ marginTop: 12, marginBottom: 0 }}>
          <label>Follow-up message</label>
          <textarea
            value={followup.message}
            onChange={(e) => setFollowup((f) => ({ ...f, message: e.target.value }))}
            rows={2}
            maxLength={1000}
            placeholder={"Hi! Aap aage process karna chahte hain? Koi sawaal ho to bataiye, hum madad ke liye yahin hain."}
            style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, fontFamily: "inherit", resize: "vertical" }}
          />
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 8 }}>
            <span className="muted" style={{ fontSize: 12 }}>Khaali chhodne par ek default message chala jaata hai. 24h window ke andar hi bhejta hai.</span>
            <button className="btn" style={{ width: "auto", padding: "9px 18px" }} disabled={followupSaving} onClick={saveFollowup}>{followupSaving ? "Saving…" : "Save follow-up"}</button>
          </div>
        </div>
      </div>

      <div className="grid" style={{ gridTemplateColumns: "1fr 360px", gap: 16, alignItems: "start" }}>
        {/* Rules list */}
        <div className="panel">
          <h3 style={{ marginTop: 0 }}>Rules <span className="muted" style={{ fontSize: 13, fontWeight: 400 }}>(checked in priority order)</span></h3>
          {bot.rules.length === 0 ? (
            <p className="muted">No rules yet. Add one on the right →</p>
          ) : (
            <table style={{ width: "100%", borderCollapse: "collapse" }}>
              <thead>
                <tr style={{ textAlign: "left", fontSize: 12, color: "#667781", borderBottom: "1px solid #eef1f2" }}>
                  <th style={{ padding: "6px 4px" }}>Pri</th>
                  <th style={{ padding: "6px 4px" }}>Keyword</th>
                  <th style={{ padding: "6px 4px" }}>Match</th>
                  <th style={{ padding: "6px 4px" }}>Response</th>
                  <th style={{ padding: "6px 4px" }}></th>
                </tr>
              </thead>
              <tbody>
                {[...bot.rules].sort((a, b) => a.priority - b.priority).map((r) => (
                  <tr key={r.id} style={{ borderBottom: "1px solid #f6f7f8", opacity: r.is_active ? 1 : 0.5 }}>
                    <td style={{ padding: "8px 4px", fontVariantNumeric: "tabular-nums" }}>{r.priority}</td>
                    <td style={{ padding: "8px 4px", fontWeight: 600 }}>{r.keyword}</td>
                    <td style={{ padding: "8px 4px", fontSize: 12, color: "#667781" }}>{r.match_type}</td>
                    <td style={{ padding: "8px 4px", fontSize: 13, maxWidth: 220, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                      {r.response_type === "template" ? `📋 ${r.template_name}` : r.response_text}
                    </td>
                    <td style={{ padding: "8px 4px", whiteSpace: "nowrap" }}>
                      <button className="btn-mini" onClick={() => editRule(r)}>Edit</button>{" "}
                      <button className="btn-mini" style={{ color: "#c53030" }} onClick={() => deleteRule(r.id)}>✕</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        {/* Add/edit rule form */}
        <div className="panel">
          <h3 style={{ marginTop: 0 }}>{editingRuleId ? "Edit rule" : "Add rule"}</h3>
          <form onSubmit={saveRule}>
            <div className="field">
              <label>Keyword / phrase</label>
              <input value={ruleForm.keyword} onChange={(e) => setRuleForm((f) => ({ ...f, keyword: e.target.value }))} required placeholder="price, hours, help…" />
            </div>
            <div className="field">
              <label>Match type</label>
              <select value={ruleForm.match_type} onChange={(e) => setRuleForm((f) => ({ ...f, match_type: e.target.value }))}>
                {MATCH_TYPES.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
              </select>
            </div>
            <div className="field">
              <label>Response type</label>
              <select value={ruleForm.response_type} onChange={(e) => setRuleForm((f) => ({ ...f, response_type: e.target.value }))}>
                <option value="text">Text reply</option>
                <option value="template">Template</option>
              </select>
            </div>
            {ruleForm.response_type === "text" ? (
              <div className="field">
                <label>Reply text</label>
                <textarea value={ruleForm.response_text} onChange={(e) => setRuleForm((f) => ({ ...f, response_text: e.target.value }))} rows={3} placeholder="Our prices start at…" />
              </div>
            ) : (
              <div className="field">
                <label>Template</label>
                {templates.length > 0 ? (
                  <select value={ruleForm.template_name} onChange={(e) => setRuleForm((f) => ({ ...f, template_name: e.target.value }))}>
                    <option value="">— Select a template —</option>
                    {!templates.some((t) => t.name === ruleForm.template_name) && ruleForm.template_name && (
                      <option value={ruleForm.template_name}>{ruleForm.template_name} (manual)</option>
                    )}
                    {templates.map((t) => (
                      <option key={t.id} value={t.name}>{t.name} · {t.language}{t.category ? ` · ${t.category.toLowerCase()}` : ""}</option>
                    ))}
                  </select>
                ) : (
                  <input value={ruleForm.template_name} onChange={(e) => setRuleForm((f) => ({ ...f, template_name: e.target.value }))} placeholder="No approved templates — type a name" />
                )}
                <span className="muted" style={{ fontSize: 12 }}>
                  {templates.length > 0 ? `${templates.length} approved template${templates.length === 1 ? "" : "s"}` : "Only approved templates can be sent."}
                </span>
              </div>
            )}
            <div className="field">
              <label>Priority <span className="muted">(lower = checked first)</span></label>
              <input type="number" value={ruleForm.priority} onChange={(e) => setRuleForm((f) => ({ ...f, priority: Number(e.target.value) }))} min={0} />
            </div>
            <label style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 12 }}>
              <input type="checkbox" checked={ruleForm.is_active} onChange={(e) => setRuleForm((f) => ({ ...f, is_active: e.target.checked }))} />
              <span>Rule active</span>
            </label>
            <div style={{ display: "flex", gap: 8 }}>
              <button className="btn" disabled={savingRule}>{savingRule ? "Saving…" : editingRuleId ? "Update rule" : "Add rule"}</button>
              {editingRuleId && <button type="button" className="btn-mini" onClick={resetRuleForm}>Cancel</button>}
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}
