"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { api, ApiError } from "@/lib/api";
import { saveToken } from "@/lib/auth";

export default function RegisterPage() {
  const router = useRouter();
  const [form, setForm] = useState({
    company_name: "",
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
  });
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [loading, setLoading] = useState(false);

  function update(key: keyof typeof form, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setFieldErrors({});
    setLoading(true);
    try {
      const data = await api.register(form);
      saveToken(data.token);
      router.push("/dashboard");
    } catch (err) {
      const apiErr = err as ApiError;
      setError(apiErr.message ?? "Registration failed");
      if (apiErr.errors) setFieldErrors(apiErr.errors);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="auth-wrap">
      <div className="card">
        <div style={{ display: "flex", justifyContent: "center", marginBottom: 6 }}>
          <img src="/eydia-logo.png" alt="Eydia.ai — Automate · Engage · Grow" style={{ width: "100%", maxWidth: 210, height: "auto", display: "block" }} />
        </div>
        <p className="subtitle" style={{ textAlign: "center" }}>Create your company workspace</p>

        {error && <div className="error">{error}</div>}

        <form onSubmit={onSubmit}>
          <div className="field">
            <label>Company name</label>
            <input value={form.company_name} onChange={(e) => update("company_name", e.target.value)} required placeholder="Acme Realty" />
          </div>
          <div className="field">
            <label>Your name</label>
            <input value={form.name} onChange={(e) => update("name", e.target.value)} required placeholder="Jane Doe" />
          </div>
          <div className="field">
            <label>Email</label>
            <input type="email" value={form.email} onChange={(e) => update("email", e.target.value)} required placeholder="you@company.com" />
            {fieldErrors.email && <div style={{ color: "#e02424", fontSize: 12, marginTop: 4 }}>{fieldErrors.email[0]}</div>}
          </div>
          <div className="field">
            <label>Password</label>
            <input type="password" value={form.password} onChange={(e) => update("password", e.target.value)} required placeholder="Min 8 chars, mixed case + number" />
            {fieldErrors.password && <div style={{ color: "#e02424", fontSize: 12, marginTop: 4 }}>{fieldErrors.password[0]}</div>}
          </div>
          <div className="field">
            <label>Confirm password</label>
            <input type="password" value={form.password_confirmation} onChange={(e) => update("password_confirmation", e.target.value)} required placeholder="Repeat password" />
          </div>
          <button className="btn" disabled={loading}>{loading ? "Creating…" : "Create workspace"}</button>
        </form>

        <div className="switch">
          Already have an account? <Link href="/login">Sign in</Link>
        </div>
      </div>
      <div style={{ marginTop: 18, fontSize: 12, color: "var(--muted)", textAlign: "center" }}>
        © {new Date().getFullYear()} Eydia.ai
      </div>
    </div>
  );
}
