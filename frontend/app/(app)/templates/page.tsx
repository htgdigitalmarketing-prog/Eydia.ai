"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { api, ApiError, TemplateItem, TemplateButton, TemplateCreateBody } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { PageHeader } from "@/components/PageHeader";
import { UpgradePrompt, planAllows } from "@/components/PlanGate";
import { useUser } from "@/lib/user-context";

const STATUS_COLORS: Record<string, { bg: string; fg: string }> = {
  APPROVED: { bg: "#e7f7ef", fg: "#0a7d47" },
  PENDING: { bg: "#fff3cd", fg: "#856404" },
  REJECTED: { bg: "#fdecec", fg: "#c53030" },
  PAUSED: { bg: "#eef1f2", fg: "#667781" },
  DISABLED: { bg: "#eef1f2", fg: "#667781" },
};

const CATEGORY_COLORS: Record<string, string> = {
  MARKETING: "#6f42c1",
  UTILITY: "#0d6efd",
  AUTHENTICATION: "#fd7e14",
};

const LANGUAGES = [
  { code: "en", label: "English" },
  { code: "en_US", label: "English (US)" },
  { code: "en_GB", label: "English (UK)" },
  { code: "hi", label: "Hindi" },
  { code: "es", label: "Spanish" },
  { code: "pt_BR", label: "Portuguese (BR)" },
  { code: "ar", label: "Arabic" },
  { code: "id", label: "Indonesian" },
];

const HEADER_FORMATS = ["NONE", "TEXT", "IMAGE", "VIDEO", "DOCUMENT"] as const;
const BUTTON_TYPES = [
  { value: "QUICK_REPLY", label: "Quick reply" },
  { value: "URL", label: "Visit website (URL)" },
  { value: "PHONE_NUMBER", label: "Call phone number" },
  { value: "COPY_CODE", label: "Copy offer code" },
] as const;

type BuilderState = {
  name: string;
  category: TemplateCreateBody["category"];
  language: string;
  header_format: TemplateCreateBody["header_format"];
  header_text: string;
  header_handle: string;
  header_sample_name: string;
  header_preview: string; // local object URL for live preview of the chosen media
  body: string;
  footer: string;
  buttons: TemplateButton[];
  body_example: string[];
};

const EMPTY_BUILDER: BuilderState = {
  name: "",
  category: "MARKETING",
  language: "en",
  header_format: "NONE",
  header_text: "",
  header_handle: "",
  header_sample_name: "",
  header_preview: "",
  body: "Hi {{1}}, thanks for choosing us! Your order {{2}} is confirmed. 🎉",
  footer: "",
  buttons: [],
  body_example: [],
};

/** Count distinct {{n}} placeholders in a body string. */
function varCount(body: string): number {
  const set = new Set<string>();
  const re = /\{\{\s*(\d+)\s*\}\}/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(body))) set.add(m[1]);
  return set.size;
}

