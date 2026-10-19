"use client";

import { useCallback, useEffect, useState } from "react";
import { useUser } from "@/lib/user-context";
import { AccessDenied, hasFeature } from "@/components/PlanGate";
import { PageHeader } from "@/components/PageHeader";
import {
  api, ApiError, InvoicingListItem, InvoicingStats, InvoicingCustomer,
  InvoicingService, InvoicingExpense, InvoicingItemInput,
} from "@/lib/api";
import { getToken } from "@/lib/auth";

const inr = (n: number) => "₹" + n.toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const STATUS_COLOR: Record<string, string> = { DRAFT: "#667781", SENT: "#1877F2", PAID: "#0a7d47", OVERDUE: "#c53030" };

export default function InvoicingPage() {
  const user = useUser();
  if (!hasFeature(user, "invoicing")) return <AccessDenied dashboard="Invoicing" />;
  return <InvoicingDashboard />;
}

function InvoicingDashboard() {
  const [tab, setTab] = useState<"invoices" | "customers" | "services" | "expenses">("invoices");
  const [error, setError] = useState<string | null>(null);

  return (
    <div>
      <PageHeader title="Invoicing" subtitle="Bill your customers — GST invoices, services, customers and expenses." />
      {error && <div className="error" style={{ marginBottom: 12 }}>{error}</div>}

      <div className="panel" style={{ padding: 6, marginBottom: 16, display: "inline-flex", gap: 4, flexWrap: "wrap" }}>
        {(["invoices", "customers", "services", "expenses"] as const).map((t) => (
          <button key={t} className="btn-mini"
            style={{ background: tab === t ? "var(--accent)" : "transparent", color: tab === t ? "#fff" : undefined, border: "none", textTransform: "capitalize" }}
            onClick={() => setTab(t)}>{t}</button>
        ))}
      </div>

      {tab === "invoices" && <InvoicesTab onError={setError} />}
      {tab === "customers" && <CustomersTab onError={setError} />}
      {tab === "services" && <ServicesTab onError={setError} />}
      {tab === "expenses" && <ExpensesTab onError={setError} />}
    </div>
  );
}

