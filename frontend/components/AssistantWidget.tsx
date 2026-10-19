"use client";

import { useEffect, useRef, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { getToken } from "@/lib/auth";

type Msg = { role: "user" | "assistant"; text: string };

const LANGUAGES = [
  { value: "en", label: "English" },
  { value: "hinglish", label: "Hinglish" },
  { value: "hi", label: "हिन्दी" },
  { value: "mr", label: "मराठी" },
  { value: "gu", label: "ગુજરાતી" },
  { value: "ta", label: "தமிழ்" },
  { value: "te", label: "తెలుగు" },
  { value: "bn", label: "বাংলা" },
  { value: "pa", label: "ਪੰਜਾਬੀ" },
];

const GREETING: Record<string, string> = {
  en: "Hi! Ask me anything about your data — today's messages, open chats, hot leads or an overall summary. I only ever look at your own data.",
  hinglish: "Hi! Apne data ke baare mein kuch bhi poochho — aaj ke messages, open chats, hot leads ya overall summary. Main sirf aapka hi data dekhta hoon.",
  hi: "नमस्ते! अपने डेटा के बारे में कुछ भी पूछें — आज के मैसेज, ओपन चैट, हॉट लीड्स या पूरा सारांश। मैं सिर्फ़ आपका ही डेटा देखता हूँ।",
};

const PLACEHOLDER: Record<string, string> = {
  en: "Type your question…",
  hinglish: "Apna sawaal likhein…",
  hi: "अपना सवाल लिखें…",
};

const SUGGESTIONS: Record<string, string[]> = {
  en: ["How many messages today?", "How many open conversations do I have?", "How many hot leads?", "Give me a summary of my dashboard"],
  hinglish: ["Aaj kitne messages aaye?", "Kitni open conversations hain?", "Kitne hot leads hain?", "Mere dashboard ka summary do"],
  hi: ["आज कितने मैसेज आए?", "कितनी ओपन चैट हैं?", "कितने हॉट लीड्स हैं?", "मेरे डैशबोर्ड का सारांश दें"],
};

export function AssistantWidget() {
  const [open, setOpen] = useState(false);
  const [input, setInput] = useState("");
  const [busy, setBusy] = useState(false);
  const [lang, setLang] = useState("en");
  const [msgs, setMsgs] = useState<Msg[]>([{ role: "assistant", text: GREETING.en }]);
  const bodyRef = useRef<HTMLDivElement>(null);

  const greeting = GREETING[lang] ?? GREETING.en;
  const placeholder = PLACEHOLDER[lang] ?? PLACEHOLDER.en;
  const suggestions = SUGGESTIONS[lang] ?? SUGGESTIONS.en;

  // When the user switches language before asking anything, re-greet in that language.
  function changeLang(next: string) {
    setLang(next);
    setMsgs((m) => (m.length <= 1 ? [{ role: "assistant", text: GREETING[next] ?? GREETING.en }] : m));
  }

  useEffect(() => {
    if (open) bodyRef.current?.scrollTo({ top: bodyRef.current.scrollHeight, behavior: "smooth" });
  }, [msgs, open, busy]);

  async function ask(question: string) {
    const q = question.trim();
    if (!q || busy) return;
    const token = getToken();
    if (!token) return;
    setInput("");
    setMsgs((m) => [...m, { role: "user", text: q }]);
    setBusy(true);
    try {
      const res = await api.assistant.ask(token, q, lang);
      setMsgs((m) => [...m, { role: "assistant", text: res.answer }]);
    } catch (err) {
      setMsgs((m) => [...m, { role: "assistant", text: (err as ApiError).message || "Sorry, kuch galat ho gaya. Dubara try karein." }]);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <button
        className="appbar-btn"
        title="Ask AI about your data"
        onClick={() => setOpen((v) => !v)}
        style={{ position: "relative" }}
      >
        💬
      </button>

      {open && (
        <>
          <div onClick={() => setOpen(false)} style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,.25)", zIndex: 300 }} />
          <div
            role="dialog"
            aria-label="AI assistant"
            style={{
              position: "fixed", right: 16, bottom: 16, top: 72, width: "min(400px, calc(100vw - 32px))",
              background: "var(--panel, #fff)", borderRadius: 16, boxShadow: "0 12px 48px rgba(0,0,0,.22)",
              display: "flex", flexDirection: "column", zIndex: 301, overflow: "hidden",
            }}
          >
            <div style={{ background: "linear-gradient(135deg,#3b82f6,#1e2a63)", color: "#fff", padding: "14px 16px", display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
              <div>
                <div style={{ fontWeight: 700, fontSize: 15 }}>Data Assistant</div>
                <div style={{ fontSize: 11, color: "rgba(255,255,255,.75)" }}>🔒 Only your own data</div>
              </div>
              <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                <select
                  value={lang}
                  onChange={(e) => changeLang(e.target.value)}
                  title="Answer language"
                  style={{ background: "rgba(255,255,255,.18)", color: "#fff", border: "1px solid rgba(255,255,255,.3)", borderRadius: 8, padding: "4px 6px", fontSize: 12, cursor: "pointer" }}
                >
                  {LANGUAGES.map((l) => <option key={l.value} value={l.value} style={{ color: "#1f2a2a" }}>{l.label}</option>)}
                </select>
                <button onClick={() => setOpen(false)} style={{ background: "rgba(255,255,255,.18)", color: "#fff", border: "none", borderRadius: 8, width: 28, height: 28, cursor: "pointer", fontSize: 16 }}>×</button>
              </div>
            </div>

            <div ref={bodyRef} style={{ flex: 1, overflowY: "auto", padding: 14, display: "flex", flexDirection: "column", gap: 10, background: "var(--bg, #f6f7f8)" }}>
              {msgs.map((m, i) => (
                <div key={i} style={{ alignSelf: m.role === "user" ? "flex-end" : "flex-start", maxWidth: "85%" }}>
                  <div style={{
                    padding: "9px 13px", borderRadius: 14, fontSize: 14, lineHeight: 1.45, whiteSpace: "pre-wrap", wordBreak: "break-word",
                    background: m.role === "user" ? "#5b6bf5" : "#fff",
                    color: m.role === "user" ? "#fff" : "#1f2a2a",
                    border: m.role === "user" ? "none" : "1px solid #e3edeb",
                    borderBottomRightRadius: m.role === "user" ? 4 : 14,
                    borderBottomLeftRadius: m.role === "user" ? 14 : 4,
                  }}>
                    {m.text}
                  </div>
                </div>
              ))}
              {busy && (
                <div style={{ alignSelf: "flex-start", fontSize: 13, color: "#667781", padding: "6px 4px" }}>Soch raha hoon…</div>
              )}

              {msgs.length <= 1 && !busy && (
                <div style={{ display: "flex", flexWrap: "wrap", gap: 6, marginTop: 4 }}>
                  {suggestions.map((s) => (
                    <button key={s} onClick={() => ask(s)} style={{
                      background: "#e9f2fd", color: "#0a68c4", border: "1px solid #cfe1fb", borderRadius: 999,
                      padding: "5px 11px", fontSize: 12, cursor: "pointer",
                    }}>{s}</button>
                  ))}
                </div>
              )}
            </div>

            <form
              onSubmit={(e) => { e.preventDefault(); ask(input); }}
              style={{ display: "flex", gap: 8, padding: 12, borderTop: "1px solid #e3edeb", background: "var(--panel,#fff)" }}
            >
              <input
                value={input}
                onChange={(e) => setInput(e.target.value)}
                placeholder={placeholder}
                style={{ flex: 1, padding: "10px 12px", border: "1px solid var(--border,#d7e3e0)", borderRadius: 10, fontSize: 14 }}
              />
              <button type="submit" disabled={busy || !input.trim()} className="btn" style={{ width: "auto", padding: "0 18px" }}>Send</button>
            </form>
          </div>
        </>
      )}
    </>
  );
}
