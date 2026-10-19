"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, Company, CompanyUser } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

const EMPTY = { company_name: "", owner_name: "", owner_email: "", password: "", phone: "", plan_id: "" };

export default function CompaniesPage() {
  const me = useUser();
  const [companies, setCompanies] = useState<Company[]>([]);
  const [plans, setPlans] = useState<{ id: string; name: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [showAdd, setShowAdd] = useState(false);
  const [form, setForm] = useState({ ...EMPTY });
  const [submitting, setSubmitting] = useState(false);

  // Manage-users (password reset) panel
  const [usersFor, setUsersFor] = useState<Company | null>(null);
  const [companyUsers, setCompanyUsers] = useState<CompanyUser[]>([]);
  const [usersLoading, setUsersLoading] = useState(false);

  const isSuper = !!me.is_super_admin;

  async function openUsers(c: Company) {
    const token = getToken();
    if (!token) return;
    setUsersFor(c);
    setCompanyUsers([]);
    setUsersLoading(true);
    try {
      const res = await api.admin.companyUsers(token, c.id);
      setCompanyUsers(res.users);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setUsersLoading(false);
    }
  }

  async function resetPassword(u: CompanyUser) {
    const token = getToken();
    if (!token || !usersFor) return;
    const pw = window.prompt(`New password for ${u.name} (${u.role}) — min 8 characters:`);
    if (!pw) return;
    if (pw.length < 8) { setError("Password must be at least 8 characters."); return; }
    try {
      const res = await api.admin.resetUserPassword(token, usersFor.id, u.id, pw);
      flash(res.message);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const res = await api.admin.companies(token);
      setCompanies(res.companies);
      setPlans(res.plans ?? []);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  async function changePlan(c: Company, planId: string) {
    const token = getToken();
    if (!token || !planId) return;
    try {
      await api.admin.assignPlan(token, c.id, planId);
      flash("Plan updated.");
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  useEffect(() => { if (isSuper) load(); else setLoading(false); }, [isSuper, load]);

  function flash(msg: string) {
    setNotice(msg);
    setTimeout(() => setNotice(null), 3500);
  }

  async function addCompany(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSubmitting(true);
    setError(null);
    try {
      await api.admin.createCompany(token, {
        company_name: form.company_name,
        owner_name: form.owner_name,
        owner_email: form.owner_email,
        password: form.password,
        phone: form.phone || undefined,
        plan_id: form.plan_id || undefined,
      });
      setShowAdd(false);
      setForm({ ...EMPTY });
      flash(`Company "${form.company_name}" created.`);
      await load();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSubmitting(false);
    }
  }

  async function toggleCompany(c: Company) {
    const token = getToken();
    if (!token) return;
    try {
      const res = await api.admin.toggleCompany(token, c.id);
      setCompanies((prev) => prev.map((x) => (x.id === c.id ? { ...x, status: res.status } : x)));
      flash(res.message);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function topUpCredits(c: Company) {
    const token = getToken();
    if (!token) return;
    const amt = prompt(`Add AI credits to ${c.name}:`, "100");
    if (!amt) return;
    const n = parseInt(amt, 10);
    if (!n || n < 1) return;
    try {
      const r = await api.admin.addCredits(token, c.id, n);
      flash(`${r.message} New balance: ${r.balance}`);
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  async function removeCompany(c: Company) {
    const token = getToken();
    if (!token) return;
    if (!confirm(`Delete company "${c.name}" and all its data? This cannot be undone.`)) return;
    try {
      await api.admin.removeCompany(token, c.id);
      setCompanies((prev) => prev.filter((x) => x.id !== c.id));
      flash("Company deleted.");
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  if (!isSuper) {
    return (
      <div>
        <PageHeader title="Companies" />
        <div className="panel"><p className="muted">This area is for the platform super admin only.</p></div>
      </div>
    );
  }

  const active = companies.filter((c) => c.status !== "suspended").length;

  return (
    <div>
      <PageHeader
        title="Companies"
        subtitle="Every company (workspace) on the platform."
        action={<button className="btn" onClick={() => setShowAdd(true)}>+ Add company</button>}
      />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      <div className="stat-cards">
        <div className="stat-card"><div className="ic" style={{ background: "#e7f0ff" }}>🏢</div><div><div className="sc-label">Companies</div><div className="sc-value">{companies.length}</div></div></div>
        <div className="stat-card"><div className="ic" style={{ background: "#e7f7ef" }}>✅</div><div><div className="sc-label">Active</div><div className="sc-value">{active}</div></div></div>
        <div className="stat-card"><div className="ic" style={{ background: "#fdecec" }}>⛔</div><div><div className="sc-label">Suspended</div><div className="sc-value">{companies.length - active}</div></div></div>
      </div>

      {loading ? (
        <LoadingBlock label="Loading companies…" />
      ) : companies.length === 0 ? (
        <div className="panel" style={{ textAlign: "center", padding: 40 }}>
          <div style={{ fontSize: 40 }}>🏢</div>
          <h3 style={{ margin: "12px 0 4px" }}>No companies yet</h3>
          <p className="muted" style={{ marginBottom: 16 }}>Create the first company workspace.</p>
          <button className="btn" onClick={() => setShowAdd(true)}>Add company</button>
        </div>
      ) : (
        <div className="panel" style={{ padding: 0, overflow: "hidden" }}>
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", fontSize: 12, color: "#667781", background: "#f8fafb", borderBottom: "1px solid #eef1f2" }}>
                <th style={{ padding: "12px 14px" }}>Company</th>
                <th style={{ padding: "12px 14px" }}>Owner</th>
                <th style={{ padding: "12px 14px" }}>Plan</th>
                <th style={{ padding: "12px 14px" }}>Agents</th>
                <th style={{ padding: "12px 14px" }}>Status</th>
                <th style={{ padding: "12px 14px", textAlign: "right" }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {companies.map((c) => (
                <tr key={c.id} style={{ borderBottom: "1px solid #f4f6f7" }}>
                  <td style={{ padding: "12px 14px", fontWeight: 600 }}>{c.name}</td>
                  <td style={{ padding: "12px 14px" }}>
                    <div style={{ fontSize: 13 }}>{c.owner_name ?? "—"}</div>
                    <div className="muted" style={{ fontSize: 12 }}>{c.owner_email}</div>
                  </td>
                  <td style={{ padding: "12px 14px" }}>
                    <select value={c.plan_id ?? ""} onChange={(e) => changePlan(c, e.target.value)}
                      style={{ padding: "6px 8px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13, maxWidth: 150 }}>
                      <option value="">— No plan —</option>
                      {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                  </td>
                  <td style={{ padding: "12px 14px", fontVariantNumeric: "tabular-nums" }}>
                    {c.agents_used ?? 0}{c.agents_limit != null ? ` / ${c.agents_limit}` : " / ∞"}
                  </td>
                  <td style={{ padding: "12px 14px" }}>
                    <span style={{
                      background: c.status === "suspended" ? "#fdecec" : "#e7f7ef",
                      color: c.status === "suspended" ? "#c53030" : "#0a7d47",
                      padding: "3px 10px", borderRadius: 999, fontSize: 12, fontWeight: 600, textTransform: "capitalize",
                    }}>{c.status}</span>
                  </td>
                  <td style={{ padding: "12px 14px", textAlign: "right", whiteSpace: "nowrap" }}>
                    <button className="btn-mini" onClick={() => openUsers(c)}>Users</button>
                    <button className="btn-mini" style={{ marginLeft: 6 }} onClick={() => topUpCredits(c)}>+ Credits</button>
                    <button className="btn-mini" style={{ marginLeft: 6 }} onClick={() => toggleCompany(c)}>{c.status === "suspended" ? "Reactivate" : "Suspend"}</button>
                    <button className="btn-mini" style={{ color: "#c53030", marginLeft: 6 }} onClick={() => removeCompany(c)}>Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {usersFor && (
        <div className="msg-info-overlay" onClick={() => setUsersFor(null)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 520 }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
              <h2 style={{ marginTop: 0 }}>{usersFor.name} — users</h2>
              <button className="btn-mini" onClick={() => setUsersFor(null)}>Close</button>
            </div>
            <p className="muted" style={{ fontSize: 13, marginTop: 0 }}>Reset the login password for the Admin (owner) or any Agent of this company.</p>
            {usersLoading ? (
              <LoadingBlock label="Loading users…" />
            ) : companyUsers.length === 0 ? (
              <p className="muted">No users found.</p>
            ) : (
              <table style={{ width: "100%", borderCollapse: "collapse" }}>
                <tbody>
                  {companyUsers.map((u) => (
                    <tr key={u.id} style={{ borderBottom: "1px solid #f4f6f7" }}>
                      <td style={{ padding: "10px 6px" }}>
                        <div style={{ fontWeight: 600 }}>{u.name} <span style={{ background: u.role === "Admin" ? "#e7f0ff" : "#eef1f2", color: u.role === "Admin" ? "#1877f2" : "#54656f", padding: "1px 8px", borderRadius: 999, fontSize: 11, fontWeight: 700, marginLeft: 6 }}>{u.role}</span></div>
                        <div className="muted" style={{ fontSize: 12 }}>{u.email}</div>
                      </td>
                      <td style={{ padding: "10px 6px", textAlign: "right" }}>
                        <button className="btn-mini" onClick={() => resetPassword(u)}>Reset password</button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}

      {showAdd && (
        <div className="msg-info-overlay" onClick={() => setShowAdd(false)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 460 }}>
            <h2 style={{ marginTop: 0 }}>Add company</h2>
            <p className="muted" style={{ fontSize: 13, marginTop: 0 }}>Creates a workspace and its owner (admin) account.</p>
            <form onSubmit={addCompany}>
              <div className="field">
                <label>Company name</label>
                <input value={form.company_name} onChange={(e) => setForm((f) => ({ ...f, company_name: e.target.value }))} required placeholder="Acme Realty" />
              </div>
              <div className="field">
                <label>Owner name</label>
                <input value={form.owner_name} onChange={(e) => setForm((f) => ({ ...f, owner_name: e.target.value }))} required placeholder="Jane Doe" />
              </div>
              <div className="field">
                <label>Owner email</label>
                <input type="email" value={form.owner_email} onChange={(e) => setForm((f) => ({ ...f, owner_email: e.target.value }))} required placeholder="owner@acme.com" />
              </div>
              <div className="field">
                <label>Temporary password</label>
                <input type="text" value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} required minLength={8} placeholder="Min 8 characters" />
              </div>
              <div className="field">
                <label>Phone <span className="muted">(optional)</span></label>
                <input value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} placeholder="+91…" />
              </div>
              <div className="field">
                <label>Subscription plan</label>
                <select value={form.plan_id} onChange={(e) => setForm((f) => ({ ...f, plan_id: e.target.value }))}>
                  <option value="">— No plan (unlimited) —</option>
                  {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
                <span className="muted" style={{ fontSize: 12 }}>Controls the company&apos;s agent limit &amp; feature access.</span>
              </div>
              <div style={{ display: "flex", gap: 8, marginTop: 10 }}>
                <button className="btn" disabled={submitting}>{submitting ? "Creating…" : "Create company"}</button>
                <button type="button" className="btn-mini" onClick={() => setShowAdd(false)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