function InvoicesTab({ onError }: { onError: (m: string) => void }) {
  const [invoices, setInvoices] = useState<InvoicingListItem[]>([]);
  const [stats, setStats] = useState<InvoicingStats | null>(null);
  const [showNew, setShowNew] = useState(false);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    try { const r = await api.invoicing.invoices(token); setInvoices(r.invoices); setStats(r.stats); }
    catch (e) { onError((e as ApiError).message); }
  }, [onError]);
  useEffect(() => { load(); }, [load]);

  async function setStatus(id: string, action: "sent" | "paid") {
    const token = getToken();
    if (!token) return;
    try { action === "sent" ? await api.invoicing.markSent(token, id) : await api.invoicing.markPaid(token, id); load(); }
    catch (e) { onError((e as ApiError).message); }
  }
  async function remove(id: string) {
    const token = getToken();
    if (!token || !confirm("Delete this draft invoice?")) return;
    try { await api.invoicing.removeInvoice(token, id); load(); } catch (e) { onError((e as ApiError).message); }
  }

  return (
    <div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(190px,1fr))", gap: 12, marginBottom: 16 }}>
        <Stat label="Outstanding" value={stats ? inr(stats.outstanding) : "—"} icon="📬" bg="#fff4e6" />
        <Stat label="Paid this month" value={stats ? inr(stats.paid_this_month) : "—"} icon="💰" bg="#e9faf1" />
        <Stat label="Drafts" value={stats?.drafts ?? 0} icon="📝" bg="#eef3ff" />
      </div>

      <div style={{ display: "flex", justifyContent: "flex-end", marginBottom: 12 }}>
        <button className="btn" style={{ width: "auto", padding: "9px 18px" }} onClick={() => setShowNew(true)}>+ New invoice</button>
      </div>

      <div className="panel" style={{ padding: 0, overflowX: "auto" }}>
        <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13.5 }}>
          <thead>
            <tr style={{ textAlign: "left", background: "var(--bg)" }}>
              {["Number", "Customer", "Issued", "Due", "Status", "Total", ""].map((h) => (
                <th key={h} style={{ padding: "10px 12px", fontSize: 12, color: "var(--muted)" }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {invoices.map((i) => (
              <tr key={i.id} style={{ borderTop: "1px solid var(--border)" }}>
                <td style={{ padding: "10px 12px", fontWeight: 600 }}>{i.invoice_number}</td>
                <td style={{ padding: "10px 12px" }}>{i.customer_name ?? "—"}</td>
                <td style={{ padding: "10px 12px" }} className="muted">{i.issue_date ?? "—"}</td>
                <td style={{ padding: "10px 12px" }} className="muted">{i.due_date ?? "—"}</td>
                <td style={{ padding: "10px 12px" }}><span style={{ background: (STATUS_COLOR[i.status] ?? "#667781") + "1a", color: STATUS_COLOR[i.status] ?? "#667781", padding: "2px 9px", borderRadius: 999, fontSize: 11, fontWeight: 700 }}>{i.status}</span></td>
                <td style={{ padding: "10px 12px", fontWeight: 700 }}>{inr(i.total)}</td>
                <td style={{ padding: "10px 12px", whiteSpace: "nowrap" }}>
                  {i.status === "DRAFT" && <button className="btn-mini" onClick={() => setStatus(i.id, "sent")}>Send</button>}
                  {(i.status === "SENT" || i.status === "OVERDUE") && <button className="btn-mini" onClick={() => setStatus(i.id, "paid")}>Mark paid</button>}
                  {i.status === "DRAFT" && <button className="btn-mini" style={{ marginLeft: 4 }} onClick={() => remove(i.id)}>✕</button>}
                </td>
              </tr>
            ))}
            {invoices.length === 0 && <tr><td colSpan={7} style={{ padding: 28, textAlign: "center" }}><span className="muted">No invoices yet.</span></td></tr>}
          </tbody>
        </table>
      </div>

      {showNew && <NewInvoiceModal onClose={() => setShowNew(false)} onCreated={() => { setShowNew(false); load(); }} onError={onError} />}
    </div>
  );
}

function NewInvoiceModal({ onClose, onCreated, onError }: { onClose: () => void; onCreated: () => void; onError: (m: string) => void }) {
  const [customers, setCustomers] = useState<InvoicingCustomer[]>([]);
  const [services, setServices] = useState<InvoicingService[]>([]);
  const [customerId, setCustomerId] = useState("");
  const [issueDate, setIssueDate] = useState(new Date().toISOString().slice(0, 10));
  const [dueDate, setDueDate] = useState("");
  const [notes, setNotes] = useState("");
  const [items, setItems] = useState<InvoicingItemInput[]>([{ description: "", quantity: 1, unit_price: 0, gst_percent: 18 }]);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => {
    const token = getToken();
    if (!token) return;
    api.invoicing.customers(token).then((r) => setCustomers(r.customers)).catch(() => {});
    api.invoicing.services(token).then((r) => setServices(r.services)).catch(() => {});
  }, []);

  const total = items.reduce((sum, it) => {
    const base = (Number(it.quantity) || 0) * (Number(it.unit_price) || 0);
    return sum + base + base * (Number(it.gst_percent) || 0) / 100;
  }, 0);

  function setItem(idx: number, patch: Partial<InvoicingItemInput>) {
    setItems((arr) => arr.map((it, i) => i === idx ? { ...it, ...patch } : it));
  }
  function applyService(idx: number, serviceId: string) {
    const svc = services.find((s) => s.id === serviceId);
    if (svc) setItem(idx, { service_id: svc.id, description: svc.name, unit_price: svc.price, gst_percent: svc.gst_percent });
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token || !customerId) { setErr("Pick a customer first."); return; }
    setSaving(true); setErr(null);
    try {
      await api.invoicing.createInvoice(token, {
        customer_id: customerId, issue_date: issueDate, due_date: dueDate || undefined, notes: notes || undefined,
        items: items.filter((it) => it.description.trim()),
      });
      onCreated();
    } catch (e2) { const a = e2 as ApiError; setErr(a.errors ? Object.values(a.errors).flat().join(". ") : a.message); } finally { setSaving(false); }
  }

  return (
    <div className="msg-info-overlay" onClick={onClose} style={{ zIndex: 200 }}>
      <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 680 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <h2 style={{ margin: 0 }}>New invoice</h2>
          <button className="btn-mini" onClick={onClose}>Close</button>
        </div>
        {customers.length === 0 && <div className="muted" style={{ fontSize: 13, marginTop: 6 }}>Add a customer first (Customers tab).</div>}
        {err && <div className="error">{err}</div>}
        <form onSubmit={submit}>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
            <div className="field" style={{ flex: 1, minWidth: 180 }}>
              <label>Customer</label>
              <select value={customerId} onChange={(e) => setCustomerId(e.target.value)} required
                style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
                <option value="">Select…</option>
                {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
            <div className="field" style={{ flex: 1, minWidth: 130 }}><label>Issue date</label><input type="date" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} required /></div>
            <div className="field" style={{ flex: 1, minWidth: 130 }}><label>Due date</label><input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} /></div>
          </div>

          <div style={{ fontSize: 12, fontWeight: 700, margin: "6px 0" }}>Line items</div>
          {items.map((it, idx) => (
            <div key={idx} style={{ display: "flex", gap: 8, marginBottom: 8, flexWrap: "wrap", alignItems: "center" }}>
              {services.length > 0 && (
                <select value={it.service_id ?? ""} onChange={(e) => applyService(idx, e.target.value)}
                  style={{ padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13, minWidth: 110 }}>
                  <option value="">Custom</option>
                  {services.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
              )}
              <input placeholder="Description" value={it.description} onChange={(e) => setItem(idx, { description: e.target.value })} required
                style={{ flex: 2, minWidth: 140, padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }} />
              <input type="number" placeholder="Qty" value={it.quantity} onChange={(e) => setItem(idx, { quantity: Number(e.target.value) })}
                style={{ width: 64, padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }} />
              <input type="number" placeholder="Price" value={it.unit_price} onChange={(e) => setItem(idx, { unit_price: Number(e.target.value) })}
                style={{ width: 90, padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }} />
              <input type="number" placeholder="GST%" value={it.gst_percent} onChange={(e) => setItem(idx, { gst_percent: Number(e.target.value) })}
                style={{ width: 70, padding: "8px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13 }} />
              {items.length > 1 && <button type="button" className="btn-mini" onClick={() => setItems((a) => a.filter((_, i) => i !== idx))}>✕</button>}
            </div>
          ))}
          <button type="button" className="btn-mini" onClick={() => setItems((a) => [...a, { description: "", quantity: 1, unit_price: 0, gst_percent: 18 }])}>+ Add line</button>

          <div className="field" style={{ marginTop: 10 }}>
            <label>Notes</label>
            <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2}
              style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, fontFamily: "inherit", resize: "vertical" }} />
          </div>

          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 8 }}>
            <strong style={{ fontSize: 16 }}>Total (incl. GST): {inr(total)}</strong>
            <button className="btn" disabled={saving || !customerId} style={{ width: "auto", padding: "11px 24px" }}>{saving ? "Creating…" : "Create invoice"}</button>
          </div>
        </form>
      </div>
    </div>
  );
}