export default function TemplatesPage() {
  const gateUser = useUser();
  const [templates, setTemplates] = useState<TemplateItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [detail, setDetail] = useState<TemplateItem | null>(null);

  const [showBuilder, setShowBuilder] = useState(false);
  const [b, setB] = useState<BuilderState>({ ...EMPTY_BUILDER });
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);

  async function uploadHeaderSample(file: File) {
    const token = getToken();
    if (!token) return;
    setUploading(true);
    setError(null);
    // Show a real preview straight away from the local file (Meta upload runs in the background).
    const previewUrl = URL.createObjectURL(file);
    setB((s) => { if (s.header_preview) URL.revokeObjectURL(s.header_preview); return { ...s, header_preview: previewUrl }; });
    try {
      const { header_handle } = await api.templates.uploadSample(token, file);
      setB((s) => ({ ...s, header_handle, header_sample_name: file.name }));
    } catch (err) {
      const e = err as ApiError;
      setError(e.errors ? Object.values(e.errors).flat().join(". ") : e.message);
      URL.revokeObjectURL(previewUrl);
      setB((s) => ({ ...s, header_handle: "", header_sample_name: "", header_preview: "" }));
    } finally {
      setUploading(false);
    }
  }

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const res = await api.templates.list(token, {
        search: search || undefined,
        status: statusFilter || undefined,
        category: categoryFilter || undefined,
      });
      setTemplates(res.templates);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, [search, statusFilter, categoryFilter]);

  useEffect(() => { load(); }, [load]);

  function flash(msg: string) {
    setNotice(msg);
    setTimeout(() => setNotice(null), 5000);
  }

  async function syncTemplates() {
    const token = getToken();
    if (!token) return;
    setSyncing(true);
    setError(null);
    try {
      const res = await api.templates.sync(token);
      flash(res.message);
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSyncing(false);
    }
  }

  async function viewDetail(id: string) {
    const token = getToken();
    if (!token) return;
    try {
      const res = await api.templates.get(token, id);
      setDetail(res.template);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function removeTemplate(t: TemplateItem) {
    const token = getToken();
    if (!token) return;
    if (!confirm(`Delete template "${t.name}"? This also removes it from Meta.`)) return;
    try {
      await api.templates.remove(token, t.id);
      setTemplates((prev) => prev.filter((x) => x.id !== t.id));
      flash("Template deleted.");
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  const nVars = useMemo(() => varCount(b.body), [b.body]);

  function openBuilder() {
    setB({ ...EMPTY_BUILDER });
    setError(null);
    setShowBuilder(true);
  }

  function setBody(body: string) {
    setB((s) => {
      const count = varCount(body);
      const ex = s.body_example.slice(0, count);
      while (ex.length < count) ex.push("");
      return { ...s, body, body_example: ex };
    });
  }

  function insertVar() {
    setBody(b.body + `{{${nVars + 1}}}`);
  }

  function addButton(type: TemplateButton["type"]) {
    if (b.buttons.length >= 10) return;
    setB((s) => ({ ...s, buttons: [...s.buttons, { type, text: "" }] }));
  }
  function updateButton(i: number, patch: Partial<TemplateButton>) {
    setB((s) => ({ ...s, buttons: s.buttons.map((btn, j) => (j === i ? { ...btn, ...patch } : btn)) }));
  }
  function removeButton(i: number) {
    setB((s) => ({ ...s, buttons: s.buttons.filter((_, j) => j !== i) }));
  }

  async function submitBuilder(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSaving(true);
    setError(null);
    try {
      const isMediaHeader = ["IMAGE", "VIDEO", "DOCUMENT"].includes(b.header_format ?? "");
      if (isMediaHeader && !b.header_handle) {
        setError(`Please upload a sample ${b.header_format?.toLowerCase()} for the header before submitting.`);
        setSaving(false);
        return;
      }
      const body: TemplateCreateBody = {
        name: b.name.trim().toLowerCase().replace(/[^a-z0-9_]+/g, "_"),
        language: b.language,
        category: b.category,
        header_format: b.header_format,
        header_text: b.header_format === "TEXT" ? b.header_text : undefined,
        header_handle: isMediaHeader ? b.header_handle : undefined,
        body: b.body,
        body_example: nVars > 0 ? b.body_example : undefined,
        footer: b.footer || undefined,
        buttons: b.buttons.length ? b.buttons : undefined,
      };
      const res = await api.templates.create(token, body);
      setShowBuilder(false);
      flash(res.message);
      await load();
    } catch (err) {
      const e2 = err as ApiError;
      const fieldErrors = e2.errors ? Object.values(e2.errors).flat().join(". ") : "";
      setError(fieldErrors || e2.message);
    } finally {
      setSaving(false);
    }
  }

  if (!planAllows(gateUser.plan_limits, "max_templates")) return <UpgradePrompt title="Templates" feature="Templates" />;

  return (
    <>
      <PageHeader
        title="Templates"
        subtitle="Create WhatsApp message templates and track Meta approval"
        action={
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn-mini" disabled={syncing} onClick={syncTemplates}>
              {syncing ? "Syncing…" : "Sync from Meta"}
            </button>
            <button className="btn" style={{ width: "auto", padding: "10px 18px" }} onClick={openBuilder}>
              + Create template
            </button>
          </div>
        }
      />

      {notice && <div className="panel" style={{ background: "#e7f7ef", borderColor: "#b6e6cd", color: "#0a7d47" }}>{notice}</div>}
      {error && !showBuilder && <div className="error">{error}</div>}

      {/* Filters */}
      <div style={{ display: "flex", gap: 10, marginBottom: 16, flexWrap: "wrap" }}>
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search templates..."
          style={{ flex: 1, minWidth: 200, padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}
        />
        <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} style={{ padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
          <option value="">All statuses</option>
          <option value="APPROVED">Approved</option>
          <option value="PENDING">Pending</option>
          <option value="REJECTED">Rejected</option>
        </select>
        <select value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)} style={{ padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
          <option value="">All categories</option>
          <option value="MARKETING">Marketing</option>
          <option value="UTILITY">Utility</option>
          <option value="AUTHENTICATION">Authentication</option>
        </select>
      </div>

      {/* Template detail */}
      {detail && (
        <div className="panel">
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
            <h2 style={{ fontFamily: "monospace" }}>{detail.name}</h2>
            <button className="btn-mini" onClick={() => setDetail(null)}>Close</button>
          </div>
          <div style={{ display: "flex", gap: 16, flexWrap: "wrap", margin: "12px 0" }}>
            <div><span className="muted">Language:</span> {detail.language}</div>
            <div><span className="muted">Category:</span> <span style={{ color: CATEGORY_COLORS[detail.category] ?? "#333" }}>{detail.category}</span></div>
            <div><span className="muted">Status:</span> <StatusBadge status={detail.status} /></div>
            {detail.quality_score && <div><span className="muted">Quality:</span> {detail.quality_score}</div>}
          </div>
          {detail.rejection_reason && (
            <div style={{ background: "#fdecec", padding: "8px 12px", borderRadius: 8, color: "#c53030", fontSize: 13, marginBottom: 12 }}>
              Rejection reason: {detail.rejection_reason}
            </div>
          )}
          {detail.components && detail.components.length > 0 && (
            <div>
              <h3 style={{ fontSize: 14, marginBottom: 8 }}>Components</h3>
              {detail.components.map((comp, i) => (
                <div key={i} style={{ background: "var(--bg-subtle, #f8f9fa)", padding: "10px 14px", borderRadius: 8, marginBottom: 8, border: "1px solid var(--border)" }}>
                  <div style={{ fontSize: 11, fontWeight: 600, textTransform: "uppercase", color: "#888", marginBottom: 4 }}>
                    {comp.type} {comp.format && `(${comp.format})`}
                  </div>
                  {comp.text && <div style={{ fontSize: 14, whiteSpace: "pre-wrap" }}>{comp.text}</div>}
                  {comp.buttons && (
                    <div style={{ display: "flex", gap: 8, marginTop: 8, flexWrap: "wrap" }}>
                      {(comp.buttons as { type: string; text: string }[]).map((btn, j) => (
                        <span key={j} style={{ padding: "4px 12px", border: "1px solid var(--border)", borderRadius: 6, fontSize: 12 }}>{btn.text}</span>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Templates table */}
      <div className="panel">
        {loading ? (
          <p className="muted">Loading…</p>
        ) : templates.length === 0 ? (
          <div style={{ textAlign: "center", padding: 32 }}>
            <div style={{ fontSize: 40 }}>▧</div>
            <h3 style={{ margin: "10px 0 4px" }}>No templates yet</h3>
            <p className="muted" style={{ marginBottom: 14 }}>Create your first template, or sync approved ones from Meta.</p>
            <button className="btn" style={{ width: "auto", padding: "10px 18px" }} onClick={openBuilder}>+ Create template</button>
          </div>
        ) : (
          <div style={{ overflowX: "auto" }}>
            <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
              <thead>
                <tr style={{ borderBottom: "2px solid var(--border)" }}>
                  <th style={{ textAlign: "left", padding: "8px 4px", width: 48 }}>#</th>
                  <th style={{ textAlign: "left", padding: "8px 4px" }}>Template name</th>
                  <th style={{ textAlign: "left", padding: "8px 4px" }}>Language</th>
                  <th style={{ textAlign: "left", padding: "8px 4px" }}>Category</th>
                  <th style={{ textAlign: "left", padding: "8px 4px" }}>Status</th>
                  <th style={{ textAlign: "right", padding: "8px 4px" }}></th>
                </tr>
              </thead>
              <tbody>
                {templates.map((t, i) => (
                  <tr key={t.id} style={{ borderBottom: "1px solid var(--border)" }}>
                    <td style={{ padding: "8px 4px", color: "var(--muted)", fontVariantNumeric: "tabular-nums" }}>{i + 1}</td>
                    <td style={{ padding: "8px 4px", fontFamily: "monospace", fontWeight: 500 }}>{t.name}</td>
                    <td style={{ padding: "8px 4px" }}>{t.language}</td>
                    <td style={{ padding: "8px 4px" }}>
                      <span style={{ color: CATEGORY_COLORS[t.category] ?? "#333", fontWeight: 500 }}>{t.category}</span>
                    </td>
                    <td style={{ padding: "8px 4px" }}><StatusBadge status={t.status} /></td>
                    <td style={{ padding: "8px 4px", textAlign: "right", whiteSpace: "nowrap" }}>
                      <button className="btn-mini" onClick={() => viewDetail(t.id)}>View</button>
                      <button className="btn-mini" style={{ color: "#c53030", marginLeft: 6 }} onClick={() => removeTemplate(t)}>Delete</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Builder */}
      {showBuilder && (
        <div className="msg-info-overlay" onClick={() => setShowBuilder(false)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 900, width: "94vw" }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 6 }}>
              <h2 style={{ margin: 0 }}>Create template</h2>
              <button className="btn-mini" onClick={() => setShowBuilder(false)}>Close</button>
            </div>
            <p className="muted" style={{ fontSize: 13, marginTop: 0 }}>Design your template and see a live WhatsApp preview. It&apos;s submitted to Meta for approval.</p>
            {error && <div className="error">{error}</div>}

            <div style={{ display: "grid", gridTemplateColumns: "1fr 320px", gap: 20, alignItems: "start" }}>
              {/* Form */}
              <form onSubmit={submitBuilder} id="tpl-form">
                <div style={{ display: "flex", gap: 10 }}>
                  <div className="field" style={{ flex: 2 }}>
                    <label>Template name</label>
                    <input value={b.name} onChange={(e) => setB((s) => ({ ...s, name: e.target.value }))} required placeholder="order_confirmation" />
                    <span className="muted" style={{ fontSize: 12 }}>Lowercase, numbers and underscores only.</span>
                  </div>
                  <div className="field" style={{ flex: 1 }}>
                    <label>Language</label>
                    <select value={b.language} onChange={(e) => setB((s) => ({ ...s, language: e.target.value }))}>
                      {LANGUAGES.map((l) => <option key={l.code} value={l.code}>{l.label}</option>)}
                    </select>
                  </div>
                </div>

                <div className="field">
                  <label>Category</label>
                  <div style={{ display: "flex", gap: 8 }}>
                    {(["MARKETING", "UTILITY", "AUTHENTICATION"] as const).map((c) => (
                      <button type="button" key={c} onClick={() => setB((s) => ({ ...s, category: c }))}
                        style={{
                          flex: 1, padding: "8px 6px", borderRadius: 8, fontSize: 12, fontWeight: 600, cursor: "pointer",
                          border: `1px solid ${b.category === c ? CATEGORY_COLORS[c] : "var(--border)"}`,
                          background: b.category === c ? CATEGORY_COLORS[c] : "#fff",
                          color: b.category === c ? "#fff" : "#54656f",
                        }}>{c}</button>
                    ))}
                  </div>
                </div>

                <div className="field">
                  <label>Header <span className="muted">(optional)</span></label>
                  <select value={b.header_format} onChange={(e) => setB((s) => { if (s.header_preview) URL.revokeObjectURL(s.header_preview); return { ...s, header_format: e.target.value as BuilderState["header_format"], header_handle: "", header_sample_name: "", header_preview: "" }; })}>
                    {HEADER_FORMATS.map((f) => <option key={f} value={f}>{f === "NONE" ? "None" : f.charAt(0) + f.slice(1).toLowerCase()}</option>)}
                  </select>
                  {b.header_format === "TEXT" && (
                    <input style={{ marginTop: 8 }} maxLength={60} value={b.header_text} onChange={(e) => setB((s) => ({ ...s, header_text: e.target.value }))} placeholder="Header text (max 60 chars)" />
                  )}
                  {["IMAGE", "VIDEO", "DOCUMENT"].includes(b.header_format ?? "") && (
                    <div style={{ marginTop: 8 }}>
                      <input
                        type="file"
                        accept={b.header_format === "IMAGE" ? "image/jpeg,image/png" : b.header_format === "VIDEO" ? "video/mp4" : "application/pdf"}
                        disabled={uploading}
                        onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadHeaderSample(f); }}
                        style={{ fontSize: 13 }}
                      />
                      <div className="muted" style={{ fontSize: 12, marginTop: 4 }}>
                        {uploading ? "Uploading sample…"
                          : b.header_handle ? `✓ Sample uploaded${b.header_sample_name ? `: ${b.header_sample_name}` : ""}`
                          : `Meta needs a sample ${b.header_format?.toLowerCase()} to review this template. Max 5MB.`}
                      </div>
                    </div>
                  )}
                </div>

                <div className="field">
                  <label style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                    <span>Body</span>
                    <button type="button" className="btn-mini" onClick={insertVar}>+ Add variable</button>
                  </label>
                  <textarea value={b.body} onChange={(e) => setBody(e.target.value)} required rows={4} maxLength={1024}
                    style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, fontFamily: "inherit", resize: "vertical" }} />
                  <span className="muted" style={{ fontSize: 12 }}>Use {"{{1}}"}, {"{{2}}"} for personalization. {b.body.length}/1024</span>
                </div>

                {nVars > 0 && (
                  <div className="field">
                    <label>Sample values <span className="muted">(so Meta can review)</span></label>
                    <div style={{ display: "grid", gap: 6 }}>
                      {Array.from({ length: nVars }).map((_, i) => (
                        <div key={i} style={{ display: "flex", alignItems: "center", gap: 8 }}>
                          <span style={{ fontSize: 12, width: 36 }}>{`{{${i + 1}}}`}</span>
                          <input value={b.body_example[i] ?? ""} placeholder={`Example for {{${i + 1}}}`}
                            onChange={(e) => setB((s) => { const ex = [...s.body_example]; ex[i] = e.target.value; return { ...s, body_example: ex }; })}
                            style={{ flex: 1, padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }} />
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                <div className="field">
                  <label>Footer <span className="muted">(optional)</span></label>
                  <input maxLength={60} value={b.footer} onChange={(e) => setB((s) => ({ ...s, footer: e.target.value }))} placeholder="e.g. Reply STOP to unsubscribe" />
                </div>

                <div className="field">
                  <label style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                    <span>Buttons <span className="muted">(optional)</span></span>
                  </label>
                  <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginBottom: 8 }}>
                    {BUTTON_TYPES.map((t) => (
                      <button type="button" key={t.value} className="btn-mini" disabled={b.buttons.length >= 10} onClick={() => addButton(t.value)}>+ {t.label}</button>
                    ))}
                  </div>
                  {b.buttons.map((btn, i) => (
                    <div key={i} style={{ border: "1px solid var(--border)", borderRadius: 8, padding: 10, marginBottom: 8 }}>
                      <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 6 }}>
                        <span style={{ fontSize: 12, fontWeight: 600, color: "#54656f" }}>{BUTTON_TYPES.find((t) => t.value === btn.type)?.label}</span>
                        <button type="button" onClick={() => removeButton(i)} style={{ border: "none", background: "none", color: "#c53030", cursor: "pointer", fontSize: 12 }}>Remove</button>
                      </div>
                      <input value={btn.text} maxLength={25} placeholder="Button text (max 25)" onChange={(e) => updateButton(i, { text: e.target.value })}
                        style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13, marginBottom: btn.type === "QUICK_REPLY" ? 0 : 6 }} />
                      {btn.type === "URL" && (
                        <input value={btn.url ?? ""} placeholder="https://example.com/{{1}}" onChange={(e) => updateButton(i, { url: e.target.value })}
                          style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }} />
                      )}
                      {btn.type === "PHONE_NUMBER" && (
                        <input value={btn.phone_number ?? ""} placeholder="+91 98765 43210" onChange={(e) => updateButton(i, { phone_number: e.target.value })}
                          style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }} />
                      )}
                      {btn.type === "COPY_CODE" && (
                        <input value={btn.example ?? ""} placeholder="Offer code, e.g. SAVE20" onChange={(e) => updateButton(i, { example: e.target.value })}
                          style={{ width: "100%", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }} />
                      )}
                    </div>
                  ))}
                </div>

                <div style={{ display: "flex", gap: 8, marginTop: 6 }}>
                  <button className="btn" disabled={saving} type="submit">{saving ? "Submitting…" : "Submit for approval"}</button>
                  <button type="button" className="btn-mini" onClick={() => setShowBuilder(false)}>Cancel</button>
                </div>
              </form>

              {/* Live phone preview */}
              <div style={{ position: "sticky", top: 0 }}>
                <PhonePreview state={b} />
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function StatusBadge({ status }: { status: string }) {
  const c = STATUS_COLORS[status] ?? STATUS_COLORS.PENDING;
  return <span style={{ background: c.bg, color: c.fg, padding: "2px 9px", borderRadius: 999, fontSize: 12, fontWeight: 600 }}>{status}</span>;
}

/** WhatsApp-style live preview of the template being built. */
function PhonePreview({ state }: { state: BuilderState }) {
  const rendered = useMemo(() => {
    let text = state.body;
    text = text.replace(/\{\{\s*(\d+)\s*\}\}/g, (_, n) => {
      const v = state.body_example[Number(n) - 1];
      return v && v.trim() ? v : `{{${n}}}`;
    });
    return text;
  }, [state.body, state.body_example]);

  const headerIcon = state.header_format === "IMAGE" ? "🖼️" : state.header_format === "VIDEO" ? "🎬" : state.header_format === "DOCUMENT" ? "📄" : null;
  const time = new Date().toLocaleTimeString("en-IN", { hour: "2-digit", minute: "2-digit" });

  return (
    <div>
      <div style={{ fontSize: 12, fontWeight: 600, color: "#54656f", marginBottom: 8, textAlign: "center" }}>Preview</div>
      <div style={{
        border: "8px solid #111b21", borderRadius: 28, padding: 0, background: "#0b141a", overflow: "hidden",
        boxShadow: "0 10px 30px rgba(0,0,0,.18)",
      }}>
        <div style={{ background: "#075e54", color: "#fff", padding: "10px 12px", display: "flex", alignItems: "center", gap: 8, fontSize: 13 }}>
          <span style={{ width: 26, height: 26, borderRadius: "50%", background: "#128c7e", display: "inline-flex", alignItems: "center", justifyContent: "center" }}>🏢</span>
          <div><div style={{ fontWeight: 600 }}>Your Business</div><div style={{ fontSize: 10, opacity: 0.8 }}>online</div></div>
        </div>
        <div style={{ background: "#e5ddd5", padding: "16px 12px", minHeight: 260, backgroundImage: "linear-gradient(rgba(229,221,213,.6),rgba(229,221,213,.6))" }}>
          <div style={{ background: "#fff", borderRadius: 8, padding: 8, maxWidth: 250, boxShadow: "0 1px 1px rgba(0,0,0,.1)", fontSize: 13, color: "#111b21" }}>
            {headerIcon && (
              state.header_preview ? (
                state.header_format === "VIDEO" ? (
                  <video src={state.header_preview} style={{ width: "100%", borderRadius: 6, marginBottom: 6, maxHeight: 150, objectFit: "cover", background: "#000" }} muted />
                ) : state.header_format === "IMAGE" ? (
                  <img src={state.header_preview} alt="" style={{ width: "100%", borderRadius: 6, marginBottom: 6, maxHeight: 150, objectFit: "cover" }} />
                ) : (
                  <div style={{ background: "#d9e2e6", borderRadius: 6, padding: "10px 8px", display: "flex", alignItems: "center", gap: 6, marginBottom: 6, fontSize: 12 }}>📄 {state.header_sample_name || "Document"}</div>
                )
              ) : (
                <div style={{ background: "#d9e2e6", borderRadius: 6, height: 96, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 34, marginBottom: 6 }}>{headerIcon}</div>
              )
            )}
            {state.header_format === "TEXT" && state.header_text && (
              <div style={{ fontWeight: 700, marginBottom: 4 }}>{state.header_text}</div>
            )}
            <div style={{ whiteSpace: "pre-wrap", lineHeight: 1.4 }}>{rendered || <span style={{ color: "#8696a0" }}>Your message body…</span>}</div>
            {state.footer && <div style={{ color: "#8696a0", fontSize: 11, marginTop: 6 }}>{state.footer}</div>}
            <div style={{ textAlign: "right", color: "#8696a0", fontSize: 10, marginTop: 4 }}>{time}</div>
          </div>
          {state.buttons.length > 0 && (
            <div style={{ maxWidth: 250, marginTop: 4, display: "grid", gap: 3 }}>
              {state.buttons.map((btn, i) => (
                <div key={i} style={{ background: "#fff", borderRadius: 8, padding: "8px", textAlign: "center", color: "#0096de", fontSize: 13, fontWeight: 500, boxShadow: "0 1px 1px rgba(0,0,0,.1)" }}>
                  {btn.type === "URL" ? "🔗 " : btn.type === "PHONE_NUMBER" ? "📞 " : btn.type === "COPY_CODE" ? "📋 " : "↩ "}
                  {btn.text || <span style={{ color: "#8696a0" }}>Button</span>}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
