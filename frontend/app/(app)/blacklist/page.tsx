"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, BlacklistItem } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

const EMPTY = { phone: "", name: "", reason: "" };

export default function BlacklistPage() {
  const [entries, setEntries] = useState<BlacklistItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  const [showAdd, setShowAdd] = useState(false);
  const [form, setForm] = useState({ ...EMPTY });
  const [submitting, setSubmitting] = useState(false);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const res = await api.blacklist.list(token, { search: search || undefined });
      setEntries(res.entries);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, [search]);

  useEffect(() => { load(); }, [load]);

  function flash(msg: string) {
    setNotice(msg);
    setTimeout(() => setNotice(null), 3500);
  }

  async function add(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSubmitting(true);
    setError(null);
    try {
      await api.blacklist.add(token, {
        phone: form.phone,
        name: form.name || undefined,
        reason: form.reason || undefined,
      });
      setShowAdd(false);
      setForm({ ...EMPTY });
      flash("Number added to the black list.");
      await load();
    } catch (err) {
      const e2 = err as ApiError;
      const fieldErrors = e2.errors ? Object.values(e2.errors).flat().join(". ") : "";
      setError(fieldErrors || e2.message);
    } finally {
      setSubmitting(false);
    }
  }

  async function remove(entry: BlacklistItem) {
    const token = getToken();
    if (!token) return;
    if (!confirm(`Remove ${entry.phone} from the black list?`)) return;
    try {
      await api.blacklist.remove(token, entry.id);
      setEntries((prev) => prev.filter((x) => x.id !== entry.id));
      flash("Number removed.");
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  return (
    <div>
      <PageHeader
        title="User Black List"
        subtitle="Numbers here are automatically excluded from every campaign and bulk send."
        action={<button className="btn" style={{ width: "auto", padding: "10px 18px" }} onClick={() => setShowAdd(true)}>+ Block a number</button>}
      />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      <div style={{ display: "flex", gap: 10, marginBottom: 16, flexWrap: "wrap" }}>
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by number or name…"
          style={{ flex: 1, minWidth: 200, padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
      </div>

      {loading ? (
        <LoadingBlock label="Loading black list…" />
      ) : entries.length === 0 ? (
        <div className="panel" style={{ textAlign: "center", padding: 40 }}>
          <div style={{ fontSize: 40 }}>🚫</div>
          <h3 style={{ margin: "12px 0 4px" }}>No blocked numbers</h3>
          <p className="muted" style={{ marginBottom: 16 }}>Block numbers that should never receive your messages.</p>
          <button className="btn" style={{ width: "auto", padding: "10px 18px" }} onClick={() => setShowAdd(true)}>Block a number</button>
        </div>
      ) : (
        <div className="panel" style={{ padding: 0, overflow: "hidden" }}>
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", fontSize: 12, color: "#667781", background: "#f8fafb", borderBottom: "1px solid #eef1f2" }}>
                <th style={{ padding: "12px 14px", width: 56 }}>Sr. No</th>
                <th style={{ padding: "12px 14px" }}>Number</th>
                <th style={{ padding: "12px 14px" }}>Name</th>
                <th style={{ padding: "12px 14px" }}>Reason</th>
                <th style={{ padding: "12px 14px" }}>Source</th>
                <th style={{ padding: "12px 14px", textAlign: "right" }}>Action</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((e, i) => (
                <tr key={e.id} style={{ borderBottom: "1px solid #f4f6f7" }}>
                  <td style={{ padding: "12px 14px", color: "#667781", fontVariantNumeric: "tabular-nums" }}>{i + 1}</td>
                  <td style={{ padding: "12px 14px", fontWeight: 600, fontFamily: "monospace" }}>{e.phone}</td>
                  <td style={{ padding: "12px 14px" }}>{e.name ?? "—"}</td>
                  <td style={{ padding: "12px 14px" }}>{e.reason ?? "—"}</td>
                  <td style={{ padding: "12px 14px" }}>
                    <span style={{ background: "#eef1f2", color: "#54656f", padding: "2px 9px", borderRadius: 999, fontSize: 11, fontWeight: 600, textTransform: "capitalize" }}>
                      {e.source === "opt_out" ? "Opted out" : e.source}
                    </span>
                  </td>
                  <td style={{ padding: "12px 14px", textAlign: "right" }}>
                    <button className="btn-mini" style={{ color: "#0a7d47" }} onClick={() => remove(e)}>Unblock</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showAdd && (
        <div className="msg-info-overlay" onClick={() => setShowAdd(false)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 440 }}>
            <h2 style={{ marginTop: 0 }}>Block a number</h2>
            <p className="muted" style={{ fontSize: 13, marginTop: 0 }}>This number will be skipped in all outbound sends.</p>
            <form onSubmit={add}>
              <div className="field">
                <label>Phone number</label>
                <input value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} required placeholder="+91 98765 43210" />
              </div>
              <div className="field">
                <label>Name <span className="muted">(optional)</span></label>
                <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="Contact name" />
              </div>
              <div className="field">
                <label>Reason <span className="muted">(optional)</span></label>
                <input value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} placeholder="e.g. Requested opt-out" />
              </div>
              <div style={{ display: "flex", gap: 8, marginTop: 10 }}>
                <button className="btn" disabled={submitting}>{submitting ? "Blocking…" : "Block number"}</button>
                <button type="button" className="btn-mini" onClick={() => setShowAdd(false)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
