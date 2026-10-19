"use client";

import { useEffect } from "react";

/**
 * Error boundary for the authenticated app section. Without this, any client
 * exception renders Next's blank "Application error" screen with no detail.
 * Here we surface the real message so failures are diagnosable on any device.
 */
export default function AppError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    // Log for anyone with the console open on a failing device.
    console.error("App error boundary caught:", error);
  }, [error]);

  return (
    <div style={{ maxWidth: 520, margin: "60px auto", padding: 24, textAlign: "center" }}>
      <div style={{ fontSize: 44 }}>⚠️</div>
      <h2 style={{ margin: "12px 0 6px" }}>Something went wrong</h2>
      <p className="muted" style={{ marginBottom: 16 }}>
        The page hit an unexpected error. You can try again, or reload.
      </p>
      {process.env.NODE_ENV === "development" && (
        <pre style={{
          textAlign: "left", background: "#fdecec", color: "#8a1f1f", padding: "10px 12px",
          borderRadius: 8, fontSize: 12, whiteSpace: "pre-wrap", wordBreak: "break-word", marginBottom: 16,
        }}>
          {error?.message || "Unknown error"}{error?.digest ? `\n\nDigest: ${error.digest}` : ""}
        </pre>
      )}
      <div style={{ display: "flex", gap: 8, justifyContent: "center" }}>
        <button className="btn" onClick={() => reset()}>Try again</button>
        <button className="btn-mini" onClick={() => location.reload()}>Reload page</button>
      </div>
    </div>
  );
}
