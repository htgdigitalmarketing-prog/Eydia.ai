"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { api, ApiError, AiMediaItem } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { PageHeader } from "@/components/PageHeader";

type Tab = "images" | "chat";
type ChatMsg = { role: "user" | "assistant"; text: string };

const CHAT_SUGGESTIONS = [
  "How do I get more Google reviews?",
  "Write a WhatsApp offer for a festive sale",
  "Ideas to follow up cold leads",
  "How to rank higher in local search?",
];

export default function AiStudioPage() {
  const [tab, setTab] = useState<Tab>("images");

  return (
    <div>
      <PageHeader
        title="AI Studio"
        subtitle="Generate marketing images and get on-demand marketing advice — powered by AI."
      />

      <div style={{ display: "flex", gap: 8, marginBottom: 16 }}>
        <button className={tab === "images" ? "btn" : "btn-mini"} style={{ width: "auto", padding: "8px 16px" }} onClick={() => setTab("images")}>
          ✨ AI Images
        </button>
        <button className={tab === "chat" ? "btn" : "btn-mini"} style={{ width: "auto", padding: "8px 16px" }} onClick={() => setTab("chat")}>
          💬 Marketing Chat
        </button>
      </div>

      {tab === "images" ? <ImagesTab /> : <ChatTab />}
    </div>
  );
}

function ImagesTab() {
  const [media, setMedia] = useState<AiMediaItem[]>([]);
  const [prompt, setPrompt] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const res = await api.aiMedia.list(token);
      setMedia(res.media);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  async function generate(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token || !prompt.trim()) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await api.aiMedia.generate(token, prompt.trim());
      setMedia((m) => [res.media, ...m]);
      setPrompt("");
      setNotice(res.source === "fallback"
        ? "AI image model isn't configured — showing a placeholder. No credits were charged."
        : `Image generated.${res.credits != null ? ` ${res.credits} credits left.` : ""}`);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setBusy(false);
    }
  }

  async function remove(id: string) {
    const token = getToken();
    if (!token) return;
    if (!confirm("Delete this image?")) return;
    try {
      await api.aiMedia.remove(token, id);
      setMedia((m) => m.filter((x) => x.id !== id));
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  return (
    <div>
      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      <div className="panel" style={{ marginBottom: 16 }}>
        <form onSubmit={generate}>
          <div className="field" style={{ marginBottom: 8 }}>
            <label>Describe the image you want <span className="muted">(5 credits)</span></label>
            <textarea
              value={prompt}
              onChange={(e) => setPrompt(e.target.value)}
              rows={3}
              maxLength={1000}
              placeholder={"e.g. A festive Diwali sale banner for a sweet shop, warm colours, diyas and sparkles, space for text"}
              style={{ width: "100%", padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14, fontFamily: "inherit", resize: "vertical" }}
            />
          </div>
          <button className="btn" style={{ width: "auto", padding: "9px 18px" }} disabled={busy || !prompt.trim()}>
            {busy ? "Generating…" : "Generate image"}
          </button>
        </form>
      </div>

      {loading ? (
        <div className="loading-block"><span className="spinner" /><span>Loading…</span></div>
      ) : media.length === 0 ? (
        <div className="panel" style={{ textAlign: "center", padding: 40 }}>
          <div style={{ fontSize: 40 }}>🖼️</div>
          <h3 style={{ margin: "12px 0 4px" }}>No images yet</h3>
          <p className="muted">Describe an image above to generate your first one.</p>
        </div>
      ) : (
        <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(220px, 1fr))", gap: 14 }}>
          {media.map((m) => (
            <div key={m.id} className="panel" style={{ padding: 10 }}>
              {m.image_data && (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={m.image_data} alt={m.prompt} style={{ width: "100%", borderRadius: 8, display: "block", aspectRatio: "1 / 1", objectFit: "cover" }} />
              )}
              <p className="muted" style={{ fontSize: 12, margin: "8px 0 0", maxHeight: 48, overflow: "hidden" }}>{m.prompt}</p>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 8 }}>
                <span className="muted" style={{ fontSize: 11 }}>{m.source === "fallback" ? "placeholder" : "AI"}</span>
                <div style={{ display: "flex", gap: 6 }}>
                  <a className="btn-mini" href={m.image_data ?? "#"} download={`ai-image-${m.id}.png`}>Download</a>
                  <button className="btn-mini" style={{ color: "#c53030" }} onClick={() => remove(m.id)}>Delete</button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function ChatTab() {
  const [msgs, setMsgs] = useState<ChatMsg[]>([
    { role: "assistant", text: "Hi! I'm your marketing assistant. Ask me anything about reviews, WhatsApp, social media, local SEO or following up leads." },
  ]);
  const [input, setInput] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const bodyRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    bodyRef.current?.scrollTo({ top: bodyRef.current.scrollHeight, behavior: "smooth" });
  }, [msgs, busy]);

  async function send(text: string) {
    const token = getToken();
    if (!token || !text.trim() || busy) return;
    setError(null);
    setInput("");
    setMsgs((m) => [...m, { role: "user", text }]);
    setBusy(true);
    try {
      const res = await api.assistant.chat(token, text);
      setMsgs((m) => [...m, { role: "assistant", text: res.answer }]);
    } catch (err) {
      setError((err as ApiError).message);
      setMsgs((m) => [...m, { role: "assistant", text: "Sorry, I couldn't answer that right now." }]);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="panel" style={{ display: "flex", flexDirection: "column", height: "60vh", minHeight: 420 }}>
      {error && <div className="error">{error}</div>}
      <div ref={bodyRef} style={{ flex: 1, overflowY: "auto", display: "flex", flexDirection: "column", gap: 10, padding: 4 }}>
        {msgs.map((m, i) => (
          <div
            key={i}
            style={{
              alignSelf: m.role === "user" ? "flex-end" : "flex-start",
              maxWidth: "80%",
              background: m.role === "user" ? "var(--accent)" : "#f1f3f4",
              color: m.role === "user" ? "white" : "inherit",
              padding: "9px 13px",
              borderRadius: 14,
              fontSize: 14,
              whiteSpace: "pre-wrap",
              lineHeight: 1.5,
            }}
          >
            {m.text}
          </div>
        ))}
        {busy && <div className="muted" style={{ fontSize: 13 }}>Thinking…</div>}
      </div>

      {msgs.length <= 1 && (
        <div style={{ display: "flex", flexWrap: "wrap", gap: 8, margin: "10px 0" }}>
          {CHAT_SUGGESTIONS.map((s) => (
            <button key={s} className="btn-mini" onClick={() => send(s)}>{s}</button>
          ))}
        </div>
      )}

      <form
        onSubmit={(e) => { e.preventDefault(); send(input); }}
        style={{ display: "flex", gap: 8, marginTop: 10, borderTop: "1px solid var(--border)", paddingTop: 10 }}
      >
        <input
          value={input}
          onChange={(e) => setInput(e.target.value)}
          placeholder="Ask about your marketing…"
          style={{ flex: 1, padding: "10px 12px", border: "1px solid var(--border)", borderRadius: 10, fontSize: 14 }}
        />
        <button className="btn" style={{ width: "auto", padding: "9px 18px" }} disabled={busy || !input.trim()}>Send</button>
      </form>
    </div>
  );
}
