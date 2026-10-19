"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, BulkSend, BulkSendDetail, CampaignItem, CampaignDetail, TemplateItem, TagItem } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { PageHeader } from "@/components/PageHeader";
import { UpgradePrompt, planAllows } from "@/components/PlanGate";
import { useUser } from "@/lib/user-context";

const STATUS_COLORS: Record<string, { bg: string; fg: string }> = {
  draft: { bg: "#eef1f2", fg: "#667781" },
  scheduled: { bg: "#fff3cd", fg: "#856404" },
  running: { bg: "#cce5ff", fg: "#004085" },
  paused: { bg: "#fff3cd", fg: "#856404" },
  completed: { bg: "#e7f7ef", fg: "#0a7d47" },
  cancelled: { bg: "#eef1f2", fg: "#667781" },
  failed: { bg: "#fdecec", fg: "#c53030" },
  sent: { bg: "#e7f7ef", fg: "#0a7d47" },
  processing: { bg: "#cce5ff", fg: "#004085" },
};

function StatusBadge({ status }: { status: string }) {
  const c = STATUS_COLORS[status] ?? STATUS_COLORS.draft;
  return (
    <span style={{ background: c.bg, color: c.fg, padding: "2px 9px", borderRadius: 999, fontSize: 12, fontWeight: 600 }}>
      {status}
    </span>
  );
}

/** delivered / total as a percentage string. */
function deliveryPct(total: number, delivered: number): string {
  if (!total) return "0.00%";
  return `${((delivered / total) * 100).toFixed(2)}%`;
}

