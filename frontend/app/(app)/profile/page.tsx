"use client";

import { useEffect, useRef, useState } from "react";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { api, ApiError } from "@/lib/api";
import { getToken } from "@/lib/auth";

/** Read an image file, scale it to fit `max`×`max`, and return a compact JPEG data URL. */
function downscaleImage(file: File, max: number): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error("read failed"));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error("decode failed"));
      img.onload = () => {
        const scale = Math.min(1, max / Math.max(img.width, img.height));
        const w = Math.round(img.width * scale);
        const h = Math.round(img.height * scale);
        const canvas = document.createElement("canvas");
        canvas.width = w; canvas.height = h;
        const ctx = canvas.getContext("2d");
        if (!ctx) { reject(new Error("no canvas")); return; }
        // Flatten transparency onto white (so a transparent PNG logo doesn't end
        // up on a black background once encoded as JPEG).
        ctx.fillStyle = "#ffffff";
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(img, 0, 0, w, h);
        resolve(canvas.toDataURL("image/jpeg", 0.85));
      };
      img.src = reader.result as string;
    };
    reader.readAsDataURL(file);
  });
}

export default function ProfilePage() {
  const user = useUser();

  const [name, setName] = useState(user.name);
  const [email, setEmail] = useState(user.email);
  const [phone, setPhone] = useState(user.phone ?? "");
  const [address, setAddress] = useState(user.address ?? "");
  const [avatar, setAvatar] = useState<string | null>(user.avatar_url ?? null);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const avatarRef = useRef<HTMLInputElement>(null);

  // Auto-filled: the WhatsApp number connected to this workspace (read-only).
  const [waNumber, setWaNumber] = useState<string | null>(null);
  useEffect(() => {
    const token = getToken();
    if (!token) return;
    api.whatsapp.numbers(token)
      .then((r) => {
        const def = r.numbers.find((n) => n.is_default) ?? r.numbers[0];
        if (def) setWaNumber(def.display_phone_number);
      })
      .catch(() => {});
  }, []);

  async function pickAvatar(file: File) {
    if (file.size > 5 * 1024 * 1024) { setError("Image is too large — please use one under 5MB."); return; }
    setError(null);
    try {
      // Downscale to a small square so the stored data URL stays light (~tens of KB)
      // regardless of the original file size.
      const dataUrl = await downscaleImage(file, 400);
      setAvatar(dataUrl);
    } catch {
      setError("Couldn't read that image. Please try a different PNG or JPG.");
    }
  }

  const [curPwd, setCurPwd] = useState("");
  const [newPwd, setNewPwd] = useState("");
  const [confirmPwd, setConfirmPwd] = useState("");
  const [pwdSaving, setPwdSaving] = useState(false);
  const [pwdNotice, setPwdNotice] = useState<string | null>(null);
  const [pwdError, setPwdError] = useState<string | null>(null);

  function flash(msg: string, setter: (v: string | null) => void) {
    setter(msg);
    setTimeout(() => setter(null), 3500);
  }

  async function handleProfile(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setSaving(true);
    setError(null);
    try {
      await api.updateProfile(token, {
        name, email,
        phone: phone || undefined,
        address: address || undefined,
        avatar_url: avatar,
      });
      flash("Profile updated.", setNotice);
    } catch (err) {
      const e2 = err as ApiError;
      setError(e2.errors ? Object.values(e2.errors).flat().join(". ") : e2.message);
    } finally {
      setSaving(false);
    }
  }

  async function handlePassword(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    if (newPwd !== confirmPwd) {
      setPwdError("New password and confirmation do not match.");
      return;
    }
    setPwdSaving(true);
    setPwdError(null);
    try {
      const res = await api.changePassword(token, {
        ...(user.is_super_admin ? {} : { current_password: curPwd }),
        password: newPwd,
        password_confirmation: confirmPwd,
      });
      flash(res.message, setPwdNotice);
      setCurPwd("");
      setNewPwd("");
      setConfirmPwd("");
    } catch (err) {
      const e2 = err as ApiError;
      setPwdError(e2.errors ? Object.values(e2.errors).flat().join(". ") : e2.message);
    } finally {
      setPwdSaving(false);
    }
  }

  return (
    <>
      <PageHeader title="Profile" subtitle="Manage your account" />

      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(340px, 1fr))", gap: 18 }}>
        {/* Profile details */}
        <div className="panel">
          <h2 style={{ marginTop: 0 }}>Account Details</h2>
          {error && <div className="error">{error}</div>}
          {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 12, padding: "8px 14px" }}>{notice}</div>}
          <form onSubmit={handleProfile}>
            {/* Avatar */}
            <div className="field">
              <label>Profile Photo</label>
              <div style={{ display: "flex", alignItems: "center", gap: 14 }}>
                <div style={{ width: 68, height: 68, borderRadius: "50%", overflow: "hidden", background: "#eef0ff", display: "flex", alignItems: "center", justifyContent: "center", flex: "none", fontSize: 26, fontWeight: 700, color: "#5b6bf5", border: "1px solid var(--border)" }}>
                  {avatar ? (
                    /* eslint-disable-next-line @next/next/no-img-element */
                    <img src={avatar} alt="" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                  ) : (
                    (name || "?").trim().charAt(0).toUpperCase()
                  )}
                </div>
                <div style={{ display: "flex", gap: 8 }}>
                  <input ref={avatarRef} type="file" accept="image/png,image/jpeg,image/webp" style={{ display: "none" }}
                    onChange={(e) => { const f = e.target.files?.[0]; if (f) pickAvatar(f); e.target.value = ""; }} />
                  <button type="button" className="btn-mini" onClick={() => avatarRef.current?.click()}>Upload</button>
                  {avatar && <button type="button" className="btn-mini" style={{ color: "#c53030" }} onClick={() => setAvatar(null)}>Remove</button>}
                </div>
              </div>
              <span className="muted" style={{ fontSize: 12, marginTop: 4, display: "block" }}>PNG/JPG, under 2MB.</span>
            </div>
            <div className="field">
              <label>Full Name</label>
              <input value={name} onChange={(e) => setName(e.target.value)} required />
            </div>
            <div className="field">
              <label>Email</label>
              <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
            </div>
            <div className="field">
              <label>Phone <span className="muted">(optional)</span></label>
              <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+91..." />
            </div>
            <div className="field">
              <label>Address <span className="muted">(optional)</span></label>
              <textarea value={address} onChange={(e) => setAddress(e.target.value)} rows={2} placeholder="Street, city, state, PIN"
                style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, fontFamily: "inherit", resize: "vertical" }} />
            </div>
            {waNumber && (
              <div className="field">
                <label className="muted" style={{ fontSize: 12 }}>Connected WhatsApp number</label>
                <div style={{ fontSize: 14, fontWeight: 600, display: "flex", alignItems: "center", gap: 6 }}>
                  <span style={{ color: "#25d366" }}>✓</span> {waNumber}
                </div>
              </div>
            )}
            <div className="field" style={{ marginTop: 4 }}>
              <label className="muted" style={{ fontSize: 12 }}>Role</label>
              <div style={{ fontSize: 14, fontWeight: 600, textTransform: "capitalize" }}>{user.is_super_admin ? "Super Admin" : user.roles?.[0] ?? "User"}</div>
            </div>
            <button className="btn" disabled={saving} style={{ marginTop: 8, width: "auto", padding: "10px 24px" }}>
              {saving ? "Saving..." : "Save Changes"}
            </button>
          </form>
        </div>

        {/* Password change */}
        <div className="panel">
          <h2 style={{ marginTop: 0 }}>Change Password</h2>
          {pwdError && <div className="error">{pwdError}</div>}
          {pwdNotice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 12, padding: "8px 14px" }}>{pwdNotice}</div>}
          <form onSubmit={handlePassword}>
            {!user.is_super_admin && (
              <div className="field">
                <label>Current Password</label>
                <input type="password" value={curPwd} onChange={(e) => setCurPwd(e.target.value)} required autoComplete="current-password" />
              </div>
            )}
            <div className="field">
              <label>New Password</label>
              <input type="password" value={newPwd} onChange={(e) => setNewPwd(e.target.value)} required minLength={8} autoComplete="new-password" />
            </div>
            <div className="field">
              <label>Confirm New Password</label>
              <input type="password" value={confirmPwd} onChange={(e) => setConfirmPwd(e.target.value)} required minLength={8} autoComplete="new-password" />
            </div>
            <button className="btn" disabled={pwdSaving} style={{ marginTop: 8, width: "auto", padding: "10px 24px" }}>
              {pwdSaving ? "Changing..." : "Change Password"}
            </button>
          </form>
        </div>
      </div>
    </>
  );
}
