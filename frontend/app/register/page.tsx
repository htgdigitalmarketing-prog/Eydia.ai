"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { api, ApiError } from "@/lib/api";
import { saveToken } from "@/lib/auth";

/**
 * Sign-up by mobile number + WhatsApp OTP only. The workspace is created on
 * verify; the customer fills company name / email etc. later from their profile.
 */
export default function RegisterPage() {
  const router = useRouter();
  const [step, setStep] = useState<"phone" | "code">("phone");
  const [phone, setPhone] = useState("+91 ");
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function sendCode(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setNotice(null);
    setLoading(true);
    try {
      const res = await api.requestOtp({ phone, register: true });
      setStep("code");
      setNotice(res?.dev_code ? `Dev code: ${res.dev_code}` : "We sent a 6-digit code to your WhatsApp.");
    } catch (err) {
      setError((err as ApiError).message ?? "Could not send the code. Check the number and try again.");
    } finally {
      setLoading(false);
    }
  }

  async function verify(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const data = await api.verifyOtp({ phone, code, register: true });
      saveToken(data.token);
      router.push("/dashboard");
    } catch (err) {
      setError((err as ApiError).message ?? "That code is invalid or expired.");
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
        <p className="subtitle" style={{ textAlign: "center" }}>Sign up with your mobile number</p>

        {error && <div className="error">{error}</div>}
        {notice && <div className="panel" style={{ background: "#e7f7ef", borderColor: "#b6e6cd", color: "#0a7d47", padding: "10px 14px", fontSize: 14 }}>{notice}</div>}

        {step === "phone" ? (
          <form onSubmit={sendCode}>
            <div className="field">
              <label>Mobile number</label>
              <input
                type="tel"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                required
                placeholder="+91 98765 43210"
                autoComplete="tel"
              />
            </div>
            <p className="muted" style={{ fontSize: 12.5, marginTop: -4, marginBottom: 12 }}>
              We&apos;ll send a one-time code to this number on WhatsApp. You can add your
              company name &amp; email later from your profile.
            </p>
            <button className="btn" disabled={loading}>{loading ? "Sending…" : "Send code on WhatsApp"}</button>
          </form>
        ) : (
          <form onSubmit={verify}>
            <div className="field">
              <label>Enter the 6-digit code</label>
              <input
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
                required
                inputMode="numeric"
                maxLength={6}
                placeholder="______"
                autoFocus
                style={{ letterSpacing: 6, textAlign: "center", fontSize: 18 }}
              />
            </div>
            <button className="btn" disabled={loading || code.length !== 6}>{loading ? "Verifying…" : "Verify & create workspace"}</button>
            <div style={{ display: "flex", justifyContent: "space-between", marginTop: 12, fontSize: 13 }}>
              <button type="button" onClick={() => { setStep("phone"); setCode(""); setError(null); setNotice(null); }}
                style={{ background: "none", border: "none", color: "var(--accent-dark)", cursor: "pointer", padding: 0 }}>← Change number</button>
              <button type="button" onClick={(e) => sendCode(e as unknown as React.FormEvent)} disabled={loading}
                style={{ background: "none", border: "none", color: "var(--accent-dark)", cursor: "pointer", padding: 0 }}>Resend code</button>
            </div>
          </form>
        )}

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
