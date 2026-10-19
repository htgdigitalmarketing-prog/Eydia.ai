"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { api, ApiError, SocialConnection, SocialPost } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { useUser } from "@/lib/user-context";
import { PageHeader } from "@/components/PageHeader";
import { LoadingBlock } from "@/components/Preloader";

type FbAuthResponse = { authResponse?: { accessToken?: string } | null; status?: string };
type FbSdk = {
  init: (o: { appId: string; cookie?: boolean; xfbml?: boolean; version: string }) => void;
  login: (cb: (r: FbAuthResponse) => void, o: { scope: string; return_scopes?: boolean }) => void;
};

export default function SocialPage() {
  const me = useUser();
  const perms = me.permissions ?? [];
  const canConnect = perms.includes("whatsapp.manage");
  const canPost = perms.includes("campaigns.create");

  const [connection, setConnection] = useState<SocialConnection | null>(null);
  const [posts, setPosts] = useState<SocialPost[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // connect form
  const [showConnect, setShowConnect] = useState(false);
  const [connForm, setConnForm] = useState({ page_id: "", page_access_token: "" });
  const [connecting, setConnecting] = useState(false);
  const [showManual, setShowManual] = useState(false);

  // Facebook login page-picker
  const [fbAppId, setFbAppId] = useState<string | null>(null);
  const [apiVersion, setApiVersion] = useState("v23.0");
  const [fbBusy, setFbBusy] = useState(false);
  const [pages, setPages] = useState<{ id: string; name: string; access_token: string; ig_user_id: string | null; ig_username: string | null }[]>([]);

  // compose
  const [caption, setCaption] = useState("");
  const [imageUrl, setImageUrl] = useState("");
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [imageMode, setImageMode] = useState<"url" | "upload">("url");
  const [targets, setTargets] = useState<string[]>(["facebook"]);
  const [scheduleOn, setScheduleOn] = useState(false);
  const [scheduledAt, setScheduledAt] = useState("");
  const [posting, setPosting] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const [imgDims, setImgDims] = useState<{ w: number; h: number } | null>(null);

  // Classify the image so we can show whether it's a feed post or a reel/story.
  function aspectInfo(d: { w: number; h: number } | null) {
    if (!d || !d.h) return null;
    const r = d.w / d.h;
    let label: string, hint: string;
    if (r >= 0.95 && r <= 1.05) { label = "Square 1:1"; hint = "Feed post"; }
    else if (r < 0.95) {
      if (r <= 0.62) { label = "Portrait 9:16"; hint = "Reel / Story"; }
      else { label = "Portrait 4:5"; hint = "Feed post"; }
    } else {
      label = "Landscape 1.91:1"; hint = "Feed post";
    }
    return { label, hint, ratio: r };
  }
  const ai = aspectInfo(imgDims);

  // Surface field-level validation messages (e.g. "page_id must be ≤64 chars")
  // instead of the generic "The given data was invalid."
  function errMsg(err: unknown): string {
    const e = err as ApiError;
    if (e?.errors) {
      const flat = Object.values(e.errors).flat();
      if (flat.length) return flat.join(" ");
    }
    return e?.message ?? "Something went wrong.";
  }

  const load = useCallback(async () => {
    const token = getToken();
    if (!token) return;
    setLoading(true);
    try {
      const [c, p] = await Promise.all([
        api.social.connection(token),
        api.social.posts(token).catch(() => ({ posts: [] as SocialPost[] })),
      ]);
      setConnection(c.connection);
      setFbAppId(c.facebook_app_id);
      setApiVersion(c.api_version || "v23.0");
      setPosts(p.posts);
    } catch (err) {
      setError(errMsg(err));
    } finally {
      setLoading(false);
    }
  }, []);

  async function ensureFb(appId: string, version: string): Promise<FbSdk> {
    const w = window as unknown as { FB?: FbSdk };
    if (w.FB) return w.FB;
    await new Promise<void>((resolve, reject) => {
      const s = document.createElement("script");
      s.src = "https://connect.facebook.net/en_US/sdk.js";
      s.async = true; s.defer = true;
      s.onload = () => resolve();
      s.onerror = () => reject(new Error("Could not load the Facebook SDK. Check your connection."));
      document.body.appendChild(s);
    });
    w.FB!.init({ appId, cookie: true, xfbml: false, version });
    return w.FB!;
  }

  async function loginWithFacebook() {
    const token = getToken();
    if (!token) return;
    if (!fbAppId) { setError("Facebook login isn't configured. Use the manual option below."); setShowManual(true); return; }
    setFbBusy(true); setError(null);
    try {
      const FB = await ensureFb(fbAppId, apiVersion);
      const auth = await new Promise<FbAuthResponse>((resolve) =>
        FB.login((r) => resolve(r), {
          scope: "pages_show_list,pages_manage_posts,business_management,instagram_basic,instagram_content_publish",
          return_scopes: true,
        })
      );
      const userToken = auth?.authResponse?.accessToken;
      if (!userToken) {
        // Either the user closed the popup, or (commonly) Facebook showed
        // "this app isn't available / needs a supported permission" because the
        // Meta app isn't live / lacks Advanced Access. Offer the manual path.
        setError("Couldn't finish Facebook login. If Facebook said the app isn’t available, your Meta app needs Facebook Login for Business + approved permissions (Live mode). Meanwhile, use the manual Page ID & token option below.");
        setShowManual(true);
        return;
      }
      const res = await api.social.pages(token, userToken);
      if (!res.pages.length) {
        setError("No Facebook Pages found. Make sure you manage at least one Page and granted permission.");
        return;
      }
      setPages(res.pages);
    } catch (err) {
      setError(errMsg(err));
    } finally {
      setFbBusy(false);
    }
  }

  async function connectPage(p: { id: string; name: string; access_token: string }) {
    const token = getToken();
    if (!token) return;
    setConnecting(true); setError(null);
    try {
      const res = await api.social.connect(token, { page_id: p.id, page_access_token: p.access_token });
      setConnection(res.connection);
      setShowConnect(false); setPages([]);
      flash(`Connected to ${res.connection.page_name ?? p.name}.`);
    } catch (err) {
      setError(errMsg(err));
    } finally {
      setConnecting(false);
    }
  }

  useEffect(() => { load(); }, [load]);

  function flash(msg: string) {
    setNotice(msg);
    setTimeout(() => setNotice(null), 4000);
  }

  async function connect(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    setConnecting(true);
    setError(null);
    try {
      const res = await api.social.connect(token, connForm);
      setConnection(res.connection);
      setShowConnect(false);
      flash(`Connected to ${res.connection.page_name ?? "your Page"}.`);
    } catch (err) {
      setError(errMsg(err));
    } finally {
      setConnecting(false);
    }
  }

  const [rechecking, setRechecking] = useState(false);
  async function recheckInstagram() {
    const token = getToken();
    if (!token) return;
    setRechecking(true);
    setError(null);
    try {
      const res = await api.social.recheckInstagram(token);
      setConnection(res.connection);
      flash(res.message);
    } catch (err) {
      setError(errMsg(err));
    } finally {
      setRechecking(false);
    }
  }

  async function disconnect() {
    const token = getToken();
    if (!token) return;
    if (!confirm("Disconnect this Facebook Page & Instagram account?")) return;
    try {
      await api.social.disconnect(token);
      setConnection(null);
      flash("Disconnected.");
    } catch (err) {
      setError(errMsg(err));
    }
  }

  function toggleTarget(t: string) {
    setTargets((prev) => (prev.includes(t) ? prev.filter((x) => x !== t) : [...prev, t]));
  }

  async function submitPost(e: React.FormEvent) {
    e.preventDefault();
    const token = getToken();
    if (!token) return;
    if (targets.length === 0) { setError("Select at least one destination (Facebook or Instagram)."); return; }
    if (imageMode === "url" && !imageUrl) { setError("Paste a public image URL, or switch to upload."); return; }
    if (imageMode === "upload" && !imageFile) { setError("Choose an image to upload."); return; }
    setPosting(true);
    setError(null);
    try {
      const res = await api.social.createPost(token, {
        caption,
        targets,
        image_url: imageMode === "url" ? imageUrl : undefined,
        image: imageMode === "upload" ? imageFile : undefined,
        scheduled_at: scheduleOn && scheduledAt ? new Date(scheduledAt).toISOString() : undefined,
      });
      const st = res.post.status;
      flash(st === "scheduled" ? "Post scheduled." : st === "published" ? "Published! 🎉" : st === "partial" ? "Published with some errors — see below." : "Post failed — see details below.");
      setCaption(""); setImageUrl(""); setImageFile(null); if (fileRef.current) fileRef.current.value = "";
      setScheduleOn(false); setScheduledAt("");
      await load();
    } catch (err) {
      setError(errMsg(err));
    } finally {
      setPosting(false);
    }
  }

  async function publishNow(p: SocialPost) {
    const token = getToken();
    if (!token) return;
    try {
      const res = await api.social.publishPost(token, p.id);
      setPosts((prev) => prev.map((x) => (x.id === p.id ? res.post : x)));
      flash(res.post.status === "published" ? "Published! 🎉" : "Attempted — see status.");
    } catch (err) {
      setError(errMsg(err));
    }
  }

  async function editPost(p: SocialPost) {
    const token = getToken();
    if (!token) return;
    const next = window.prompt("Edit caption", p.caption ?? "");
    if (next === null) return; // cancelled
    try {
      const res = await api.social.updatePost(token, p.id, { caption: next });
      setPosts((prev) => prev.map((x) => (x.id === p.id ? res.post : x)));
      flash(res.note || "Caption updated.");
    } catch (err) {
      setError(errMsg(err));
    }
  }

  async function deletePost(p: SocialPost) {
    const token = getToken();
    if (!token) return;
    const igDone = (p.results?.instagram?.status) === "published";
    const msg = igDone
      ? "Delete this post?\n\n• Removed from Eydia.ai\n• Deleted from Facebook (if it was posted there)\n• Instagram: cannot be deleted via API — you'll need to remove it in the Instagram app yourself."
      : "Delete this post? It will be removed from Eydia.ai (and from Facebook if it was published there).";
    if (!confirm(msg)) return;
    try {
      const res = await api.social.deletePost(token, p.id);
      setPosts((prev) => prev.filter((x) => x.id !== p.id));
      flash(res.note || "Post deleted.");
    } catch (err) {
      setError(errMsg(err));
    }
  }

  const igLinked = connection?.instagram_linked;
  const [previewUrl, setPreviewUrl] = useState("");

  // Build the preview URL ONCE when the image changes (never in render, or the
  // object URL churns every render and the preview flickers). Revoke on cleanup.
  useEffect(() => {
    setImgDims(null);
    if (imageMode === "upload" && imageFile) {
      const url = URL.createObjectURL(imageFile);
      setPreviewUrl(url);
      return () => URL.revokeObjectURL(url);
    }
    setPreviewUrl(imageMode === "url" ? imageUrl : "");
  }, [imageMode, imageFile, imageUrl]);

  // Is the selected media a video (Reel) rather than a photo?
  const isVideo = imageMode === "upload"
    ? !!imageFile && imageFile.type.startsWith("video")
    : /\.(mp4|mov|m4v|webm)(\?.*)?$/i.test(imageUrl);

  const statusColor = (s: string) => s === "published" ? { bg: "#e7f7ef", fg: "#0a7d47" }
    : s === "scheduled" ? { bg: "#fff3cd", fg: "#856404" }
    : s === "partial" ? { bg: "#fff3e0", fg: "#b45309" }
    : s === "failed" ? { bg: "#fdecec", fg: "#c53030" }
    : { bg: "#eef1f2", fg: "#667781" };

  return (
    <div>
      <PageHeader
        title="Social"
        subtitle="Publish to your Facebook Page and Instagram from Eydia.ai."
        action={connection && canConnect ? <button className="btn-mini" onClick={disconnect}>Disconnect</button> : undefined}
      />

      {error && <div className="error">{error}</div>}
      {notice && <div className="panel" style={{ background: "#e7f7ef", color: "#0a7d47", marginBottom: 16 }}>{notice}</div>}

      {loading ? (
        <LoadingBlock label="Loading…" />
      ) : !connection ? (
        // ---- Not connected ----
        <div className="panel" style={{ textAlign: "center", padding: 40, background: "linear-gradient(135deg,#f5f0ff,#eef7ff)" }}>
          <div style={{ fontSize: 44 }}>📸</div>
          <h2 style={{ margin: "12px 0 6px" }}>Connect Facebook &amp; Instagram</h2>
          <p className="muted" style={{ maxWidth: 460, margin: "0 auto 18px" }}>
            Link your Facebook Page (and its connected Instagram Business account) to publish posts from here.
          </p>
          {canConnect ? (
            <>
              <button className="btn" onClick={() => setShowConnect(true)}>Connect Facebook &amp; Instagram</button>
              <p className="muted" style={{ fontSize: 12, marginTop: 12, maxWidth: 440, marginLeft: "auto", marginRight: "auto" }}>
                ℹ️ Instagram is connected automatically through its linked Facebook Page — that&apos;s how Meta&apos;s publishing API works. Make sure your Instagram is a <b>Business/Creator</b> account linked to your Page.
              </p>
            </>
          ) : (
            <p className="muted">Ask your workspace admin to connect a Facebook Page.</p>
          )}
        </div>
      ) : (
        <>
          {/* Connected banner */}
          <div className="panel" style={{ display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap" }}>
            <div style={{ fontSize: 30 }}>✅</div>
            <div style={{ flex: 1, minWidth: 200 }}>
              <div style={{ fontWeight: 700 }}>{connection.page_name ?? "Facebook Page"}</div>
              <div className="muted" style={{ fontSize: 13 }}>
                Facebook Page connected
                {igLinked ? ` · Instagram @${connection.ig_username}` : " · No Instagram linked"}
              </div>
            </div>
            <div style={{ display: "flex", gap: 6, alignItems: "center" }}>
              <span style={{ fontSize: 12, fontWeight: 600, background: "#e7f0ff", color: "#1877f2", padding: "4px 10px", borderRadius: 999 }}>f Facebook</span>
              <span style={{ fontSize: 12, fontWeight: 600, background: igLinked ? "#fdeef5" : "#eef1f2", color: igLinked ? "#c13584" : "#98a2ab", padding: "4px 10px", borderRadius: 999 }}>◙ Instagram</span>
              {canConnect && !igLinked && (
                <button className="btn-mini" disabled={rechecking} onClick={recheckInstagram} title="Re-detect the Instagram Business account linked to this Page">
                  {rechecking ? "Checking…" : "Re-check Instagram"}
                </button>
              )}
            </div>
          </div>

          {/* Compose */}
          {canPost && (
            <div className="panel">
              <h2 style={{ marginTop: 0 }}>New post</h2>
              <form onSubmit={submitPost}>
                <div className="grid" style={{ gridTemplateColumns: "1.2fr 1fr", gap: 18, alignItems: "start" }}>
                  <div>
                    <div className="field">
                      <label>Caption</label>
                      <textarea value={caption} onChange={(e) => setCaption(e.target.value)} rows={5} placeholder="Write a caption… #hashtags welcome" />
                    </div>

                    <div className="field">
                      <label>Photo or video (Reel)</label>
                      <div className="filter-tabs" style={{ marginBottom: 10 }}>
                        <button type="button" className={imageMode === "url" ? "active" : ""} onClick={() => setImageMode("url")}>Paste URL</button>
                        <button type="button" className={imageMode === "upload" ? "active" : ""} onClick={() => setImageMode("upload")}>Upload</button>
                      </div>
                      {imageMode === "url" ? (
                        <input key="url-input" value={imageUrl ?? ""} onChange={(e) => setImageUrl(e.target.value)} placeholder="https://example.com/photo.jpg or video.mp4" />
                      ) : (
                        <input key="file-input" ref={fileRef} type="file" accept="image/*,video/mp4,video/quicktime" onChange={(e) => setImageFile(e.target.files?.[0] ?? null)} />
                      )}
                      <span className="muted" style={{ fontSize: 12 }}>
                        Photo (JPG/PNG) or video (MP4/MOV). A video posts as a <b>Reel</b> on Instagram.
                        {imageMode === "upload"
                          ? " ⚠️ Upload only works on the live site — Instagram can't fetch files from localhost. On localhost use Paste URL with a public link."
                          : " Use a direct public link that opens as just the image/video (not a web page)."}
                      </span>
                    </div>

                    <div className="field">
                      <label>Publish to</label>
                      <div style={{ display: "flex", gap: 14, paddingTop: 4 }}>
                        <label style={{ display: "flex", alignItems: "center", gap: 6, cursor: "pointer", fontSize: 14 }}>
                          <input type="checkbox" checked={targets.includes("facebook")} onChange={() => toggleTarget("facebook")} />
                          <span style={{ color: "#1877f2", fontWeight: 600 }}>Facebook</span>
                        </label>
                        <label style={{ display: "flex", alignItems: "center", gap: 6, cursor: igLinked ? "pointer" : "not-allowed", fontSize: 14, opacity: igLinked ? 1 : 0.5 }}>
                          <input type="checkbox" disabled={!igLinked} checked={targets.includes("instagram")} onChange={() => toggleTarget("instagram")} />
                          <span style={{ color: "#c13584", fontWeight: 600 }}>Instagram</span>
                          {!igLinked && <span className="muted" style={{ fontSize: 11 }}>(not linked)</span>}
                        </label>
                      </div>
                    </div>

                    <div className="field">
                      <label style={{ display: "flex", alignItems: "center", gap: 8 }}>
                        <input type="checkbox" checked={scheduleOn} onChange={(e) => setScheduleOn(e.target.checked)} />
                        <span>Schedule for later</span>
                      </label>
                      {scheduleOn && (
                        <input type="datetime-local" value={scheduledAt} onChange={(e) => setScheduledAt(e.target.value)} style={{ marginTop: 8 }} />
                      )}
                    </div>

                    {previewUrl && (
                      <div style={{
                        padding: "10px 12px", borderRadius: 10, marginBottom: 12, fontSize: 13, fontWeight: 600,
                        background: isVideo ? "#f3e8ff" : "#e7f7ef", color: isVideo ? "#7c3aed" : "#0a7d47",
                      }}>
                        {isVideo
                          ? "🎬 This will be published as a REEL on Instagram (and a video on Facebook)."
                          : "📸 This will be published as a FEED POST — a photo."}
                      </div>
                    )}

                    <button className="btn" disabled={posting}>
                      {posting ? "Working…" : scheduleOn ? "Schedule post" : isVideo ? "Publish Reel now" : "Publish now"}
                    </button>
                  </div>

                  {/* Live preview */}
                  <div style={{ position: "sticky", top: 12 }}>
                    <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 8 }}>
                      <label className="muted" style={{ fontSize: 13, fontWeight: 600, margin: 0 }}>Preview</label>
                      {(ai || isVideo) && (
                        <span style={{ fontSize: 11, fontWeight: 700, background: isVideo ? "#f3e8ff" : "#e7f7ef", color: isVideo ? "#7c3aed" : "#0a7d47", padding: "2px 9px", borderRadius: 999 }}>
                          {isVideo ? "🎬 Reel / Video" : `${ai!.hint} · ${ai!.label}`}
                        </span>
                      )}
                    </div>
                    <div style={{ maxWidth: 320, margin: "0 auto", borderRadius: 14, overflow: "hidden", boxShadow: "0 6px 22px rgba(0,0,0,.08)", background: "#fff", border: "1px solid var(--border)" }}>
                      {/* Media frame — matches the image's own shape so the whole photo shows, uncropped. */}
                      <div style={{
                        width: "100%",
                        aspectRatio: imgDims ? `${imgDims.w} / ${imgDims.h}` : "1 / 1",
                        maxHeight: 420,
                        background: "#0b141a",
                        display: "flex", alignItems: "center", justifyContent: "center",
                      }}>
                        {previewUrl && isVideo ? (
                          <video
                            src={previewUrl}
                            controls
                            playsInline
                            onLoadedMetadata={(e) => setImgDims({ w: e.currentTarget.videoWidth, h: e.currentTarget.videoHeight })}
                            onError={() => setImgDims(null)}
                            style={{ width: "100%", height: "100%", objectFit: "contain", display: "block", background: "#000" }}
                          />
                        ) : previewUrl ? (
                          // eslint-disable-next-line @next/next/no-img-element
                          <img
                            src={previewUrl}
                            alt="preview"
                            onLoad={(e) => setImgDims({ w: e.currentTarget.naturalWidth, h: e.currentTarget.naturalHeight })}
                            onError={() => setImgDims(null)}
                            style={{ width: "100%", height: "100%", objectFit: "contain", display: "block" }}
                          />
                        ) : (
                          <span style={{ color: "#5b6b73", fontSize: 13 }}>🖼️ Photo / video preview</span>
                        )}
                      </div>
                      <div style={{ padding: 12, fontSize: 13, color: "#3b4a54", whiteSpace: "pre-wrap", wordBreak: "break-word" }}>{caption || <span className="muted">Your caption appears here…</span>}</div>
                    </div>
                    {imgDims && (
                      <div className="muted" style={{ fontSize: 12, textAlign: "center", marginTop: 8 }}>
                        {imgDims.w}×{imgDims.h}px
                        {ai && ai.hint === "Reel / Story" && " · tall image — best as a Reel/Story"}
                      </div>
                    )}
                  </div>
                </div>
              </form>
            </div>
          )}

          {/* History */}
          <div className="panel">
            <h2 style={{ marginTop: 0 }}>Posts</h2>
            {posts.length === 0 ? (
              <p className="muted">No posts yet.</p>
            ) : (
              <div style={{ display: "grid", gap: 12 }}>
                {posts.map((p) => {
                  const sc = statusColor(p.status);
                  return (
                    <div key={p.id} style={{ display: "flex", gap: 12, border: "1px solid var(--border)", borderRadius: 12, padding: 12 }}>
                      <div style={{ width: 64, height: 64, borderRadius: 10, background: "#0b141a", flex: "none", overflow: "hidden", display: "flex", alignItems: "center", justifyContent: "center", color: "#fff", fontSize: 22 }}>
                        {p.media_type === "video" ? (
                          "🎬"
                        ) : p.image_url ? (
                          /* eslint-disable-next-line @next/next/no-img-element */
                          <img src={p.image_url} alt="" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                        ) : null}
                      </div>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 4, flexWrap: "wrap" }}>
                          <span style={{ background: sc.bg, color: sc.fg, padding: "2px 9px", borderRadius: 999, fontSize: 11, fontWeight: 700, textTransform: "capitalize" }}>{p.status}</span>
                          {p.targets.map((t) => <span key={t} style={{ fontSize: 11, color: "#54656f" }}>{t === "facebook" ? "f FB" : "◙ IG"}</span>)}
                          {p.scheduled_at && p.status === "scheduled" && <span className="muted" style={{ fontSize: 12 }}>for {new Date(p.scheduled_at).toLocaleString("en-IN")}</span>}
                        </div>
                        <div style={{ fontSize: 13, color: "#3b4a54", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{p.caption || <span className="muted">No caption</span>}</div>
                        {p.results && (
                          <div style={{ fontSize: 12, marginTop: 4 }}>
                            {Object.entries(p.results).map(([plat, r]) => (
                              <span key={plat} style={{ marginRight: 12, color: r.status === "published" ? "#0a7d47" : "#c53030" }}>
                                {plat === "facebook" ? "Facebook" : "Instagram"}: {r.status === "published" ? "✓ posted" : `✗ ${r.error ?? "failed"}`}
                              </span>
                            ))}
                          </div>
                        )}
                      </div>
                      {canPost && (
                        <div style={{ display: "flex", flexDirection: "column", gap: 6, alignSelf: "center" }}>
                          {(p.status === "scheduled" || p.status === "failed" || p.status === "partial") && (
                            <button className="btn-mini" onClick={() => publishNow(p)}>Publish now</button>
                          )}
                          <button className="btn-mini" onClick={() => editPost(p)}>Edit</button>
                          <button className="btn-mini" style={{ color: "#c53030" }} onClick={() => deletePost(p)}>Delete</button>
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </>
      )}

      {/* Connect modal */}
      {showConnect && (
        <div className="msg-info-overlay" onClick={() => { setShowConnect(false); setPages([]); }}>
          <div className="msg-info-panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 480 }}>
            <h2 style={{ marginTop: 0 }}>Connect Facebook &amp; Instagram</h2>

            {pages.length > 0 ? (
              <>
                <p className="muted" style={{ fontSize: 13, marginTop: 0 }}>Choose the Page you want to connect:</p>
                <div style={{ display: "grid", gap: 8, maxHeight: 340, overflowY: "auto" }}>
                  {pages.map((p) => (
                    <div key={p.id} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10, border: "1px solid var(--border)", borderRadius: 10, padding: "10px 12px" }}>
                      <div>
                        <div style={{ fontWeight: 600 }}>{p.name}</div>
                        <div className="muted" style={{ fontSize: 12 }}>
                          {p.ig_username ? `Instagram: @${p.ig_username}` : "No Instagram linked"}
                        </div>
                      </div>
                      <button className="btn" style={{ width: "auto", padding: "7px 16px" }} disabled={connecting} onClick={() => connectPage(p)}>
                        {connecting ? "…" : "Connect"}
                      </button>
                    </div>
                  ))}
                </div>
                <button type="button" className="btn-mini" style={{ marginTop: 12 }} onClick={() => setPages([])}>Back</button>
              </>
            ) : (
              <>
                <p className="muted" style={{ fontSize: 13, marginTop: 0 }}>
                  Log in with Facebook and pick the Page you want to publish from. We&apos;ll auto-detect its linked Instagram account.
                </p>
                <div style={{ fontSize: 12, background: "#fff8e6", border: "1px solid #f3e2b3", color: "#8a6d1b", borderRadius: 8, padding: "8px 10px", marginBottom: 10 }}>
                  ⚠️ If Facebook says <b>“this app isn’t available”</b>, the Meta app needs <b>Facebook Login for Business</b> added, the required permissions with <b>Advanced Access</b>, and the app in <b>Live</b> mode. Until then, use the manual Page ID &amp; token option below.
                </div>
                <button
                  className="btn"
                  style={{ background: "#1877f2", display: "flex", alignItems: "center", justifyContent: "center", gap: 8 }}
                  disabled={fbBusy}
                  onClick={loginWithFacebook}
                >
                  <span style={{ fontWeight: 800 }}>f</span> {fbBusy ? "Opening Facebook…" : "Continue with Facebook"}
                </button>

                <button type="button" className="btn-ghost" style={{ marginTop: 12, fontSize: 13 }} onClick={() => setShowManual((v) => !v)}>
                  {showManual ? "Hide manual option" : "Advanced: enter Page ID & token manually"}
                </button>

                {showManual && (
                  <form onSubmit={connect} style={{ marginTop: 10, borderTop: "1px solid var(--border)", paddingTop: 10 }}>
                    <div className="field">
                      <label>Facebook Page ID</label>
                      <input value={connForm.page_id} onChange={(e) => setConnForm((f) => ({ ...f, page_id: e.target.value }))} required placeholder="1234567890" />
                    </div>
                    <div className="field">
                      <label>Page access token</label>
                      <input value={connForm.page_access_token} onChange={(e) => setConnForm((f) => ({ ...f, page_access_token: e.target.value }))} required placeholder="EAAG…" />
                    </div>
                    <button className="btn" disabled={connecting}>{connecting ? "Verifying…" : "Connect"}</button>
                  </form>
                )}
              </>
            )}

            <button type="button" className="btn-mini" style={{ marginTop: 12 }} onClick={() => { setShowConnect(false); setPages([]); }}>Cancel</button>
          </div>
        </div>
      )}
    </div>
  );
}
