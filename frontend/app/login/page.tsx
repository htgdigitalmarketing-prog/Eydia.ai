"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { api, ApiError } from "@/lib/api";
import { saveToken } from "@/lib/auth";

const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api/v1";
const GOOGLE_ERRORS: Record<string, string> = {
  not_configured: "Google sign-in isn't configured yet. Ask the admin to add the Google client credentials.",
  cancelled: "Google sign-in was cancelled.",
  token_exchange_failed: "Google sign-in failed. Please try again.",
  profile_failed: "Couldn't read your Google profile. Please try again.",
  no_email: "Your Google account didn't share an email address.",
  account_disabled: "This account has been disabled.",
};

export default function LoginPage() {
  const router = useRouter();
  const [mode, setMode] = useState<"password" | "otp">("password");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  // OTP flow
  const [phone, setPhone] = useState("");
  const [otp, setOtp] = useState("");
  const [otpSent, setOtpSent] = useState(false);
  const [resendIn, setResendIn] = useState(0);

  useEffect(() => {
    const err = new URLSearchParams(window.location.search).get("google_error");
    if (err) setError(GOOGLE_ERRORS[err] ?? "Google sign-in failed. Please try again.");
  }, []);

  useEffect(() => {
    if (resendIn <= 0) return;
    const t = setTimeout(() => setResendIn((n) => n - 1), 1000);
    return () => clearTimeout(t);
  }, [resendIn]);

  function googleLogin() {
    window.location.href = `${API_BASE}/auth/google/redirect`;
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const data = await api.login({ email, password });
      saveToken(data.token);
      router.push("/dashboard");
    } catch (err) {
      setError((err as ApiError).message ?? "Login failed");
    } finally {
      setLoading(false);
    }
  }

  async function sendOtp(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setNotice(null);
    setLoading(true);
    try {
      await api.requestOtp({ phone: `91${phone}` });
      setOtpSent(true);
      setResendIn(30);
      setNotice("We've sent a 6-digit code to your WhatsApp.");
    } catch (err) {
      const e2 = err as ApiError;
      setError(e2.errors ? Object.values(e2.errors).flat().join(" ") : e2.message ?? "Couldn't send the code.");
    } finally {
      setLoading(false);
    }
  }

  async function verifyOtp(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const data = await api.verifyOtp({ phone: `91${phone}`, code: otp });
      saveToken(data.token);
      router.push("/dashboard");
    } catch (err) {
      setError((err as ApiError).message ?? "Invalid code.");
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
        <p className="subtitle" style={{ textAlign: "center" }}>Sign in to your workspace</p>

        {error && <div className="error">{error}</div>}
        {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", padding: "8px 12px", borderRadius: 8, fontSize: 13, marginBottom: 10 }}>{notice}</div>}

        <button type="button" className="google-btn" onClick={googleLogin}>
          <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.9 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.5 6.5 29.5 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.5 6.5 29.5 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.5-5.2l-6.2-5.3C29.2 35 26.7 36 24 36c-5.3 0-9.7-3.1-11.3-7.6l-6.5 5C9.6 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.2-4.1 5.5l6.2 5.3C40.9 36.4 44 30.8 44 24c0-1.3-.1-2.3-.4-3.5z"/></svg>
          Continue with Google
        </button>

        <div className="auth-divider"><span>or</span></div>

        {/* Password / Mobile OTP — two buttons in one row, styled like "Continue with Google" */}
        <div style={{ display: "flex", gap: 10, marginBottom: 16 }}>
          <button
            type="button"
            className="google-btn"
            onClick={() => { setMode("password"); setError(null); setNotice(null); }}
            style={{ flex: 1, minWidth: 0, padding: "10px 8px", fontSize: 13, gap: 6, ...(mode === "password" ? { borderColor: "#5b6bf5", boxShadow: "0 0 0 1px #5b6bf5 inset", color: "#5b6bf5" } : {}) }}
          >
            <span style={{ fontSize: 15 }}>📧</span> Email &amp; Password
          </button>
          <button
            type="button"
            className="google-btn"
            onClick={() => { setMode("otp"); setError(null); setNotice(null); }}
            style={{ flex: 1, minWidth: 0, padding: "10px 8px", fontSize: 13, gap: 6, ...(mode === "otp" ? { borderColor: "#5b6bf5", boxShadow: "0 0 0 1px #5b6bf5 inset", color: "#5b6bf5" } : {}) }}
          >
            <span style={{ fontSize: 15 }}>📱</span> Mobile OTP
          </button>
        </div>

        {mode === "password" ? (
          <form onSubmit={onSubmit}>
            <div className="field">
              <label>Email</label>
              <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required placeholder="you@company.com" />
            </div>
            <div className="field">
              <label>Password</label>
              <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required placeholder="••••••••" />
            </div>
            <button className="btn" disabled={loading}>{loading ? "Signing in…" : "Sign in"}</button>
          </form>
        ) : !otpSent ? (
          <form onSubmit={sendOtp}>
            <div className="field">
              <label>Mobile number</label>
              <div style={{ display: "flex", alignItems: "stretch", border: "1px solid var(--border)", borderRadius: 10, overflow: "hidden" }}>
                <span style={{ display: "flex", alignItems: "center", padding: "0 12px", background: "#f6f8f9", borderRight: "1px solid var(--border)", color: "var(--text)", fontSize: 14, fontWeight: 600 }}>+91</span>
                <input
                  type="tel"
                  inputMode="numeric"
                  maxLength={10}
                  value={phone}
                  onChange={(e) => setPhone(e.target.value.replace(/\D/g, "").slice(0, 10))}
                  required
                  placeholder="98765 43210"
                  autoComplete="tel"
                  style={{ flex: 1, border: "none", outline: "none", padding: "11px 12px", fontSize: 14, background: "transparent" }}
                />
              </div>
              <span style={{ fontSize: 12, color: "var(--muted)", marginTop: 4, display: "block" }}>Enter your 10-digit mobile number.</span>
            </div>
            <button className="btn" disabled={loading}>{loading ? "Sending…" : "Send OTP"}</button>
          </form>
        ) : (
          <form onSubmit={verifyOtp}>
            <div className="field">
              <label>Enter the 6-digit code</label>
              <input inputMode="numeric" maxLength={6} value={otp}
                onChange={(e) => setOtp(e.target.value.replace(/\D/g, "").slice(0, 6))}
                required placeholder="______"
                style={{ letterSpacing: "0.5em", textAlign: "center", fontSize: 18 }} autoComplete="one-time-code" />
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 6 }}>
                <button type="button" onClick={() => { setOtpSent(false); setOtp(""); setNotice(null); }}
                  style={{ background: "none", border: "none", color: "var(--muted)", fontSize: 12, cursor: "pointer", padding: 0 }}>← Change number</button>
                <button type="button" disabled={resendIn > 0 || loading} onClick={(e) => sendOtp(e)}
                  style={{ background: "none", border: "none", color: resendIn > 0 ? "var(--muted)" : "#5b6bf5", fontSize: 12, cursor: resendIn > 0 ? "default" : "pointer", padding: 0 }}>
                  {resendIn > 0 ? `Resend in ${resendIn}s` : "Resend code"}
                </button>
              </div>
            </div>
            <button className="btn" disabled={loading || otp.length !== 6}>{loading ? "Verifying…" : "Verify & Sign in"}</button>
          </form>
        )}

        <div className="switch">
          New here? <Link href="/register">Create an account</Link>
        </div>
      </div>
      <div style={{ marginTop: 18, fontSize: 12, color: "rgba(255,255,255,0.85)", textAlign: "center" }}>
        © {new Date().getFullYear()} Eydia.ai
      </div>
    </div>
  );
}