function CustomersTab({ onError }: { onError: (m: string) => void }) {
  const [list, setList] = useState<InvoicingCustomer[]>([]);
  const [form, setForm] = useState({ name: "", phone: "", email: "", gstin: "", billing_address: "" });
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    try { setList((await api.invoicing.customers(token)).customers); } catch (e) { onError((e as ApiError).message); }
  }, [onError]);
  useEffect(() => { load(); }, [load]);

  async function add(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token || !form.name.trim()) return;
    setBusy(true);
    try { await api.invoicing.addCustomer(token, { name: form.name.trim(), phone: form.phone || undefined, email: form.email || undefined, gstin: form.gstin || undefined, billing_address: form.billing_address || undefined }); setForm({ name: "", phone: "", email: "", gstin: "", billing_address: "" }); load(); }
    catch (e2) { onError((e2 as ApiError).message); } finally { setBusy(false); }
  }
  async function remove(id: string) {
    const token = getToken();
    if (!token || !confirm("Delete customer?")) return;
    try { await api.invoicing.removeCustomer(token, id); load(); } catch (e) { onError((e as ApiError).message); }
  }

  return (
    <div>
      <form onSubmit={add} className="panel" style={{ padding: 12, marginBottom: 14, display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
        <input placeholder="Name" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required style={inputS(1.5, 150)} />
        <input placeholder="Phone" value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} style={inputS(1, 110)} />
        <input placeholder="Email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} style={inputS(1, 140)} />
        <input placeholder="GSTIN" value={form.gstin} onChange={(e) => setForm((f) => ({ ...f, gstin: e.target.value }))} style={inputS(1, 120)} />
        <button className="btn" disabled={busy} style={{ width: "auto", padding: "9px 18px" }}>+ Add</button>
      </form>
      <div style={{ display: "grid", gap: 8 }}>
        {list.map((c) => (
          <div key={c.id} className="panel" style={{ padding: "12px 16px", display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
            <div style={{ flex: 1, minWidth: 180 }}>
              <strong style={{ fontSize: 14 }}>{c.name}</strong>
              <div className="muted" style={{ fontSize: 12.5 }}>{[c.email, c.phone, c.gstin].filter(Boolean).join(" · ") || "—"} · {c.invoices_count} invoice{c.invoices_count === 1 ? "" : "s"}</div>
            </div>
            <button className="btn-mini" onClick={() => remove(c.id)}>✕</button>
          </div>
        ))}
        {list.length === 0 && <div className="panel" style={{ padding: 24, textAlign: "center" }}><span className="muted">No customers yet.</span></div>}
      </div>
    </div>
  );
}

function ServicesTab({ onError }: { onError: (m: string) => void }) {
  const [list, setList] = useState<InvoicingService[]>([]);
  const [form, setForm] = useState({ name: "", price: "", gst_percent: "18" });
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    try { setList((await api.invoicing.services(token)).services); } catch (e) { onError((e as ApiError).message); }
  }, [onError]);
  useEffect(() => { load(); }, [load]);

  async function add(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token || !form.name.trim()) return;
    setBusy(true);
    try { await api.invoicing.addService(token, { name: form.name.trim(), price: Number(form.price) || 0, gst_percent: Number(form.gst_percent) || 18 }); setForm({ name: "", price: "", gst_percent: "18" }); load(); }
    catch (e2) { onError((e2 as ApiError).message); } finally { setBusy(false); }
  }
  async function remove(id: string) {
    const token = getToken();
    if (!token || !confirm("Delete service?")) return;
    try { await api.invoicing.removeService(token, id); load(); } catch (e) { onError((e as ApiError).message); }
  }

  return (
    <div>
      <form onSubmit={add} className="panel" style={{ padding: 12, marginBottom: 14, display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
        <input placeholder="Service name" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} required style={inputS(2, 150)} />
        <input type="number" placeholder="Price ₹" value={form.price} onChange={(e) => setForm((f) => ({ ...f, price: e.target.value }))} style={inputS(1, 100)} />
        <input type="number" placeholder="GST %" value={form.gst_percent} onChange={(e) => setForm((f) => ({ ...f, gst_percent: e.target.value }))} style={inputS(1, 90)} />
        <button className="btn" disabled={busy} style={{ width: "auto", padding: "9px 18px" }}>+ Add</button>
      </form>
      <div style={{ display: "grid", gap: 8 }}>
        {list.map((s) => (
          <div key={s.id} className="panel" style={{ padding: "12px 16px", display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
            <div style={{ flex: 1, minWidth: 180 }}>
              <strong style={{ fontSize: 14 }}>{s.name}</strong>
              <div className="muted" style={{ fontSize: 12.5 }}>{inr(s.price)} · GST {s.gst_percent}%{s.category ? ` · ${s.category}` : ""}</div>
            </div>
            <button className="btn-mini" onClick={() => remove(s.id)}>✕</button>
          </div>
        ))}
        {list.length === 0 && <div className="panel" style={{ padding: 24, textAlign: "center" }}><span className="muted">No services yet.</span></div>}
      </div>
    </div>
  );
}

function ExpensesTab({ onError }: { onError: (m: string) => void }) {
  const [list, setList] = useState<InvoicingExpense[]>([]);
  const [stats, setStats] = useState<{ spent_this_month: number; count: number } | null>(null);
  const [form, setForm] = useState({ date: new Date().toISOString().slice(0, 10), description: "", category: "", method: "CASH", amount: "" });
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    try { const r = await api.invoicing.expenses(token); setList(r.expenses); setStats(r.stats); } catch (e) { onError((e as ApiError).message); }
  }, [onError]);
  useEffect(() => { load(); }, [load]);

  async function add(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token || !form.description.trim()) return;
    setBusy(true);
    try { await api.invoicing.addExpense(token, { date: form.date, description: form.description.trim(), category: form.category || undefined, method: form.method, amount: Number(form.amount) || 0 }); setForm((f) => ({ ...f, description: "", category: "", amount: "" })); load(); }
    catch (e2) { onError((e2 as ApiError).message); } finally { setBusy(false); }
  }
  async function remove(id: string) {
    const token = getToken();
    if (!token || !confirm("Delete expense?")) return;
    try { await api.invoicing.removeExpense(token, id); load(); } catch (e) { onError((e as ApiError).message); }
  }

  return (
    <div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(190px,1fr))", gap: 12, marginBottom: 14 }}>
        <Stat label="Spent this month" value={stats ? inr(stats.spent_this_month) : "—"} icon="💳" bg="#fdecec" />
        <Stat label="Entries" value={stats?.count ?? 0} icon="📒" bg="#eef3ff" />
      </div>
      <form onSubmit={add} className="panel" style={{ padding: 12, marginBottom: 14, display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
        <input type="date" value={form.date} onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))} style={inputS(1, 130)} />
        <input placeholder="Description" value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} required style={inputS(2, 150)} />
        <input placeholder="Category" value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))} style={inputS(1, 110)} />
        <select value={form.method} onChange={(e) => setForm((f) => ({ ...f, method: e.target.value }))} style={{ padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}>
          {["CASH", "BANK", "UPI", "CARD", "OTHER"].map((m) => <option key={m} value={m}>{m}</option>)}
        </select>
        <input type="number" placeholder="Amount ₹" value={form.amount} onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))} style={inputS(1, 100)} />
        <button className="btn" disabled={busy} style={{ width: "auto", padding: "9px 18px" }}>+ Add</button>
      </form>
      <div style={{ display: "grid", gap: 8 }}>
        {list.map((x) => (
          <div key={x.id} className="panel" style={{ padding: "12px 16px", display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
            <div style={{ flex: 1, minWidth: 180 }}>
              <strong style={{ fontSize: 14 }}>{x.description}</strong>
              <div className="muted" style={{ fontSize: 12.5 }}>{[x.date, x.category, x.method].filter(Boolean).join(" · ")}</div>
            </div>
            <strong style={{ fontSize: 14 }}>{inr(x.amount)}</strong>
            <button className="btn-mini" onClick={() => remove(x.id)}>✕</button>
          </div>
        ))}
        {list.length === 0 && <div className="panel" style={{ padding: 24, textAlign: "center" }}><span className="muted">No expenses logged.</span></div>}
      </div>
    </div>
  );
}

function inputS(flex: number, minWidth: number): React.CSSProperties {
  return { flex, minWidth, padding: "9px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 };
}

function Stat({ label, value, icon, bg }: { label: string; value: React.ReactNode; icon: string; bg: string }) {
  return (
    <div className="stat-card">
      <div className="ic" style={{ background: bg }}>{icon}</div>
      <div><div className="sc-label">{label}</div><div className="sc-value">{value}</div></div>
    </div>
  );
}
