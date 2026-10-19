"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { api, ApiError, Conversation, InboxMessage, TemplateItem } from "@/lib/api";
import { getToken } from "@/lib/auth";

function timeAgo(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 60) return "now";
  if (diff < 3600) return `${Math.floor(diff / 60)}m`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

function formatTime(iso: string | null): string {
  if (!iso) return "";
  return new Date(iso).toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
}

function StatusIcon({ status }: { status: string }) {
  if (status === "read") return <span className="status-checks status-read">✓✓</span>;
  if (status === "delivered") return <span className="status-checks">✓✓</span>;
  if (status === "sent") return <span className="status-checks">✓</span>;
  if (status === "failed") return <span className="status-checks" style={{ color: "var(--danger)" }} title="Delivery failed">⚠</span>;
  return null;
}

/** Turn Meta's delivery error into a short, plain-English reason the user can act on. */
function failureReason(code: string | null, message: string | null): string {
  switch (String(code ?? "")) {
    case "131026":
    case "133010":
      return "This number is not on WhatsApp (or can't receive messages).";
    case "131049":
    case "130472":
      return "Meta didn't deliver this marketing message (per-user frequency cap). Try a utility template or message people who opted in.";
    case "131047":
      return "More than 24h since the customer last replied — only a template can reach them now.";
    case "131048":
      return "Blocked: this number has hit its spam/quality limit. Slow down and send only to people who opted in.";
    case "131031":
    case "368":
      return "Your WhatsApp number is restricted by Meta (quality/policy). Check WhatsApp Manager.";
    case "132000":
    case "132001":
    case "132005":
    case "132007":
    case "132012":
      return "Template problem (not approved, paused, or wrong variables). Check the template in Templates.";
    case "131021":
      return "Can't send — this is the business's own number.";
    default:
      return message || "Meta couldn't deliver this message.";
  }
}