/** Build a CSV string from headers + rows and trigger a browser download. */
function downloadCsv(filename: string, headers: string[], rows: (string | number)[][]) {
  const esc = (v: string | number) => {
    const s = String(v ?? "");
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const csv = [headers, ...rows].map((r) => r.map(esc).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

type Tab = "campaigns" | "bulk";

export default function CampaignsPage() {
  const gateUser = useUser();
  const [tab, setTab] = useState<Tab>("campaigns");
  const [campaigns, setCampaigns] = useState<CampaignItem[]>([]);
  const [sends, setSends] = useState<BulkSend[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Campaign create form
  const [showCreate, setShowCreate] = useState(false);
  const [templates, setTemplates] = useState<TemplateItem[]>([]);
  const [tags, setTags] = useState<TagItem[]>([]);
  const [form, setForm] = useState({ name: "", template_id: "", tags: [] as string[], scheduled_at: "" });
  const [submitting, setSubmitting] = useState(false);

  // Campaign detail
  const [detail, setDetail] = useState<CampaignDetail | null>(null);

  // Bulk send form
  const [showBulk, setShowBulk] = useState(false);
  const [bulkNumbers, setBulkNumbers] = useState("");
  const [bulkTemplate, setBulkTemplate] = useState("test_welcome");
  const [bulkLang, setBulkLang] = useState("en");
  const [bulkVars, setBulkVars] = useState<string[]>([]);
  const [bulkHeaderUrl, setBulkHeaderUrl] = useState("");
  const [bulkHeaderMode, setBulkHeaderMode] = useState<"upload" | "url">("upload");
  const [bulkHeaderId, setBulkHeaderId] = useState("");
  const [bulkHeaderName, setBulkHeaderName] = useState("");
  const [bulkHeaderUploading, setBulkHeaderUploading] = useState(false);
  const [retryingId, setRetryingId] = useState<string | null>(null);
  const [bulkDetail, setBulkDetail] = useState<BulkSendDetail | null>(null);

  const loadCampaigns = useCallback(async (silent = false) => {
    const token = getToken();
    if (!token) return;
    if (!silent) setLoading(true);
    try {
      const res = await api.campaigns.list(token);
      setCampaigns(res.campaigns);
    } catch (err) {
      if (!silent) setError((err as ApiError).message);
    } finally {
      if (!silent) setLoading(false);
    }
  }, []);

  const loadBulkSends = useCallback(async (silent = false) => {
    const token = getToken();
    if (!token) return;
    if (!silent) setLoading(true);
    try {
      const res = await api.bulk.list(token);
      setSends(res.bulk_sends);
    } catch (err) {
      if (!silent) setError((err as ApiError).message);
    } finally {
      if (!silent) setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (tab === "campaigns") loadCampaigns();
    else loadBulkSends();
  }, [tab, loadCampaigns, loadBulkSends]);

  // Poll while anything is still sending. Each poll also lets the server pick up
  // a run whose background worker was stopped by the host.
  const bulkActive = sends.some((s) => s.status === "processing") || bulkDetail?.status === "processing";
  const campaignActive = campaigns.some((c) => c.status === "running") || detail?.status === "running";
  const activeBulkDetailId = bulkDetail?.status === "processing" ? bulkDetail.uuid : null;
  const activeCampaignDetailId = detail?.status === "running" ? detail.id : null;

  useEffect(() => {
    const active = tab === "bulk" ? bulkActive : campaignActive;
    if (!active) return;
    const timer = setInterval(async () => {
      const token = getToken();
      if (!token) return;
      if (tab === "bulk") {
        loadBulkSends(true);
        if (activeBulkDetailId) {
          try { setBulkDetail((await api.bulk.get(token, activeBulkDetailId)).bulk_send); } catch {}
        }
      } else {
        loadCampaigns(true);
        if (activeCampaignDetailId) {
          try { setDetail((await api.campaigns.get(token, activeCampaignDetailId)).campaign); } catch {}
        }
      }
    }, 5000);
    return () => clearInterval(timer);
  }, [tab, bulkActive, campaignActive, activeBulkDetailId, activeCampaignDetailId, loadBulkSends, loadCampaigns]);

  async function openCreateForm() {
    const token = getToken();
    if (!token) return;
    setShowCreate(true);
    setShowBulk(false);
    setError(null);
    try {
      const [t, tg] = await Promise.all([api.templates.list(token, { status: "APPROVED" }), api.tags.list(token)]);
      setTemplates(t.templates);
      setTags(tg.tags);
    } catch {}
  }

  // Open Bulk Send and load the tenant's approved templates for the dropdown.
  async function openBulk() {
    if (showBulk) { setShowBulk(false); return; }
    setShowBulk(true);
    setShowCreate(false);
    setError(null);
    const token = getToken();
    if (!token) return;
    try {
      const t = await api.templates.list(token, { status: "APPROVED" });
      setTemplates(t.templates);
      // Default the picker to the first approved template if the current one isn't in the list.
      if (t.templates.length > 0 && !t.templates.some((x) => x.name === bulkTemplate)) {
        const first = t.templates[0];
        setBulkTemplate(first.name);
        setBulkLang(first.language || "en");
        const body = first.components?.find((c) => (c.type || "").toUpperCase() === "BODY");
        const matches = body?.text?.match(/\{\{\s*(\d+)\s*\}\}/g);
        const count = matches ? Math.max(...matches.map((m) => parseInt(m.replace(/\D/g, ""), 10))) : 0;
        setBulkVars(Array(count).fill(""));
        setBulkHeaderUrl(""); setBulkHeaderId(""); setBulkHeaderName("");
      }
    } catch {}
  }

  // Number of BODY variables ({{1}}, {{2}}, …) a template expects.
  function templateVarCount(name: string): number {
    const tpl = templates.find((t) => t.name === name);
    const body = tpl?.components?.find((c) => (c.type || "").toUpperCase() === "BODY");
    if (!body?.text) return 0;
    const matches = body.text.match(/\{\{\s*(\d+)\s*\}\}/g);
    if (!matches) return 0;
    return Math.max(...matches.map((m) => parseInt(m.replace(/\D/g, ""), 10)));
  }

  // Media HEADER format of a template (IMAGE / VIDEO / DOCUMENT), or null for
  // text/none. Such templates need a public media URL supplied at send time.
  function templateHeaderMedia(name: string): "IMAGE" | "VIDEO" | "DOCUMENT" | null {
    const tpl = templates.find((t) => t.name === name);
    const header = tpl?.components?.find((c) => (c.type || "").toUpperCase() === "HEADER");
    const fmt = (header?.format || "").toUpperCase();
    return fmt === "IMAGE" || fmt === "VIDEO" || fmt === "DOCUMENT" ? fmt : null;
  }

  // When a template is picked, set its name, language, and reset variable slots.
  function pickBulkTemplate(name: string) {
    setBulkTemplate(name);
    const tpl = templates.find((t) => t.name === name);
    if (tpl?.language) setBulkLang(tpl.language);
    setBulkVars(Array(templateVarCount(name)).fill(""));
    setBulkHeaderUrl(""); setBulkHeaderId(""); setBulkHeaderName("");
  }

  async function createCampaign(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSubmitting(true);
    setError(null);
    try {
      const body: Parameters<typeof api.campaigns.create>[1] = {
        name: form.name,
        template_id: form.template_id,
      };
      if (form.tags.length) body.audience_filter = { tags: form.tags };
      if (form.scheduled_at) body.scheduled_at = form.scheduled_at;
      await api.campaigns.create(token, body);
      setNotice("Campaign created.");
      setShowCreate(false);
      setForm({ name: "", template_id: "", tags: [], scheduled_at: "" });
      loadCampaigns();
    } catch (err) {
      const e = err as ApiError;
      setError(e.errors ? Object.values(e.errors).flat().join(". ") : e.message);
    } finally {
      setSubmitting(false);
    }
  }

  async function viewCampaignDetail(id: string) {
    const token = getToken();
    if (!token) return;
    try {
      const res = await api.campaigns.get(token, id);
      setDetail(res.campaign);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function campaignAction(id: string, action: "start" | "pause" | "cancel") {
    const token = getToken();
    if (!token) return;
    setError(null);
    try {
      await api.campaigns[action](token, id);
      setNotice(`Campaign ${action}ed.`);
      loadCampaigns();
      if (detail?.id === id) viewCampaignDetail(id);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function deleteCampaign(id: string) {
    const token = getToken();
    if (!token) return;
    try {
      await api.campaigns.remove(token, id);
      setNotice("Campaign deleted.");
      setDetail(null);
      loadCampaigns();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  // Bulk send
  function parseNumbers(text: string): string[] {
    return text.split(/[\n,;]+/).map((n) => n.trim().replace(/[^0-9+]/g, "")).filter((n) => n.length >= 10);
  }

  async function importNumbersFile(file: File) {
    const text = await file.text();
    // Pull every phone-number-like token from any column of a CSV/TXT (skips headers automatically).
    const found = (text.match(/\+?\d[\d\s\-()]{8,}\d/g) || [])
      .map((t) => t.replace(/[^0-9+]/g, ""))
      .filter((n) => n.length >= 10);
    if (found.length === 0) { setError(`No phone numbers found in ${file.name}`); return; }
    let added = 0;
    setBulkNumbers((prev) => {
      const existing = parseNumbers(prev);
      const merged = Array.from(new Set([...existing, ...found])); // de-dupe
      added = merged.length - existing.length;
      return merged.join("\n");
    });
    setError(null);
    setNotice(`Imported ${added} new number${added === 1 ? "" : "s"} from ${file.name}.`);
  }

  async function sendBulk(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    const nums = parseNumbers(bulkNumbers);
    if (!nums.length) { setError("No valid numbers found."); return; }
    if (bulkVars.some((v) => v.trim() === "")) {
      setError("Please fill in all template variables before sending.");
      return;
    }
    const headerMedia = templateHeaderMedia(bulkTemplate);
    const hasHeaderInput = bulkHeaderMode === "upload" ? !!bulkHeaderId : !!bulkHeaderUrl.trim();
    if (headerMedia && !hasHeaderInput) {
      setError(bulkHeaderMode === "upload"
        ? `This template has an ${headerMedia.toLowerCase()} header. Please choose an ${headerMedia.toLowerCase()} file to upload.`
        : `This template has an ${headerMedia.toLowerCase()} header. Please paste a public ${headerMedia.toLowerCase()} URL.`);
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const { bulk_send: bs } = await api.bulk.send(token, {
        numbers: nums,
        template: bulkTemplate,
        language: bulkLang,
        ...(bulkVars.length > 0 ? { variables: bulkVars } : {}),
        ...(headerMedia && bulkHeaderMode === "upload" ? { header_media_id: bulkHeaderId } : {}),
        ...(headerMedia && bulkHeaderMode === "url" ? { header_media_url: bulkHeaderUrl.trim() } : {}),
      });
      setNotice(bs.status === "processing"
        ? `Sending to ${bs.total} numbers — ${bs.sent_count} sent so far. This list updates automatically.`
        : `Bulk send finished: ${bs.sent_count} sent, ${bs.failed_count} failed.`);
      setShowBulk(false);
      setBulkNumbers("");
      setBulkHeaderUrl(""); setBulkHeaderId(""); setBulkHeaderName("");
      loadBulkSends();
    } catch (err) {
      const e = err as ApiError;
      setError(e.errors ? Object.values(e.errors).flat().join(". ") : e.message);
    } finally {
      setSubmitting(false);
    }
  }

  async function uploadBulkHeader(file: File) {
    const token = getToken();
    if (!token) return;
    setBulkHeaderUploading(true);
    setError(null);
    try {
      const { media_id } = await api.bulk.uploadHeader(token, file);
      setBulkHeaderId(media_id);
      setBulkHeaderName(file.name);
    } catch (err) {
      const e = err as ApiError;
      setError(e.errors ? Object.values(e.errors).flat().join(". ") : e.message);
      setBulkHeaderId(""); setBulkHeaderName("");
    } finally {
      setBulkHeaderUploading(false);
    }
  }

  async function retryBulk(uuid: string) {
    const token = getToken();
    if (!token) return;
    setRetryingId(uuid);
    setError(null);
    try {
      const { bulk_send: bs } = await api.bulk.retry(token, uuid);
      setNotice(bs.status === "processing"
        ? "Retrying the failed numbers — the list updates automatically."
        : `Retry finished: ${bs.sent_count} sent, ${bs.failed_count} still failed.`);
      if (bulkDetail?.uuid === uuid) setBulkDetail(bs);
      loadBulkSends();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setRetryingId(null);
    }
  }

  async function viewBulkDetail(uuid: string) {
    const token = getToken();
    if (!token) return;
    try {
      const res = await api.bulk.get(token, uuid);
      setBulkDetail(res.bulk_send);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  const parsedCount = parseNumbers(bulkNumbers).length;

  function downloadCampaignReport(c: CampaignItem) {
    downloadCsv(
      `campaign_${c.name.replace(/\s+/g, "_")}.csv`,
      ["Campaign", "Status", "Total Numbers", "Sent", "Delivered", "Read", "Failed", "Replied", "Delivery %", "Created"],
      [[
        c.name, c.status, c.total_recipients, c.sent_count, c.delivered_count, c.read_count,
        c.failed_count, c.replied_count, deliveryPct(c.total_recipients, c.delivered_count),
        c.created_at ? new Date(c.created_at).toLocaleString("en-IN") : "",
      ]],
    );
  }

  function downloadAllCampaigns() {
    downloadCsv(
      `campaigns_report_${new Date().toISOString().slice(0, 10)}.csv`,
      ["#", "Campaign", "Template", "Status", "Total Numbers", "Sent", "Delivered", "Read", "Failed", "Replied", "Delivery %", "Created"],
      campaigns.map((c, i) => [
        i + 1, c.name, c.template?.name ?? "", c.status, c.total_recipients, c.sent_count,
        c.delivered_count, c.read_count, c.failed_count, c.replied_count,
        deliveryPct(c.total_recipients, c.delivered_count),
        c.created_at ? new Date(c.created_at).toLocaleString("en-IN") : "",
      ]),
    );
  }

  if (!planAllows(gateUser.plan_limits, "max_campaigns")) return <UpgradePrompt title="Campaigns" feature="Campaigns" />;

  return (
    <>
      <PageHeader
        title="Campaigns"
        subtitle="Template campaigns & bulk sends"
        action={
          <div style={{ display: "flex", gap: 8 }}>
            {tab === "campaigns" ? (
              <button className="btn" style={{ width: "auto", padding: "10px 18px" }} onClick={openCreateForm}>
                + New Campaign
              </button>
            ) : (
              <button className="btn" style={{ width: "auto", padding: "10px 18px" }} onClick={openBulk}>
                {showBulk ? "Close" : "+ Bulk Send"}
              </button>
            )}
          </div>
        }
      />

      {notice && <div className="panel" style={{ background: "#e7f7ef", borderColor: "#b6e6cd", color: "#0a7d47" }}>{notice}</div>}
      {error && <div className="error">{error}</div>}

      {/* Tabs */}
      <div className="filter-tabs" style={{ marginBottom: 16 }}>
        <div className={`filter-tab ${tab === "campaigns" ? "active" : ""}`} onClick={() => { setTab("campaigns"); setShowBulk(false); setShowCreate(false); }}>
          Campaigns
        </div>
        <div className={`filter-tab ${tab === "bulk" ? "active" : ""}`} onClick={() => { setTab("bulk"); setShowBulk(false); setShowCreate(false); }}>
          Bulk Send
        </div>
      </div>

      {/* Campaign create form */}
      {showCreate && tab === "campaigns" && (
        <div className="panel">
          <h2>Create Campaign</h2>
          <form onSubmit={createCampaign}>
            <div className="field">
              <label>Campaign name</label>
              <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required placeholder="Summer sale blast" />
            </div>
            <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
              <div className="field" style={{ flex: 1, minWidth: 200 }}>
                <label>Template</label>
                <select value={form.template_id} onChange={(e) => setForm((f) => ({ ...f, template_id: e.target.value }))} required style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
                  <option value="">Select template...</option>
                  {templates.map((t) => (
                    <option key={t.id} value={t.id}>{t.name} ({t.language})</option>
                  ))}
                </select>
              </div>
              <div className="field" style={{ flex: 1, minWidth: 200 }}>
                <label>Schedule (optional)</label>
                <input type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm((f) => ({ ...f, scheduled_at: e.target.value }))} />
              </div>
            </div>
            <div className="field">
              <label>Target audience — tags (leave empty for all contacts)</label>
              <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                {tags.map((t) => (
                  <label key={t.id} style={{ display: "flex", alignItems: "center", gap: 4, fontSize: 13, cursor: "pointer" }}>
                    <input
                      type="checkbox"
                      checked={form.tags.includes(t.id)}
                      onChange={(e) => {
                        setForm((f) => ({
                          ...f,
                          tags: e.target.checked ? [...f.tags, t.id] : f.tags.filter((id) => id !== t.id),
                        }));
                      }}
                    />
                    <span style={{ background: t.color + "22", color: t.color, padding: "1px 8px", borderRadius: 999, fontWeight: 600 }}>{t.name}</span>
                  </label>
                ))}
                {tags.length === 0 && <span className="muted">No tags — campaign will target all contacts</span>}
              </div>
            </div>
            <div style={{ display: "flex", gap: 10 }}>
              <button className="btn" disabled={submitting}>{submitting ? "Creating..." : "Create Campaign"}</button>
              <button type="button" className="btn" style={{ background: "#888" }} onClick={() => setShowCreate(false)}>Cancel</button>
            </div>
          </form>
        </div>
      )}

      {/* Campaign detail */}
      {detail && tab === "campaigns" && (
        <div className="panel">
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", flexWrap: "wrap", gap: 8 }}>
            <h2>{detail.name}</h2>
            <div style={{ display: "flex", gap: 6 }}>
              {(detail.status === "draft" || detail.status === "scheduled") && (
                <button className="btn-mini" onClick={() => campaignAction(detail.id, "start")}>Start</button>
              )}
              {detail.status === "running" && (
                <button className="btn-mini" onClick={() => campaignAction(detail.id, "pause")}>Pause</button>
              )}
              {(detail.status === "draft" || detail.status === "scheduled" || detail.status === "running" || detail.status === "paused") && (
                <button className="btn-mini danger" onClick={() => campaignAction(detail.id, "cancel")}>Cancel</button>
              )}
              {detail.status === "draft" && (
                <button className="btn-mini danger" onClick={() => deleteCampaign(detail.id)}>Delete</button>
              )}
              <button className="btn-mini" onClick={() => setDetail(null)}>Close</button>
            </div>
          </div>
          <div style={{ display: "flex", gap: 16, flexWrap: "wrap", margin: "12px 0" }}>
            <div><span className="muted">Status:</span> <StatusBadge status={detail.status} /></div>
            <div><span className="muted">Template:</span> {detail.template?.name ?? "—"}</div>
            <div><span className="muted">Phone:</span> {detail.phone_number?.display_phone_number ?? "—"}</div>
            {detail.scheduled_at && <div><span className="muted">Scheduled:</span> {new Date(detail.scheduled_at).toLocaleString("en-IN")}</div>}
            {detail.started_at && <div><span className="muted">Started:</span> {new Date(detail.started_at).toLocaleString("en-IN")}</div>}
            {detail.completed_at && <div><span className="muted">Completed:</span> {new Date(detail.completed_at).toLocaleString("en-IN")}</div>}
          </div>
          <div className="grid" style={{ marginBottom: 16 }}>
            <div className="stat"><div className="label">Recipients</div><div className="value">{detail.total_recipients}</div></div>
            <div className="stat"><div className="label">Sent</div><div className="value" style={{ color: "#0a7d47" }}>{detail.sent_count}</div></div>
            <div className="stat"><div className="label">Delivered</div><div className="value" style={{ color: "#007bfc" }}>{detail.delivered_count}</div></div>
            <div className="stat"><div className="label">Read</div><div className="value" style={{ color: "#53bdeb" }}>{detail.read_count}</div></div>
            <div className="stat"><div className="label">Failed</div><div className="value" style={{ color: "#c53030" }}>{detail.failed_count}</div></div>
            <div className="stat"><div className="label">Replied</div><div className="value">{detail.replied_count}</div></div>
          </div>
          {detail.contacts && detail.contacts.length > 0 && (
            <div style={{ overflowX: "auto" }}>
              <h3 style={{ fontSize: 14, marginBottom: 8 }}>Recipients</h3>
              <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
                <thead>
                  <tr style={{ borderBottom: "2px solid var(--border)" }}>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Contact</th>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Phone</th>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {detail.contacts.map((cc) => (
                    <tr key={cc.id} style={{ borderBottom: "1px solid var(--border)" }}>
                      <td style={{ padding: "6px 4px" }}>{cc.contact.name || "—"}</td>
                      <td style={{ padding: "6px 4px", fontFamily: "monospace" }}>{cc.contact.phone}</td>
                      <td style={{ padding: "6px 4px" }}><StatusBadge status={cc.status} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* Campaigns list */}
      {tab === "campaigns" && (
        <div className="panel">
          {loading ? (
            <div className="loading-block"><span className="spinner" /><span>Loading…</span></div>
          ) : campaigns.length === 0 ? (
            <p className="muted">No campaigns yet. Click "+ New Campaign" to create one targeting your contacts.</p>
          ) : (
            <>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 10, flexWrap: "wrap", gap: 8 }}>
                <h3 style={{ margin: 0, fontSize: 15 }}>Campaign report</h3>
                <button className="btn-mini" onClick={downloadAllCampaigns}>⬇ Download report</button>
              </div>
              <div style={{ overflowX: "auto" }}>
                <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13, whiteSpace: "nowrap" }}>
                  <thead>
                    <tr style={{ borderBottom: "2px solid var(--border)", color: "#667781" }}>
                      <th style={{ textAlign: "left", padding: "8px 6px", width: 40 }}>#</th>
                      <th style={{ textAlign: "left", padding: "8px 6px" }}>Name</th>
                      <th style={{ textAlign: "left", padding: "8px 6px" }}>Status</th>
                      <th style={{ textAlign: "right", padding: "8px 6px" }}>Total&nbsp;Numbers</th>
                      <th style={{ textAlign: "right", padding: "8px 6px" }}>Sent</th>
                      <th style={{ textAlign: "right", padding: "8px 6px" }}>Delivered</th>
                      <th style={{ textAlign: "right", padding: "8px 6px" }}>Read</th>
                      <th style={{ textAlign: "right", padding: "8px 6px" }}>Failed</th>
                      <th style={{ textAlign: "right", padding: "8px 6px" }}>Replied</th>
                      <th style={{ textAlign: "right", padding: "8px 6px" }}>Delivery&nbsp;%</th>
                      <th style={{ textAlign: "left", padding: "8px 6px" }}>Created</th>
                      <th style={{ textAlign: "right", padding: "8px 6px" }}>Report</th>
                      <th style={{ textAlign: "right", padding: "8px 6px" }}></th>
                    </tr>
                  </thead>
                  <tbody>
                    {campaigns.map((c, i) => (
                      <tr key={c.id} style={{ borderBottom: "1px solid var(--border)" }}>
                        <td style={{ padding: "8px 6px", color: "var(--muted)", fontVariantNumeric: "tabular-nums" }}>{i + 1}</td>
                        <td style={{ padding: "8px 6px", fontWeight: 500, cursor: "pointer", color: "#1a7f64" }} onClick={() => viewCampaignDetail(c.id)}>{c.name}</td>
                        <td style={{ padding: "8px 6px" }}><StatusBadge status={c.status} /></td>
                        <td style={{ padding: "8px 6px", textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{c.total_recipients}</td>
                        <td style={{ padding: "8px 6px", textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{c.sent_count}</td>
                        <td style={{ padding: "8px 6px", textAlign: "right", color: "#007bfc", fontVariantNumeric: "tabular-nums" }}>{c.delivered_count}</td>
                        <td style={{ padding: "8px 6px", textAlign: "right", color: "#53bdeb", fontVariantNumeric: "tabular-nums" }}>{c.read_count}</td>
                        <td style={{ padding: "8px 6px", textAlign: "right", color: "#c53030", fontVariantNumeric: "tabular-nums" }}>{c.failed_count}</td>
                        <td style={{ padding: "8px 6px", textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{c.replied_count}</td>
                        <td style={{ padding: "8px 6px", textAlign: "right", fontWeight: 600, fontVariantNumeric: "tabular-nums" }}>{deliveryPct(c.total_recipients, c.delivered_count)}</td>
                        <td style={{ padding: "8px 6px" }}>{c.created_at ? new Date(c.created_at).toLocaleDateString("en-IN") : "—"}</td>
                        <td style={{ padding: "8px 6px", textAlign: "right" }}>
                          <button className="btn-mini" title="Download report" onClick={() => downloadCampaignReport(c)}>⬇</button>
                        </td>
                        <td style={{ padding: "8px 6px", textAlign: "right" }}>
                          <button className="btn-mini" onClick={() => viewCampaignDetail(c.id)}>View</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </div>
      )}

      {/* Bulk send form */}
      {showBulk && tab === "bulk" && (
        <div className="panel">
          <h2>Bulk Send</h2>
          <form onSubmit={sendBulk}>
            <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
              <div className="field" style={{ flex: 1, minWidth: 240 }}>
                <label>Template</label>
                {templates.length > 0 ? (
                  <select value={bulkTemplate} onChange={(e) => pickBulkTemplate(e.target.value)} required>
                    {!templates.some((t) => t.name === bulkTemplate) && <option value={bulkTemplate}>{bulkTemplate} (manual)</option>}
                    {templates.map((t) => (
                      <option key={t.id} value={t.name}>
                        {t.name} · {t.language}{t.category ? ` · ${t.category.toLowerCase()}` : ""}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input value={bulkTemplate} onChange={(e) => setBulkTemplate(e.target.value)} required placeholder="No approved templates found — type a name" />
                )}
                <span className="muted" style={{ fontSize: 12, marginTop: 4, display: "block" }}>
                  {templates.length > 0 ? `${templates.length} approved template${templates.length === 1 ? "" : "s"} available` : "Only approved templates can be sent."}
                </span>
              </div>
              <div className="field" style={{ minWidth: 180 }}>
                <label>Language</label>
                <div style={{ display: "flex", gap: 12, paddingTop: 8 }}>
                  {[{ label: "English", value: "en" }, { label: "English US", value: "en_US" }, { label: "Hindi", value: "hi" }].map((l) => (
                    <label key={l.value} style={{ display: "flex", alignItems: "center", gap: 4, fontSize: 13, cursor: "pointer" }}>
                      <input type="radio" name="lang" value={l.value} checked={bulkLang === l.value} onChange={() => setBulkLang(l.value)} />
                      {l.label}
                    </label>
                  ))}
                </div>
                <span className="muted" style={{ fontSize: 12, marginTop: 4, display: "block" }}>Auto-set from template; change if needed.</span>
              </div>
            </div>
            {templateHeaderMedia(bulkTemplate) && (() => {
              const fmt = templateHeaderMedia(bulkTemplate)!.toLowerCase();
              const accept = fmt === "image" ? "image/jpeg,image/png" : fmt === "video" ? "video/mp4" : "application/pdf";
              return (
                <div className="field">
                  <label>Header {fmt}</label>
                  <div style={{ display: "flex", gap: 14, margin: "4px 0 8px" }}>
                    {(["upload", "url"] as const).map((m) => (
                      <label key={m} style={{ display: "flex", alignItems: "center", gap: 4, fontSize: 13, cursor: "pointer" }}>
                        <input type="radio" name="headermode" checked={bulkHeaderMode === m} onChange={() => setBulkHeaderMode(m)} />
                        {m === "upload" ? "Upload file" : "Paste URL"}
                      </label>
                    ))}
                  </div>
                  {bulkHeaderMode === "upload" ? (
                    <>
                      <input type="file" accept={accept} disabled={bulkHeaderUploading}
                        onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadBulkHeader(f); }}
                        style={{ fontSize: 13 }} />
                      <div className="muted" style={{ fontSize: 12, marginTop: 4 }}>
                        {bulkHeaderUploading ? "Uploading…" : bulkHeaderId ? `✓ Ready: ${bulkHeaderName}` : `Choose the ${fmt} to show on top. Max 16MB.`}
                      </div>
                    </>
                  ) : (
                    <input value={bulkHeaderUrl} onChange={(e) => setBulkHeaderUrl(e.target.value)}
                      placeholder="https://example.com/banner.jpg"
                      style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
                  )}
                </div>
              );
            })()}
            {bulkVars.length > 0 && (
              <div className="field">
                <label>Template variables</label>
                <div className="muted" style={{ fontSize: 12, marginBottom: 8 }}>
                  This template has {bulkVars.length} variable{bulkVars.length === 1 ? "" : "s"}. The same values are sent to every number.
                </div>
                <div style={{ display: "grid", gap: 8 }}>
                  {bulkVars.map((v, i) => (
                    <div key={i} style={{ display: "flex", alignItems: "center", gap: 8 }}>
                      <span style={{ fontFamily: "monospace", fontSize: 13, color: "#54656f", minWidth: 42 }}>{`{{${i + 1}}}`}</span>
                      <input
                        value={v}
                        onChange={(e) => setBulkVars((prev) => prev.map((x, j) => (j === i ? e.target.value : x)))}
                        placeholder={`Value for {{${i + 1}}}`}
                        style={{ flex: 1, padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}
                      />
                    </div>
                  ))}
                </div>
              </div>
            )}
            <div className="field">
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
                <label style={{ margin: 0 }}>Phone numbers (one per line, or comma-separated)</label>
                <label style={{ display: "inline-flex", alignItems: "center", gap: 6, fontSize: 13, cursor: "pointer", color: "var(--primary, #1f6feb)", fontWeight: 600 }}>
                  ⬆ Import CSV
                  <input
                    type="file"
                    accept=".csv,.txt,text/csv,text/plain"
                    style={{ display: "none" }}
                    onChange={(e) => { const f = e.target.files?.[0]; if (f) importNumbersFile(f); e.target.value = ""; }}
                  />
                </label>
              </div>
              <div className="muted" style={{ fontSize: 12, margin: "2px 0 6px" }}>
                Upload a .csv file (export your Excel sheet as CSV) — numbers from any column load below automatically.
              </div>
              <textarea
                value={bulkNumbers}
                onChange={(e) => setBulkNumbers(e.target.value)}
                rows={6}
                placeholder={"919876543210\n919876543211\n919876543212"}
                style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 13, fontFamily: "monospace", resize: "vertical" }}
              />
            </div>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
              <span className="muted" style={{ fontSize: 12 }}>{parsedCount} numbers detected</span>
              <div style={{ display: "flex", gap: 10 }}>
                <button className="btn" disabled={submitting || parsedCount === 0}>{submitting ? "Sending..." : `Send to ${parsedCount}`}</button>
                <button type="button" className="btn" style={{ background: "#888" }} onClick={() => setShowBulk(false)}>Cancel</button>
              </div>
            </div>
          </form>
        </div>
      )}

      {/* Bulk send detail */}
      {bulkDetail && tab === "bulk" && (
        <div className="panel">
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
            <h2>Bulk Send Detail</h2>
            <div style={{ display: "flex", gap: 8 }}>
              {bulkDetail.failed_count > 0 && bulkDetail.status !== "processing" && (
                <button className="btn" style={{ width: "auto", padding: "6px 14px" }} disabled={retryingId === bulkDetail.uuid}
                  onClick={() => retryBulk(bulkDetail.uuid)}>
                  {retryingId === bulkDetail.uuid ? "Retrying…" : `Retry ${bulkDetail.failed_count} failed`}
                </button>
              )}
              <button className="btn-mini" onClick={() => setBulkDetail(null)}>Close</button>
            </div>
          </div>
          <div style={{ display: "flex", gap: 16, flexWrap: "wrap", margin: "12px 0" }}>
            <div><span className="muted">Template:</span> {bulkDetail.template_name}</div>
            <div><span className="muted">Language:</span> {bulkDetail.language}</div>
            <div><span className="muted">Status:</span> <StatusBadge status={bulkDetail.status} /></div>
            <div><span className="muted">Total:</span> {bulkDetail.total}</div>
            <div><span className="muted">Sent:</span> <span style={{ color: "#0a7d47" }}>{bulkDetail.sent_count}</span></div>
            <div><span className="muted">Failed:</span> <span style={{ color: "#c53030" }}>{bulkDetail.failed_count}</span></div>
          </div>
          {bulkDetail.recipients.length > 0 && (
            <div style={{ overflowX: "auto", maxHeight: 300, overflow: "auto" }}>
              <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 12 }}>
                <thead>
                  <tr style={{ borderBottom: "2px solid var(--border)" }}>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Phone</th>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Status</th>
                    <th style={{ textAlign: "left", padding: "6px 4px" }}>Error</th>
                  </tr>
                </thead>
                <tbody>
                  {bulkDetail.recipients.map((r) => (
                    <tr key={r.id} style={{ borderBottom: "1px solid var(--border)" }}>
                      <td style={{ padding: "6px 4px", fontFamily: "monospace" }}>{r.phone}</td>
                      <td style={{ padding: "6px 4px" }}><StatusBadge status={r.status} /></td>
                      <td style={{ padding: "6px 4px", color: "var(--danger)", fontSize: 11 }}>{r.error_message || "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* Bulk sends list */}
      {tab === "bulk" && (
        <div className="panel">
          {loading ? (
            <div className="loading-block"><span className="spinner" /><span>Loading…</span></div>
          ) : sends.length === 0 ? (
            <p className="muted">No bulk sends yet.</p>
          ) : (
            <div style={{ overflowX: "auto" }}>
              <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
                <thead>
                  <tr style={{ borderBottom: "2px solid var(--border)" }}>
                    <th style={{ textAlign: "left", padding: "8px 4px", width: 48 }}>#</th>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Template</th>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Language</th>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Status</th>
                    <th style={{ textAlign: "right", padding: "8px 4px" }}>Total</th>
                    <th style={{ textAlign: "right", padding: "8px 4px" }}>Sent</th>
                    <th style={{ textAlign: "right", padding: "8px 4px" }}>Failed</th>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Date</th>
                    <th style={{ textAlign: "right", padding: "8px 4px" }}></th>
                  </tr>
                </thead>
                <tbody>
                  {sends.map((s, i) => (
                    <tr key={s.uuid} style={{ borderBottom: "1px solid var(--border)" }}>
                      <td style={{ padding: "8px 4px", color: "var(--muted)", fontVariantNumeric: "tabular-nums" }}>{i + 1}</td>
                      <td style={{ padding: "8px 4px", fontFamily: "monospace" }}>{s.template_name}</td>
                      <td style={{ padding: "8px 4px" }}>{s.language}</td>
                      <td style={{ padding: "8px 4px" }}><StatusBadge status={s.status} /></td>
                      <td style={{ padding: "8px 4px", textAlign: "right" }}>{s.total}</td>
                      <td style={{ padding: "8px 4px", textAlign: "right", color: "#0a7d47" }}>{s.sent_count}</td>
                      <td style={{ padding: "8px 4px", textAlign: "right", color: "#c53030" }}>{s.failed_count}</td>
                      <td style={{ padding: "8px 4px" }}>{s.created_at ? new Date(s.created_at).toLocaleDateString("en-IN") : "—"}</td>
                      <td style={{ padding: "8px 4px", textAlign: "right", whiteSpace: "nowrap" }}>
                        {s.failed_count > 0 && s.status !== "processing" && (
                          <button className="btn-mini" style={{ marginRight: 6, color: "#c53030", borderColor: "#f3c6c6" }}
                            disabled={retryingId === s.uuid} onClick={() => retryBulk(s.uuid)}>
                            {retryingId === s.uuid ? "…" : "Retry"}
                          </button>
                        )}
                        <button className="btn-mini" onClick={() => viewBulkDetail(s.uuid)}>View</button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </>
  );
}
