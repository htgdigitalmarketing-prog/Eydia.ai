"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, ContactItem } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

function priority(score: number | undefined): { label: string; bg: string; fg: string } {
  const n = score ?? 50;
  if (n >= 80) return { label: "High", bg: "#fde8e8", fg: "#c62828" };
  if (n >= 50) return { label: "Medium", bg: "#fff1e0", fg: "#c2620a" };
  return { label: "Low", bg: "#eef1f2", fg: "#54656f" };
}

function timeAgo(iso: string | null | undefined): string {
  if (!iso) return "—";
  const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
  if (mins < 1) return "Just now";
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

export default function HotListPage() {
  const [contacts, setContacts] = useState<ContactItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  const load = useCallback(async (silent = false) => {
    const token = getToken();
    if (!token) return;
    if (!silent) setLoading(true);
    try {
      const res = await api.contacts.list(token, { hot: true, search: search || undefined });
      setContacts(res.contacts);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, [search]);

  useEffect(() => { load(); }, [load]);

  // Pick up new hot leads without a manual refresh.
  useEffect(() => {
    const t = setInterval(() => load(true), 30000);
    return () => clearInterval(t);
  }, [load]);

  return (
    <div>
      <PageHeader
        title="Hot List"
        subtitle="The AI picks customers who showed interest and ranks them. Highest priority is on top - contact them first."
      />

      {error && <div className="error">{error}</div>}

      <div style={{ display: "flex", gap: 10, marginBottom: 16, flexWrap: "wrap" }}>
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by name, phone, email…"
          style={{ flex: 1, minWidth: 200, padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }} />
      </div>

      {loading ? (
        <LoadingBlock label="Loading hot list…" />
      ) : contacts.length === 0 ? (
        <div className="panel" style={{ textAlign: "center", padding: 40 }}>
          <div style={{ fontSize: 40 }}>🔥</div>
          <h3 style={{ margin: "12px 0 4px" }}>No hot leads yet</h3>
          <p className="muted">When a customer asks about price, a demo, or says they are interested, they will show up here.</p>
          
        </div>
      ) : (
        <div className="panel" style={{ padding: 0, overflow: "hidden" }}>
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", fontSize: 12, color: "#667781", background: "#f8fafb", borderBottom: "1px solid #eef1f2" }}>
                <th style={{ padding: "12px 14px", width: 56 }}>#</th>
                <th style={{ padding: "12px 14px" }}>Priority</th>
                <th style={{ padding: "12px 14px" }}>Name</th>
                <th style={{ padding: "12px 14px" }}>Phone</th>
                <th style={{ padding: "12px 14px" }}>Company</th>
                <th style={{ padding: "12px 14px" }}>Why hot</th>
                <th style={{ padding: "12px 14px" }}>Since</th>
                <th style={{ padding: "12px 14px", textAlign: "right" }}>Action</th>
              </tr>
            </thead>
            <tbody>
              {contacts.map((c, i) => (
                <tr key={c.id} style={{ borderBottom: "1px solid #f4f6f7" }}>
                  <td style={{ padding: "12px 14px", color: "#667781", fontVariantNumeric: "tabular-nums" }}>{i + 1}</td>
                  <td style={{ padding: "12px 14px", whiteSpace: "nowrap" }}>
                    {(() => {
                      const p = priority(c.hot_score);
                      return (
                        <span title={`AI priority score ${c.hot_score ?? 50}/100`}
                          style={{ background: p.bg, color: p.fg, padding: "2px 10px", borderRadius: 999, fontSize: 12, fontWeight: 700 }}>
                          {p.label} · {c.hot_score ?? 50}
                        </span>
                      );
                    })()}
                  </td>
                  <td style={{ padding: "12px 14px", fontWeight: 600 }}>🔥 {c.name || "—"}</td>
                  <td style={{ padding: "12px 14px", fontFamily: "monospace" }}>{c.phone}</td>
                  <td style={{ padding: "12px 14px" }}>{c.company ?? "—"}</td>
                  <td style={{ padding: "12px 14px", maxWidth: 340, fontSize: 13 }}>
                    {c.hot_reason ?? "—"}
                  </td>
                  <td style={{ padding: "12px 14px", color: "#667781", whiteSpace: "nowrap" }}>{timeAgo(c.hot_at)}</td>
                  <td style={{ padding: "12px 14px", textAlign: "right", whiteSpace: "nowrap" }}>
                    <a className="btn-mini" style={{ textDecoration: "none" }}
                      href={`/inbox?contact=${c.wa_id || c.phone}`}>WhatsApp</a>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
