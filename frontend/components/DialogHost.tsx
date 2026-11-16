"use client";

import { useEffect, useState } from "react";
import type { ConfirmOptions, ToastType } from "@/lib/ui";

type Toast = { id: number; message: string; type: ToastType };
type Confirm = ConfirmOptions & { resolve: (ok: boolean) => void };

const TOAST_STYLE: Record<ToastType, { bg: string; border: string; color: string; icon: string }> = {
  success: { bg: "#e7f7ef", border: "#b6e6cd", color: "#0a7d47", icon: "✓" },
  error: { bg: "#fdecec", border: "#f6cccc", color: "#c53030", icon: "✕" },
  info: { bg: "#eef0ff", border: "#dde1ff", color: "#3b3bb5", icon: "ℹ" },
};

/** Mounted once in the app shell. Renders toasts + the confirm modal. */
export function DialogHost() {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const [confirm, setConfirm] = useState<Confirm | null>(null);

  useEffect(() => {
    let seq = 0;
    const onToast = (e: Event) => {
      const d = (e as CustomEvent).detail as { message: string; type: ToastType };
      const id = ++seq;
      setToasts((t) => [...t, { id, message: d.message, type: d.type || "info" }]);
      setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 4200);
    };
    const onConfirm = (e: Event) => setConfirm((e as CustomEvent).detail as Confirm);
    window.addEventListener("eydia:toast", onToast);
    window.addEventListener("eydia:confirm", onConfirm);
    return () => {
      window.removeEventListener("eydia:toast", onToast);
      window.removeEventListener("eydia:confirm", onConfirm);
    };
  }, []);

  function answer(ok: boolean) {
    confirm?.resolve(ok);
    setConfirm(null);
  }

  return (
    <>
      {/* Toasts */}
      <div style={{ position: "fixed", top: 16, right: 16, zIndex: 4000, display: "flex", flexDirection: "column", gap: 10, maxWidth: "min(92vw, 380px)" }}>
        {toasts.map((t) => {
          const s = TOAST_STYLE[t.type];
          return (
            <div key={t.id} onClick={() => setToasts((x) => x.filter((y) => y.id !== t.id))}
              style={{
                background: s.bg, border: `1px solid ${s.border}`, color: s.color, borderRadius: 12,
                padding: "12px 14px", display: "flex", gap: 10, alignItems: "flex-start", cursor: "pointer",
                boxShadow: "0 10px 30px rgba(15,20,40,.14)", fontSize: 14, animation: "eydiaToastIn .22s ease",
              }}>
              <span style={{ fontWeight: 900, lineHeight: 1.4 }}>{s.icon}</span>
              <span style={{ lineHeight: 1.45 }}>{t.message}</span>
            </div>
          );
        })}
      </div>

      {/* Confirm modal */}
      {confirm && (
        <div onClick={() => answer(false)}
          style={{ position: "fixed", inset: 0, zIndex: 4100, background: "rgba(15,20,40,.45)", backdropFilter: "blur(2px)", display: "flex", alignItems: "center", justifyContent: "center", padding: 20, animation: "eydiaFade .15s ease" }}>
          <div onClick={(e) => e.stopPropagation()}
            style={{ background: "var(--card, #fff)", borderRadius: 18, maxWidth: 420, width: "100%", padding: "26px 24px", boxShadow: "0 24px 70px rgba(0,0,0,.3)", animation: "eydiaPop .18s cubic-bezier(.2,.9,.3,1.2)" }}>
            <div style={{ fontSize: 34, marginBottom: 6 }}>{confirm.danger ? "🗑️" : "❓"}</div>
            <h2 style={{ margin: "0 0 6px", fontSize: 19 }}>{confirm.title || (confirm.danger ? "Are you sure?" : "Please confirm")}</h2>
            <p style={{ margin: "0 0 20px", color: "var(--muted, #667781)", lineHeight: 1.5, fontSize: 14.5 }}>{confirm.message}</p>
            <div style={{ display: "flex", gap: 10, justifyContent: "flex-end", flexWrap: "wrap" }}>
              <button onClick={() => answer(false)}
                style={{ padding: "10px 18px", borderRadius: 10, border: "1px solid var(--border, #e3e6f0)", background: "#fff", color: "var(--text, #1f2430)", fontWeight: 600, cursor: "pointer" }}>
                {confirm.cancelText || "Cancel"}
              </button>
              <button onClick={() => answer(true)} autoFocus
                style={{ padding: "10px 20px", borderRadius: 10, border: "none", color: "#fff", fontWeight: 700, cursor: "pointer", background: confirm.danger ? "var(--danger, #c53030)" : "var(--accent, #5b6bf5)" }}>
                {confirm.confirmText || (confirm.danger ? "Delete" : "Confirm")}
              </button>
            </div>
          </div>
        </div>
      )}

      <style>{`
        @keyframes eydiaToastIn{from{opacity:0;transform:translateX(14px)}to{opacity:1;transform:none}}
        @keyframes eydiaFade{from{opacity:0}to{opacity:1}}
        @keyframes eydiaPop{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:none}}
      `}</style>
    </>
  );
}
