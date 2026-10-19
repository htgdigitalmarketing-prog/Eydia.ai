"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, SupportTicket } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";

const PRIORITIES = ["low", "medium", "high", "urgent"] as const;
const CATEGORIES = ["billing", "technical", "account", "other"] as const;
const STATUSES = ["open", "in_progress", "resolved", "closed"] as const;

function statusColor(s: string) {
  if (s === "open") return { bg: "#fff3cd", fg: "#856404" };
  if (s === "in_progress") return { bg: "#cce5ff", fg: "#004085" };
  if (s === "resolved") return { bg: "#d4edda", fg: "#155724" };
  if (s === "closed") return { bg: "#eef1f2", fg: "#54656f" };
  return { bg: "#eef1f2", fg: "#54656f" };
}

function priorityColor(p: string) {
  if (p === "urgent") return { bg: "#fde8e8", fg: "#c62828" };
  if (p === "high") return { bg: "#fff1e0", fg: "#c2620a" };
  if (p === "medium") return { bg: "#cce5ff", fg: "#004085" };
  return { bg: "#eef1f2", fg: "#54656f" };
}

function timeAgo(iso: string | null): string {
  if (!iso) return "—";
  const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
  if (mins < 1) return "Just now";
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

export default function SupportPage() {
  const user = useUser();
  const isSuperAdmin = user.is_super_admin;

  const [tickets, setTickets] = useState<SupportTicket[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [filterStatus, setFilterStatus] = useState("");
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });

  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ subject: "", description: "", priority: "medium", category: "" });
  const [submitting, setSubmitting] = useState(false);

  const [detail, setDetail] = useState<SupportTicket | null>(null);
  const [replyText, setReplyText] = useState("");
  const [replyStatus, setReplyStatus] = useState("in_progress");
  const [replying, setReplying] = useState(false);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const params = { status: filterStatus || undefined, search: search || undefined, page };
      const res = isSuperAdmin
        ? await api.support.adminList(token, params)
        : await api.support.list(token, params);
      setTickets(res.tickets);
      setMeta(res.meta);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, [isSuperAdmin, filterStatus, search, page]);

  useEffect(() => { load(); }, [load]);

  async function createTicket(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSubmitting(true);
    setError(null);
    try {
      const res = await api.support.create(token, {
        subject: form.subject,
        description: form.description,
        priority: form.priority,
        category: form.category || undefined,
      });
      setNotice(`Ticket created! Your Ticket ID: ${res.ticket.ticket_id}`);
      setShowForm(false);
      setForm({ subject: "", description: "", priority: "medium", category: "" });
      await load();
    } catch (err) {
      const e2 = err as ApiError;
      setError(e2.errors ? Object.values(e2.errors).flat().join(". ") : e2.message);
    } finally {
      setSubmitting(false);
    }
  }

  async function submitReply() {
    const token = getToken();
    if (!token || !detail) return;
    setReplying(true);
    setError(null);
    try {
      const res = await api.support.reply(token, detail.id, {
        admin_reply: replyText,
        status: replyStatus,
      });
      setDetail(res.ticket);
      setReplyText("");
      setNotice("Reply sent.");
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setReplying(false);
    }
  }

  async function viewTicket(id: string) {
    const token = getToken();
    if (!token) return;
    try {
      const res = await api.support.get(token, id);
      setDetail(res.ticket);
      if (res.ticket.status) setReplyStatus(res.ticket.status);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  return (
    <>
      <PageHeader
        title={isSuperAdmin ? "Support Tickets (Admin)" : "Support"}
        subtitle={isSuperAdmin ? `${meta.total} tickets across all companies` : "Submit a query and get help from our team"}
        action={
          !isSuperAdmin ? (
            <button className="btn" style={{ width: "auto", padding: "10px 18px" }} onClick={() => { setShowForm(true); setDetail(null); setError(null); }}>
              + New Ticket
            </button>
          ) : undefined
        }
      />

      {notice && <div className="panel" style={{ background: "#e7f7ef", borderColor: "#b6e6cd", color: "#0a7d47", marginBottom: 12 }}>{notice}</div>}
      {error && <div className="error" style={{ marginBottom: 12 }}>{error}</div>}

      {/* Filters */}
      <div style={{ display: "flex", gap: 10, marginBottom: 16, flexWrap: "wrap" }}>
        <input
          value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          placeholder="Search by ticket ID or subject..."
          style={{ flex: 1, minWidth: 200, padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}
        />
        <select value={filterStatus} onChange={(e) => { setFilterStatus(e.target.value); setPage(1); }}
          style={{ padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
          <option value="">All Status</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s.replace("_", " ").replace(/\b\w/g, (c) => c.toUpperCase())}</option>)}
        </select>
      </div>

      {/* Create ticket form */}
      {showForm && !isSuperAdmin && (
        <div className="panel" style={{ marginBottom: 16 }}>
          <h2 style={{ marginTop: 0 }}>Create Support Ticket</h2>
          <form onSubmit={createTicket}>
            <div className="field">
              <label>Subject</label>
              <input value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} required placeholder="Brief description of your issue" />
            </div>
            <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
              <div className="field" style={{ flex: 1, minWidth: 150 }}>
                <label>Priority</label>
                <select value={form.priority} onChange={(e) => setForm((f) => ({ ...f, priority: e.target.value }))}
                  style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
                  {PRIORITIES.map((p) => <option key={p} value={p}>{p.charAt(0).toUpperCase() + p.slice(1)}</option>)}
                </select>
              </div>
              <div className="field" style={{ flex: 1, minWidth: 150 }}>
                <label>Category</label>
                <select value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}
                  style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
                  <option value="">Select category</option>
                  {CATEGORIES.map((c) => <option key={c} value={c}>{c.charAt(0).toUpperCase() + c.slice(1)}</option>)}
                </select>
              </div>
            </div>
            <div className="field">
              <label>Description</label>
              <textarea value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} required
                placeholder="Describe your issue in detail..."
                rows={5} style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, resize: "vertical" }} />
            </div>
            <div style={{ display: "flex", gap: 10 }}>
              <button className="btn" disabled={submitting}>{submitting ? "Submitting..." : "Submit Ticket"}</button>
              <button type="button" className="btn" style={{ background: "#888" }} onClick={() => setShowForm(false)}>Cancel</button>
            </div>
          </form>
        </div>
      )}

      {/* Ticket detail */}
      {detail && (
        <div className="panel" style={{ marginBottom: 16 }}>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", flexWrap: "wrap", gap: 10 }}>
            <div>
              <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 4 }}>
                <span style={{ fontFamily: "monospace", fontSize: 13, color: "var(--muted)" }}>{detail.ticket_id}</span>
                <span style={{ ...statusColor(detail.status), padding: "2px 10px", borderRadius: 999, fontSize: 12, fontWeight: 600, background: statusColor(detail.status).bg, color: statusColor(detail.status).fg }}>
                  {detail.status.replace("_", " ")}
                </span>
                <span style={{ ...priorityColor(detail.priority), padding: "2px 10px", borderRadius: 999, fontSize: 12, fontWeight: 600, background: priorityColor(detail.priority).bg, color: priorityColor(detail.priority).fg }}>
                  {detail.priority}
                </span>
              </div>
              <h2 style={{ margin: 0 }}>{detail.subject}</h2>
              {isSuperAdmin && detail.tenant_name && (
                <div style={{ fontSize: 12, color: "var(--muted)", marginTop: 2 }}>Company: {detail.tenant_name} | {detail.user_name} ({detail.user_email})</div>
              )}
            </div>
            <button className="btn-mini" onClick={() => setDetail(null)}>Close</button>
          </div>

          <div style={{ margin: "16px 0", padding: "14px 16px", background: "#f8fafb", borderRadius: 10, border: "1px solid var(--border)" }}>
            <div style={{ fontSize: 11, color: "var(--muted)", textTransform: "uppercase", fontWeight: 700, marginBottom: 6 }}>Description</div>
            <div style={{ fontSize: 14, whiteSpace: "pre-wrap" }}>{detail.description}</div>
            <div style={{ fontSize: 11, color: "var(--muted)", marginTop: 8 }}>Submitted {timeAgo(detail.created_at)}</div>
          </div>

          {detail.admin_reply && (
            <div style={{ margin: "12px 0", padding: "14px 16px", background: "#e7f7ef", borderRadius: 10, border: "1px solid #b6e6cd" }}>
              <div style={{ fontSize: 11, color: "#0a7d47", textTransform: "uppercase", fontWeight: 700, marginBottom: 6 }}>Admin Reply</div>
              <div style={{ fontSize: 14, whiteSpace: "pre-wrap" }}>{detail.admin_reply}</div>
              <div style={{ fontSize: 11, color: "var(--muted)", marginTop: 8 }}>Replied {timeAgo(detail.replied_at)}</div>
            </div>
          )}

          {/* Admin reply form */}
          {isSuperAdmin && (
            <div style={{ marginTop: 12, padding: "14px 16px", background: "#fff", borderRadius: 10, border: "1px solid var(--border)" }}>
              <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8 }}>Reply to this ticket</div>
              <textarea value={replyText} onChange={(e) => setReplyText(e.target.value)}
                placeholder="Type your reply..."
                rows={4} style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, resize: "vertical", marginBottom: 8 }} />
              <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
                <select value={replyStatus} onChange={(e) => setReplyStatus(e.target.value)}
                  style={{ padding: "8px 12px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }}>
                  {STATUSES.map((s) => <option key={s} value={s}>{s.replace("_", " ").replace(/\b\w/g, (c) => c.toUpperCase())}</option>)}
                </select>
                <button className="btn" style={{ width: "auto", padding: "8px 20px" }} disabled={replying || !replyText.trim()} onClick={submitReply}>
                  {replying ? "Sending..." : "Send Reply"}
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Tickets table */}
      <div className="panel">
        {loading ? (
          <div className="loading-block"><span className="spinner" /><span>Loading...</span></div>
        ) : tickets.length === 0 ? (
          <div style={{ textAlign: "center", padding: 40 }}>
            <div style={{ fontSize: 40 }}>🎫</div>
            <h3 style={{ margin: "12px 0 4px" }}>No tickets {filterStatus ? `with status "${filterStatus}"` : "yet"}</h3>
            <p className="muted">{isSuperAdmin ? "No support tickets from any company." : "Click \"+ New Ticket\" to submit a support request."}</p>
          </div>
        ) : (
          <>
            <div style={{ overflowX: "auto" }}>
              <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
                <thead>
                  <tr style={{ borderBottom: "2px solid var(--border)" }}>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Ticket ID</th>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Subject</th>
                    {isSuperAdmin && <th style={{ textAlign: "left", padding: "8px 4px" }}>Company</th>}
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Priority</th>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Status</th>
                    <th style={{ textAlign: "left", padding: "8px 4px" }}>Created</th>
                    <th style={{ textAlign: "right", padding: "8px 4px" }}></th>
                  </tr>
                </thead>
                <tbody>
                  {tickets.map((t) => {
                    const sc = statusColor(t.status);
                    const pc = priorityColor(t.priority);
                    return (
                      <tr key={t.id} style={{ borderBottom: "1px solid var(--border)" }}>
                        <td style={{ padding: "10px 4px", fontFamily: "monospace", fontWeight: 600 }}>{t.ticket_id}</td>
                        <td style={{ padding: "10px 4px", maxWidth: 300 }}>
                          <div style={{ fontWeight: 500 }}>{t.subject}</div>
                          <div style={{ fontSize: 11, color: "var(--muted)", marginTop: 2 }}>{t.user_name}</div>
                        </td>
                        {isSuperAdmin && <td style={{ padding: "10px 4px" }}>{t.tenant_name ?? "—"}</td>}
                        <td style={{ padding: "10px 4px" }}>
                          <span style={{ background: pc.bg, color: pc.fg, padding: "2px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600 }}>{t.priority}</span>
                        </td>
                        <td style={{ padding: "10px 4px" }}>
                          <span style={{ background: sc.bg, color: sc.fg, padding: "2px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600 }}>{t.status.replace("_", " ")}</span>
                        </td>
                        <td style={{ padding: "10px 4px", color: "var(--muted)", whiteSpace: "nowrap" }}>{timeAgo(t.created_at)}</td>
                        <td style={{ padding: "10px 4px", textAlign: "right" }}>
                          <button className="btn-mini" onClick={() => viewTicket(t.id)}>View</button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {meta.last_page > 1 && (
              <div style={{ display: "flex", justifyContent: "center", gap: 8, marginTop: 16 }}>
                <button className="btn-mini" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Prev</button>
                <span className="muted" style={{ alignSelf: "center", fontSize: 13 }}>Page {meta.current_page} of {meta.last_page}</span>
                <button className="btn-mini" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</button>
              </div>
            )}
          </>
        )}
      </div>
    </>
  );
}