function formatFileSize(bytes: number | null | undefined): string {
  if (!bytes) return "";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1048576).toFixed(1)} MB`;
}

function detectMediaType(file: File): string {
  if (file.type.startsWith("image/")) return "image";
  if (file.type.startsWith("video/")) return "video";
  if (file.type.startsWith("audio/")) return "audio";
  return "document";
}

function formatDuration(sec: number): string {
  const m = Math.floor(sec / 60);
  const s = Math.floor(sec % 60);
  return `${m}:${s.toString().padStart(2, "0")}`;
}

const ATTACH_OPTIONS = [
  { key: "document", label: "Document", icon: "📄", color: "#7f66ff", accept: ".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.csv", enabled: true },
  { key: "photos", label: "Photos & videos", icon: "🖼️", color: "#007bfc", accept: "image/*,video/*", enabled: true },
  { key: "camera", label: "Camera", icon: "📸", color: "#ff2e74", accept: "image/*", enabled: true, capture: true },
  { key: "audio", label: "Audio", icon: "🎧", color: "#ee6723", accept: "audio/*,.mp3,.aac,.ogg,.opus,.amr", enabled: true },
  { key: "contact", label: "Contact", icon: "👤", color: "#0795dc", accept: "", enabled: false },
  { key: "poll", label: "Poll", icon: "📊", color: "#02a698", accept: "", enabled: false },
  { key: "event", label: "Event", icon: "📅", color: "#ea4e3d", accept: "", enabled: false },
  { key: "sticker", label: "New sticker", icon: "😃", color: "#02a698", accept: "", enabled: false },
] as const;

const FILTERS = ["all", "open", "hot"] as const;

function MediaBubble({ msg }: { msg: InboxMessage }) {
  const att = msg.attachments?.[0];
  if (!att) return <div>[{msg.type}]</div>;

  if (att.type === "image") {
    return (
      <div className="bubble-media">
        {att.url
          ? <a href={att.url} target="_blank" rel="noopener noreferrer" title="Open full size"><img src={att.url} alt={att.caption || "Image"} loading="lazy" /></a>
          : <div style={{ padding: "10px 12px", fontSize: 12, color: "var(--muted)" }}>🖼️ Image unavailable</div>}
        {att.caption && <div className="media-caption">{att.caption}</div>}
      </div>
    );
  }

  if (att.type === "video") {
    return (
      <div className="bubble-media">
        {att.url
          ? <video src={att.url} controls playsInline preload="metadata" />
          : <div style={{ padding: "10px 12px", fontSize: 12, color: "var(--muted)" }}>🎬 Video unavailable</div>}
        {att.caption && <div className="media-caption">{att.caption}</div>}
      </div>
    );
  }

  if (att.type === "audio") {
    return (
      <div className="bubble-media">
        {att.url && <audio src={att.url} controls preload="metadata" />}
      </div>
    );
  }

  return (
    <div className="bubble-doc">
      <div className="bubble-doc-icon">📄</div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="bubble-doc-name">{att.file_name || "Document"}</div>
        <div style={{ fontSize: 11, color: "var(--muted)", marginTop: 2 }}>
          {att.mime_type} {att.file_size ? `· ${formatFileSize(att.file_size)}` : ""}
        </div>
        {att.caption && <div className="media-caption">{att.caption}</div>}
      </div>
      {att.url && (
        <a href={att.url} target="_blank" rel="noopener noreferrer" style={{ marginLeft: "auto", padding: "6px 10px", background: "var(--primary)", color: "#fff", borderRadius: 8, fontSize: 12, textDecoration: "none", fontWeight: 600, whiteSpace: "nowrap" }}>
          Download
        </a>
      )}
    </div>
  );
}

type MsgAction = { msgId: string; x: number; y: number };

export default function InboxPage() {
  const searchParams = useSearchParams();
  const autoSelectWaId = useRef(searchParams.get("contact"));

  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [messages, setMessages] = useState<InboxMessage[]>([]);
  const [loading, setLoading] = useState(true);
  const [msgLoading, setMsgLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [listError, setListError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState<string>("all");
  const [agentFilter, setAgentFilter] = useState<string>("");
  const [stickyOnly, setStickyOnly] = useState(false);
  const [hotOnly, setHotOnly] = useState(false);
  const [sticky, setSticky] = useState<Set<string>>(new Set());

  // Chat transfer / assignment
  const [assignableAgents, setAssignableAgents] = useState<{ id: string; name: string; is_me: boolean }[]>([]);
  const [assignOpen, setAssignOpen] = useState(false);
  const [assigning, setAssigning] = useState(false);
  const [text, setText] = useState("");
  const [sending, setSending] = useState(false);
  const [showAttach, setShowAttach] = useState(false);
  const [mediaFile, setMediaFile] = useState<File | null>(null);
  const [mediaType, setMediaType] = useState<string>("");
  const [mediaPreview, setMediaPreview] = useState<string | null>(null);
  const [caption, setCaption] = useState("");
  const [recording, setRecording] = useState(false);
  const [recordingTime, setRecordingTime] = useState(0);
  const [toast, setToast] = useState<string | null>(null);

  // Reopen-with-template (WhatsApp 24h window)
  const [templates, setTemplates] = useState<TemplateItem[]>([]);
  const [tplName, setTplName] = useState("");
  const [tplLang, setTplLang] = useState("en");
  const [sendingTpl, setSendingTpl] = useState(false);

  // Message actions
  const [actionMenu, setActionMenu] = useState<MsgAction | null>(null);
  const [replyTo, setReplyTo] = useState<InboxMessage | null>(null);
  const [msgInfo, setMsgInfo] = useState<InboxMessage | null>(null);

  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const msgPollRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const cameraRef = useRef<HTMLInputElement>(null);
  const attachMenuRef = useRef<HTMLDivElement>(null);
  const actionMenuRef = useRef<HTMLDivElement>(null);
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const audioChunksRef = useRef<Blob[]>([]);
  const recordingTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const streamRef = useRef<MediaStream | null>(null);

  const showToast = useCallback((msg: string) => {
    setToast(msg);
    setTimeout(() => setToast(null), 3000);
  }, []);

  // Sticky (pinned) chats persist per browser.
  useEffect(() => {
    try {
      const raw = localStorage.getItem("inbox_sticky");
      if (raw) setSticky(new Set(JSON.parse(raw)));
    } catch {}
  }, []);

  const toggleSticky = useCallback((id: string, e: React.MouseEvent) => {
    e.stopPropagation();
    setSticky((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      try { localStorage.setItem("inbox_sticky", JSON.stringify([...next])); } catch {}
      return next;
    });
  }, []);

  const loadConversations = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    try {
      const res = await api.inbox.conversations(token, {
        status: filter === "all" || filter === "hot" ? undefined : filter,
        search: search || undefined,
        per_page: 500,
      });
      setConversations(res.conversations);
      setListError(null);
    } catch (err) {
      setListError((err as ApiError).message || "Could not load conversations.");
    } finally {
      setLoading(false);
    }
  }, [filter, search]);

  useEffect(() => {
    setLoading(true);
    loadConversations().then(() => {
      if (autoSelectWaId.current) {
        const waId = autoSelectWaId.current;
        autoSelectWaId.current = null;
        setConversations((prev) => {
          const match = prev.find((c) => c.contact?.wa_id === waId || c.contact?.phone === waId);
          if (match) setTimeout(() => selectConversation(match), 0);
          return prev;
        });
      }
    });
    pollRef.current = setInterval(loadConversations, 15000);
    return () => { if (pollRef.current) clearInterval(pollRef.current); };
  }, [loadConversations]);

  // Load the team roster once — used by the chat-transfer picker.
  useEffect(() => {
    const token = getToken();
    if (!token) return;
    api.agents.assignable(token).then((r) => setAssignableAgents(r.agents)).catch(() => {});
  }, []);

  // Load approved templates once — used to re-open a closed 24h window.
  useEffect(() => {
    const token = getToken();
    if (!token) return;
    api.templates.list(token, { status: "APPROVED" })
      .then((r) => {
        setTemplates(r.templates);
        if (r.templates[0]) { setTplName(r.templates[0].name); setTplLang(r.templates[0].language || "en"); }
      })
      .catch(() => {});
  }, []);

  const loadMessages = useCallback(async (convId: string) => {
    const token = getToken();
    if (!token) return;
    setMsgLoading(true);
    try {
      const res = await api.inbox.messages(token, convId);
      setMessages(res.messages);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setMsgLoading(false);
    }
  }, []);

  const selectConversation = useCallback(async (conv: Conversation) => {
    setActiveId(conv.id);
    setMessages([]);
    setText("");
    setCaption("");
    setMediaFile(null);
    setMediaPreview(null);
    setError(null);
    setShowAttach(false);
    setReplyTo(null);
    setActionMenu(null);
    setMsgInfo(null);
    stopRecording();
    loadMessages(conv.id);

    if (conv.unread_count > 0) {
      const token = getToken();
      if (token) {
        api.inbox.markRead(token, conv.id).catch(() => {});
        setConversations((prev) => prev.map((c) => c.id === conv.id ? { ...c, unread_count: 0 } : c));
      }
    }

    if (msgPollRef.current) clearInterval(msgPollRef.current);
    msgPollRef.current = setInterval(() => loadMessages(conv.id), 8000);
  }, [loadMessages]);

  useEffect(() => {
    return () => {
      if (msgPollRef.current) clearInterval(msgPollRef.current);
      if (recordingTimerRef.current) clearInterval(recordingTimerRef.current);
      if (streamRef.current) streamRef.current.getTracks().forEach((t) => t.stop());
    };
  }, []);

  // Close menus on outside click
  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (attachMenuRef.current && !attachMenuRef.current.contains(e.target as Node)) {
        setShowAttach(false);
      }
      if (actionMenuRef.current && !actionMenuRef.current.contains(e.target as Node)) {
        setActionMenu(null);
      }
    }
    if (showAttach || actionMenu) document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, [showAttach, actionMenu]);

  // --- Message Actions ---
  function openActionMenu(e: React.MouseEvent, msg: InboxMessage) {
    e.preventDefault();
    e.stopPropagation();
    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
    setActionMenu({ msgId: msg.id, x: rect.right - 120, y: rect.top - 4 });
  }

  function getActionMsg(): InboxMessage | undefined {
    return messages.find((m) => m.id === actionMenu?.msgId);
  }

  async function copyMessage() {
    const msg = getActionMsg();
    if (!msg) return;
    const text = msg.body || msg.attachments?.[0]?.caption || "";
    if (text) {
      try {
        await navigator.clipboard.writeText(text);
        showToast("Copied to clipboard");
      } catch {
        showToast("Copy failed");
      }
    }
    setActionMenu(null);
  }

  function replyMessage() {
    const msg = getActionMsg();
    if (msg) setReplyTo(msg);
    setActionMenu(null);
  }

  function showMessageInfo() {
    const msg = getActionMsg();
    if (msg) setMsgInfo(msg);
    setActionMenu(null);
  }

  async function deleteMessage() {
    const msg = getActionMsg();
    const token = getToken();
    if (!msg || !token || !activeId) return;
    setActionMenu(null);
    try {
      await api.inbox.deleteMessage(token, activeId, msg.id);
      setMessages((prev) => prev.filter((m) => m.id !== msg.id));
      showToast("Message deleted");
    } catch (err) {
      setError((err as ApiError).message);
    }
  }

  function forwardMessage() {
    showToast("Forward — coming soon");
    setActionMenu(null);
  }

  function starMessage() {
    showToast("Star — coming soon");
    setActionMenu(null);
  }

  // --- Send ---
  async function sendMessage() {
    const token = getToken();
    if (!token || !activeId || !text.trim()) return;
    setSending(true);
    setError(null);
    try {
      const res = await api.inbox.send(token, activeId, { type: "text", body: text.trim() });
      setMessages((prev) => [res.message, ...prev]);
      setText("");
      setReplyTo(null);
      loadConversations();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSending(false);
    }
  }

  // Send an approved template to re-open a chat whose 24h window has closed.
  async function sendReopenTemplate() {
    const token = getToken();
    if (!token || !activeId || !tplName) return;
    setSendingTpl(true);
    setError(null);
    try {
      const res = await api.inbox.send(token, activeId, { type: "template", template: tplName, language: tplLang });
      setMessages((prev) => [res.message, ...prev]);
      setTplName("");
      // Reload so the conversation's window flips back to open.
      await Promise.all([loadConversations(), activeId ? loadMessages(activeId) : Promise.resolve()]);
      setToast("Template sent — the 24-hour window is open again. You can reply freely now.");
      setTimeout(() => setToast(null), 5000);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSendingTpl(false);
    }
  }

  // Transfer / assign the active chat to a teammate (or unassign).
  async function transferChat(agentId: string | null) {
    const token = getToken();
    if (!token || !activeId) return;
    setAssigning(true);
    setError(null);
    try {
      if (agentId) await api.agents.assign(token, { conversation_id: activeId, agent_id: agentId });
      else await api.agents.unassign(token, { conversation_id: activeId });
      setAssignOpen(false);
      await loadConversations();
      if (activeId) await loadMessages(activeId);
      showToast(agentId ? "Chat transferred." : "Chat unassigned.");
    } catch (err) {
      const e2 = err as ApiError;
      setAssignOpen(false);
      showToast(e2.message || "Failed to assign agent. Check permissions.");
    } finally {
      setAssigning(false);
    }
  }

  async function sendMediaMessage() {
    const token = getToken();
    if (!token || !activeId || !mediaFile) return;
    setSending(true);
    setError(null);
    try {
      const res = await api.inbox.sendMedia(token, activeId, mediaFile, mediaType, caption || undefined);
      setMessages((prev) => [res.message, ...prev]);
      clearMedia();
      setReplyTo(null);
      loadConversations();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSending(false);
    }
  }

  function clearMedia() {
    setMediaFile(null);
    setMediaType("");
    setMediaPreview(null);
    setCaption("");
    if (fileRef.current) fileRef.current.value = "";
    if (cameraRef.current) cameraRef.current.value = "";
  }

  function handleFileSelect(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    const type = detectMediaType(file);
    setMediaFile(file);
    setMediaType(type);
    setShowAttach(false);

    if (type === "image") {
      const reader = new FileReader();
      reader.onload = (ev) => setMediaPreview(ev.target?.result as string);
      reader.readAsDataURL(file);
    } else if (type === "video") {
      setMediaPreview("video");
    } else {
      setMediaPreview(null);
    }
  }

  function openFileFor(accept: string) {
    if (fileRef.current) {
      fileRef.current.accept = accept;
      fileRef.current.removeAttribute("capture");
      fileRef.current.click();
    }
  }

  function openCamera() {
    if (cameraRef.current) cameraRef.current.click();
    setShowAttach(false);
  }

  function handleAttachClick(opt: typeof ATTACH_OPTIONS[number]) {
    if (!opt.enabled) {
      showToast(`${opt.label} — coming soon`);
      setShowAttach(false);
      return;
    }
    if (opt.key === "camera") {
      openCamera();
    } else {
      openFileFor(opt.accept);
    }
  }

  // Voice recording
  async function startRecording() {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      streamRef.current = stream;
      const recorder = new MediaRecorder(stream, { mimeType: "audio/webm;codecs=opus" });
      mediaRecorderRef.current = recorder;
      audioChunksRef.current = [];

      recorder.ondataavailable = (e) => {
        if (e.data.size > 0) audioChunksRef.current.push(e.data);
      };

      recorder.onstop = () => {
        const blob = new Blob(audioChunksRef.current, { type: "audio/ogg" });
        const file = new File([blob], `voice_${Date.now()}.ogg`, { type: "audio/ogg" });
        setMediaFile(file);
        setMediaType("audio");
        setMediaPreview("audio-recording");
        stream.getTracks().forEach((t) => t.stop());
        streamRef.current = null;
        if (recordingTimerRef.current) clearInterval(recordingTimerRef.current);
        setRecording(false);
        setRecordingTime(0);
      };

      recorder.start(250);
      setRecording(true);
      setRecordingTime(0);
      recordingTimerRef.current = setInterval(() => setRecordingTime((t) => t + 1), 1000);
    } catch {
      showToast("Microphone access denied");
    }
  }

  function stopRecording() {
    if (mediaRecorderRef.current && mediaRecorderRef.current.state !== "inactive") {
      mediaRecorderRef.current.stop();
    }
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    }
    if (recordingTimerRef.current) {
      clearInterval(recordingTimerRef.current);
      recordingTimerRef.current = null;
    }
    setRecording(false);
    setRecordingTime(0);
  }

  function cancelRecording() {
    if (mediaRecorderRef.current && mediaRecorderRef.current.state !== "inactive") {
      mediaRecorderRef.current.ondataavailable = null;
      mediaRecorderRef.current.onstop = null;
      mediaRecorderRef.current.stop();
    }
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    }
    if (recordingTimerRef.current) {
      clearInterval(recordingTimerRef.current);
      recordingTimerRef.current = null;
    }
    setRecording(false);
    setRecordingTime(0);
    audioChunksRef.current = [];
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      if (mediaFile) {
        sendMediaMessage();
      } else {
        sendMessage();
      }
    }
  }

  const activeConv = conversations.find((c) => c.id === activeId);
  const isMediaMessage = (type: string) => ["image", "video", "audio", "document"].includes(type);

  const agentNames = Array.from(new Set(conversations.map((c) => c.assigned_agent?.name).filter(Boolean))) as string[];

  // 0 = not hot; otherwise the AI's priority score (1-100).
  const hotScore = (c: Conversation) => (c.contact?.is_hot ? (c.contact.hot_score ?? 50) : 0);

  const displayedConversations = conversations
    .filter((c) => !agentFilter || c.assigned_agent?.name === agentFilter)
    .filter((c) => !stickyOnly || sticky.has(c.id))
    .filter((c) => !hotOnly && filter !== "hot" || c.contact?.is_hot)
    .slice()
    .sort((a, b) => {
      const stickyDiff = (sticky.has(b.id) ? 1 : 0) - (sticky.has(a.id) ? 1 : 0);
      if (stickyDiff !== 0) return stickyDiff;
      const tA = a.last_message_at ? new Date(a.last_message_at).getTime() : 0;
      const tB = b.last_message_at ? new Date(b.last_message_at).getTime() : 0;
      return tB - tA;
    });

  // Chats whose last activity was today (approx. "active today").
  const todayCount = conversations.filter((c) => {
    if (!c.last_message_at) return false;
    const d = new Date(c.last_message_at);
    const now = new Date();
    return d.toDateString() === now.toDateString();
  }).length;

  function downloadChatReport() {
    const esc = (v: string) => (/[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);
    const headers = ["Contact", "Phone", "Status", "Unread", "Agent", "Last message", "Last activity"];
    const rows = displayedConversations.map((c) => [
      c.contact?.name || "Unknown",
      c.contact?.wa_id ? `+${c.contact.wa_id}` : (c.contact?.phone || ""),
      c.status,
      String(c.unread_count),
      c.assigned_agent?.name || "",
      c.last_message_preview || "",
      c.last_message_at ? new Date(c.last_message_at).toLocaleString("en-IN") : "",
    ]);
    const csv = [headers, ...rows].map((r) => r.map(esc).join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `chat_report_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  return (
    <div className={`inbox-page ${activeConv ? "chat-open" : ""}`}>
      {/* Live chat toolbar */}
      <div className="inbox-toolbar" style={{
        display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap",
        padding: "10px 16px", border: "1px solid var(--border)", borderBottom: "none",
        borderRadius: "var(--radius) var(--radius) 0 0", background: "var(--card)",
      }}>
        <div style={{ fontWeight: 700, color: "#1a7f64", fontSize: 15, whiteSpace: "nowrap" }}>WhatsApp Live Chat</div>
        <div style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 12, color: "#54656f" }}>
          <span style={{ background: "#e7f7ef", color: "#0a7d47", padding: "3px 10px", borderRadius: 999, fontWeight: 600 }}>Active today: {todayCount}</span>
          <span style={{ background: "#eef1f2", color: "#54656f", padding: "3px 10px", borderRadius: 999, fontWeight: 600 }}>Total: {conversations.length}</span>
        </div>
        <div style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
          {agentNames.length > 0 && (
            <select value={agentFilter} onChange={(e) => setAgentFilter(e.target.value)}
              style={{ padding: "7px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13, background: "#fff" }}>
              <option value="">All agents</option>
              {agentNames.map((a) => <option key={a} value={a}>{a}</option>)}
            </select>
          )}
          <label style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 13, cursor: "pointer", whiteSpace: "nowrap", color: "#54656f" }}>
            <input type="checkbox" checked={stickyOnly} onChange={(e) => setStickyOnly(e.target.checked)} />
            📌 Sticky chats
          </label>
          <label style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 13, cursor: "pointer", whiteSpace: "nowrap", color: "#54656f" }}>
            <input type="checkbox" checked={hotOnly} onChange={(e) => setHotOnly(e.target.checked)} />
            🔥 Hot leads
          </label>
          <button className="btn-mini" onClick={downloadChatReport}>⬇ Download report</button>
        </div>
      </div>

      <div className={`inbox-layout ${activeConv ? "chat-open" : ""}`} style={{ height: "auto", flex: 1, minHeight: 0, borderRadius: "0 0 var(--radius) var(--radius)" }}>
      {/* Toast */}
      {toast && (
        <div style={{
          position: "fixed", bottom: 80, left: "50%", transform: "translateX(-50%)",
          background: "#333", color: "#fff", padding: "10px 20px", borderRadius: 22,
          fontSize: 13, zIndex: 999, boxShadow: "0 4px 12px rgba(0,0,0,.2)",
          animation: "attachPop .15s ease-out",
        }}>
          {toast}
        </div>
      )}

      {/* Message action context menu */}
      {actionMenu && (
        <div
          ref={actionMenuRef}
          className="msg-action-menu"
          style={{ top: actionMenu.y, left: actionMenu.x }}
        >
          <div className="msg-action-item" onClick={replyMessage}>
            <span className="msg-action-icon">↩</span> Reply
          </div>
          <div className="msg-action-item" onClick={copyMessage}>
            <span className="msg-action-icon">📋</span> Copy
          </div>
          <div className="msg-action-item" onClick={forwardMessage}>
            <span className="msg-action-icon">↪</span> Forward
          </div>
          <div className="msg-action-item" onClick={starMessage}>
            <span className="msg-action-icon">⭐</span> Star
          </div>
          <div className="msg-action-item" onClick={showMessageInfo}>
            <span className="msg-action-icon">ℹ</span> Info
          </div>
          <div className="msg-action-divider" />
          <div className="msg-action-item msg-action-danger" onClick={deleteMessage}>
            <span className="msg-action-icon">🗑</span> Delete
          </div>
        </div>
      )}

      {/* Message info panel */}
      {msgInfo && (
        <div className="msg-info-overlay" onClick={() => setMsgInfo(null)}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12 }}>
              <h3 style={{ margin: 0, fontSize: 15 }}>Message info</h3>
              <button className="btn-mini" onClick={() => setMsgInfo(null)}>✕</button>
            </div>
            <div style={{ padding: "10px 0", borderBottom: "1px solid var(--border)" }}>
              <div style={{ fontSize: 13, color: "var(--muted)", marginBottom: 4 }}>Message</div>
              <div style={{ fontSize: 14 }}>{msgInfo.body || `[${msgInfo.type}]`}</div>
            </div>
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12, padding: "12px 0" }}>
              <div>
                <div style={{ fontSize: 11, color: "var(--muted)", textTransform: "uppercase", marginBottom: 2 }}>Status</div>
                <div style={{ fontSize: 13, fontWeight: 600 }}>{msgInfo.status}</div>
              </div>
              <div>
                <div style={{ fontSize: 11, color: "var(--muted)", textTransform: "uppercase", marginBottom: 2 }}>Direction</div>
                <div style={{ fontSize: 13, fontWeight: 600 }}>{msgInfo.direction}</div>
              </div>
              <div>
                <div style={{ fontSize: 11, color: "var(--muted)", textTransform: "uppercase", marginBottom: 2 }}>Sent</div>
                <div style={{ fontSize: 13 }}>{msgInfo.sent_at ? new Date(msgInfo.sent_at).toLocaleString("en-IN") : "—"}</div>
              </div>
              <div>
                <div style={{ fontSize: 11, color: "var(--muted)", textTransform: "uppercase", marginBottom: 2 }}>Delivered</div>
                <div style={{ fontSize: 13 }}>{msgInfo.delivered_at ? new Date(msgInfo.delivered_at).toLocaleString("en-IN") : "—"}</div>
              </div>
              <div>
                <div style={{ fontSize: 11, color: "var(--muted)", textTransform: "uppercase", marginBottom: 2 }}>Read</div>
                <div style={{ fontSize: 13 }}>{msgInfo.read_at ? new Date(msgInfo.read_at).toLocaleString("en-IN") : "—"}</div>
              </div>
              <div>
                <div style={{ fontSize: 11, color: "var(--muted)", textTransform: "uppercase", marginBottom: 2 }}>Type</div>
                <div style={{ fontSize: 13 }}>{msgInfo.type}</div>
              </div>
            </div>
            {msgInfo.error_message && (
              <div style={{ background: "#fdecec", padding: "8px 10px", borderRadius: 8, fontSize: 12, color: "#c53030", marginTop: 4 }}>
                Error: {msgInfo.error_message} {msgInfo.error_code ? `(${msgInfo.error_code})` : ""}
              </div>
            )}
          </div>
        </div>
      )}

      {/* Left: Conversation list */}
      <div className="convo-list">
        <div className="convo-list-header">
          <input
            placeholder="Search contacts..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <div className="filter-tabs">
          {FILTERS.map((f) => (
            <div
              key={f}
              className={`filter-tab ${filter === f ? "active" : ""}`}
              onClick={() => setFilter(f)}
            >
              {f === "hot" ? "🔥 Hot" : f.charAt(0).toUpperCase() + f.slice(1)}
            </div>
          ))}
        </div>
        <div className="convo-list-items">
          {loading ? (
            <div style={{ padding: 20, color: "var(--muted)", textAlign: "center" }}>Loading...</div>
          ) : listError && conversations.length === 0 ? (
            <div className="error" style={{ margin: 12, borderRadius: 8 }}>
              Couldn&apos;t load conversations: {listError}
            </div>
          ) : displayedConversations.length === 0 ? (
            <div style={{ padding: 20, color: "var(--muted)", textAlign: "center" }}>
              {hotOnly ? "No hot leads yet. Contacts who show interest appear here." : stickyOnly ? "No sticky chats yet. Tap the 📌 on a chat to pin it." : agentFilter ? "No chats for this agent." : "No conversations yet. Send a message from the WhatsApp page, or wait for incoming messages after configuring webhooks."}
            </div>
          ) : (
            displayedConversations.map((conv) => (
              <div
                key={conv.id}
                className={`convo-item ${activeId === conv.id ? "active" : ""}`}
                onClick={() => selectConversation(conv)}
              >
                <div className="convo-avatar">
                  {(conv.contact?.name?.[0] ?? conv.contact?.wa_id?.[0] ?? "?").toUpperCase()}
                </div>
                <div className="convo-info">
                  <div className="convo-name">
                    <span>
                      {conv.contact?.is_hot && (
                        <span
                          title={`${conv.contact.hot_reason ?? "Hot lead"} · Priority ${hotScore(conv)}/100`}
                          style={{
                            marginRight: 6, padding: "1px 7px", borderRadius: 999, fontSize: 11, fontWeight: 700,
                            background: hotScore(conv) >= 80 ? "#fde8e8" : "#fff1e0",
                            color: hotScore(conv) >= 80 ? "#c62828" : "#c2620a",
                          }}
                        >🔥 {hotScore(conv)}</span>
                      )}
                      {conv.contact?.name || conv.contact?.phone || conv.contact?.wa_id || "Unknown"}
                    </span>
                    <span style={{ display: "flex", alignItems: "center", gap: 6 }}>
                      <button
                        onClick={(e) => toggleSticky(conv.id, e)}
                        title={sticky.has(conv.id) ? "Unpin" : "Pin chat"}
                        style={{ border: "none", background: "none", cursor: "pointer", fontSize: 13, lineHeight: 1, padding: 0, opacity: sticky.has(conv.id) ? 1 : 0.35 }}
                      >📌</button>
                      <span className="time">{timeAgo(conv.last_message_at)}</span>
                    </span>
                  </div>
                  <div className="convo-preview">
                    <span>{conv.last_message_preview || "No messages"}</span>
                    {conv.unread_count > 0 && <span className="unread-badge">{conv.unread_count}</span>}
                  </div>
                </div>
              </div>
            ))
          )}
        </div>
      </div>

      {/* Right: Chat area */}
      <div className="chat-area">
        {!activeConv ? (
          <div className="chat-empty">Select a conversation to start chatting</div>
        ) : (
          <>
            <div className="chat-header">
              <button className="chat-back" onClick={() => setActiveId(null)} title="Back to chats" aria-label="Back">←</button>
              <div className="convo-avatar" style={{ width: 38, height: 38, fontSize: 14 }}>
                {(activeConv.contact?.name?.[0] ?? "?").toUpperCase()}
              </div>
              <div>
                <div className="name">{activeConv.contact?.name || activeConv.contact?.phone || "Unknown"}</div>
                <div className="phone">{activeConv.contact?.wa_id ? `+${activeConv.contact.wa_id}` : ""}</div>
              </div>

              <div style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 10 }}>
                {/* Assign / transfer chat */}
                <div style={{ position: "relative" }}>
                  <button className="btn-mini" disabled={assigning} onClick={() => setAssignOpen((v) => !v)} title="Transfer this chat to a teammate">
                    {activeConv.assigned_agent ? `👤 ${activeConv.assigned_agent.name}` : "Assign agent"} ▾
                  </button>
                  {assignOpen && (
                    <div onMouseLeave={() => setAssignOpen(false)}
                      style={{ position: "absolute", right: 0, top: 34, width: 230, background: "var(--card)", border: "1px solid var(--border)", borderRadius: 10, boxShadow: "0 12px 32px rgba(0,0,0,.16)", zIndex: 60, padding: 6 }}>
                      <div style={{ fontSize: 11, color: "var(--muted)", padding: "6px 8px", textTransform: "uppercase", fontWeight: 700 }}>Transfer chat to</div>
                      {assignableAgents.length === 0 && <div className="muted" style={{ fontSize: 13, padding: "6px 8px" }}>No teammates found.</div>}
                      {assignableAgents.map((a) => {
                        const current = activeConv.assigned_agent?.id === a.id;
                        return (
                          <div key={a.id} className="assign-item" onClick={() => !current && transferChat(a.id)}
                            style={{ padding: "8px 10px", borderRadius: 8, cursor: current ? "default" : "pointer", fontSize: 13, display: "flex", justifyContent: "space-between", alignItems: "center", opacity: current ? 0.7 : 1 }}>
                            <span>{a.name}{a.is_me ? " (you)" : ""}</span>
                            {current && <span style={{ color: "#0a7d47", fontSize: 12 }}>✓ current</span>}
                          </div>
                        );
                      })}
                      {activeConv.assigned_agent && (
                        <>
                          <div style={{ height: 1, background: "var(--border)", margin: "4px 0" }} />
                          <div className="assign-item" onClick={() => transferChat(null)} style={{ padding: "8px 10px", borderRadius: 8, cursor: "pointer", fontSize: 13, color: "#c53030" }}>Unassign</div>
                        </>
                      )}
                    </div>
                  )}
                </div>
                <span className={`window-tag ${activeConv.window_open ? "window-open" : "window-closed"}`} style={{ marginLeft: 0 }}>
                  {activeConv.window_open ? "Window open" : "Window closed"}
                </span>
              </div>
            </div>

            {error && <div className="error" style={{ margin: "8px 16px 0", borderRadius: 8 }}>{error}</div>}

            <div className="message-list">
              {msgLoading && messages.length === 0 ? (
                <div style={{ textAlign: "center", color: "var(--muted)", alignSelf: "center" }}>Loading messages...</div>
              ) : messages.length === 0 ? (
                <div style={{ textAlign: "center", color: "var(--muted)", alignSelf: "center" }}>No messages yet</div>
              ) : (
                messages.map((msg) => (
                  <div
                    key={msg.id}
                    className={`bubble-wrap ${msg.direction === "inbound" ? "bubble-wrap-in" : "bubble-wrap-out"}`}
                  >
                    <div className={msg.direction === "inbound" ? "bubble bubble-in" : "bubble bubble-out"}>
                      {/* Action arrow button */}
                      <button
                        className="bubble-action-btn"
                        onClick={(e) => openActionMenu(e, msg)}
                        title="Message options"
                      >
                        ▾
                      </button>
                      {msg.attachments && msg.attachments.length > 0 && isMediaMessage(msg.attachments[0].type) ? (
                        <MediaBubble msg={msg} />
                      ) : (
                        <div style={{ whiteSpace: "pre-wrap", wordBreak: "break-word" }}>{msg.body || `[${msg.type}]`}</div>
                      )}
                      <div className="meta">
                        <span>{formatTime(msg.created_at)}</span>
                        {msg.direction === "outbound" && <StatusIcon status={msg.status} />}
                      </div>
                      {msg.direction === "outbound" && msg.status === "failed" && (
                        <div style={{ marginTop: 4, fontSize: 11.5, color: "#c53030", background: "#fdecec", borderRadius: 6, padding: "4px 8px", lineHeight: 1.4 }}>
                          ⚠ Not delivered — {failureReason(msg.error_code, msg.error_message)}
                        </div>
                      )}
                    </div>
                  </div>
                ))
              )}
            </div>

            {/* Reply bar */}
            {replyTo && (
              <div className="reply-bar">
                <div className="reply-bar-line" />
                <div className="reply-bar-content">
                  <div className="reply-bar-name">{replyTo.direction === "inbound" ? (activeConv.contact?.name || "Contact") : "You"}</div>
                  <div className="reply-bar-text">{replyTo.body || `[${replyTo.type}]`}</div>
                </div>
                <button className="reply-bar-close" onClick={() => setReplyTo(null)}>✕</button>
              </div>
            )}

            {/* Media preview bar */}
            {mediaFile && !recording && (
              <div className="media-preview">
                <div className="media-preview-thumb">
                  {mediaPreview && mediaPreview !== "video" && mediaPreview !== "audio-recording" ? (
                    <img src={mediaPreview} alt="" style={{ width: 60, height: 60, borderRadius: 8, objectFit: "cover" }} />
                  ) : mediaType === "video" ? (
                    "🎬"
                  ) : mediaType === "audio" ? (
                    "🎤"
                  ) : (
                    "📄"
                  )}
                </div>
                <div className="media-preview-info">
                  <div className="media-preview-name">{mediaFile.name}</div>
                  <div className="media-preview-size">{formatFileSize(mediaFile.size)} · {mediaType}</div>
                </div>
                <button className="media-preview-close" onClick={clearMedia}>✕</button>
              </div>
            )}

            {/* Recording bar */}
            {recording && (
              <div className="media-preview" style={{ background: "#fef2f2" }}>
                <div className="recording-dot" />
                <div style={{ flex: 1, fontSize: 14, fontWeight: 500, color: "#dc2626" }}>
                  Recording... {formatDuration(recordingTime)}
                </div>
                <button
                  className="media-preview-close"
                  onClick={cancelRecording}
                  title="Cancel"
                  style={{ background: "rgba(220,38,38,.15)", color: "#dc2626" }}
                >
                  🗑
                </button>
                <button
                  className="media-preview-close"
                  onClick={stopRecording}
                  title="Stop & attach"
                  style={{ background: "#dc2626", color: "#fff", fontSize: 12 }}
                >
                  ◼
                </button>
              </div>
            )}

            {/* Compose bar */}
            <div className="compose-bar" ref={attachMenuRef}>
              <input ref={fileRef} type="file" style={{ display: "none" }} onChange={handleFileSelect} />
              <input ref={cameraRef} type="file" accept="image/*" capture="environment" style={{ display: "none" }} onChange={handleFileSelect} />

              {activeConv.window_open ? (<>
              {/* Attachment button */}
              <button
                className="attach-btn"
                onClick={() => setShowAttach((v) => !v)}
                disabled={!activeConv.window_open || recording}
                title="Attach"
                style={{ transform: showAttach ? "rotate(45deg)" : "none", transition: "transform .2s" }}
              >
                +
              </button>

              {/* Attachment menu */}
              {showAttach && (
                <div className="attach-menu">
                  {ATTACH_OPTIONS.map((opt) => (
                    <div
                      key={opt.key}
                      className={`attach-menu-item ${!opt.enabled ? "disabled" : ""}`}
                      onClick={() => handleAttachClick(opt)}
                      style={!opt.enabled ? { opacity: 0.5 } : undefined}
                    >
                      <div className="attach-menu-icon" style={{ background: opt.color }}>{opt.icon}</div>
                      <span>{opt.label}</span>
                      {!opt.enabled && <span style={{ marginLeft: "auto", fontSize: 10, color: "var(--muted)" }}>Soon</span>}
                    </div>
                  ))}
                </div>
              )}

              <input
                value={mediaFile ? caption : text}
                onChange={(e) => mediaFile ? setCaption(e.target.value) : setText(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder={
                  !activeConv.window_open
                    ? "Window closed — send a template to re-open"
                    : recording
                    ? "Recording..."
                    : mediaFile
                    ? "Add a caption..."
                    : "Type a message..."
                }
                disabled={!activeConv.window_open || recording}
              />

              {/* Send or Mic button */}
              {text.trim() || mediaFile ? (
                <button
                  onClick={mediaFile ? sendMediaMessage : sendMessage}
                  disabled={sending || (!mediaFile && !text.trim()) || !activeConv.window_open}
                >
                  ➤
                </button>
              ) : recording ? (
                <button
                  onClick={stopRecording}
                  style={{ background: "#dc2626" }}
                  title="Stop recording & send"
                >
                  ➤
                </button>
              ) : (
                <button
                  className="mic-btn"
                  onClick={startRecording}
                  disabled={!activeConv.window_open}
                  title="Record voice message"
                >
                  🎤
                </button>
              )}
              </>) : (
                <div style={{ display: "flex", gap: 8, width: "100%", alignItems: "center", flexWrap: "wrap" }}>
                  <span style={{ fontSize: 12, color: "var(--muted)", whiteSpace: "nowrap" }}>🔒 Window closed — reopen with a template:</span>
                  <select
                    value={tplName}
                    onChange={(e) => { const t = templates.find((x) => x.name === e.target.value); setTplName(e.target.value); if (t?.language) setTplLang(t.language); }}
                    style={{ flex: 1, minWidth: 160, padding: "9px 10px", border: "1px solid var(--border)", borderRadius: 8, fontSize: 13, background: "#fff" }}
                  >
                    {templates.length === 0 && <option value="">No approved templates</option>}
                    {templates.map((t) => <option key={t.id} value={t.name}>{t.name} · {t.language}{t.category ? ` · ${t.category.toLowerCase()}` : ""}</option>)}
                  </select>
                  <button
                    onClick={sendReopenTemplate}
                    disabled={sendingTpl || !tplName}
                    style={{ padding: "9px 16px", borderRadius: 8, border: "none", background: "var(--green)", color: "#fff", fontWeight: 600, fontSize: 13, cursor: "pointer", whiteSpace: "nowrap", opacity: sendingTpl || !tplName ? 0.6 : 1 }}
                  >
                    {sendingTpl ? "Sending…" : "Send template"}
                  </button>
                </div>
              )}
            </div>
          </>
        )}
      </div>
      </div>
    </div>
  );
}
